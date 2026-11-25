<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFundSourceRequest;
use App\Models\BookCopy;
use App\Models\FundSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Sources of funds: the choices in the "Source of fund" box (Library page, Categories tab). */
class FundSourceController extends Controller
{
    public function index()
    {
        $counts = BookCopy::query()->whereNotNull('sourceOfFund')->selectRaw('sourceOfFund, count(*) as total')->groupBy('sourceOfFund')->pluck('total', 'sourceOfFund');

        return response()->json([
            'sources' => FundSource::orderBy('sortOrder')->orderBy('name')->get()->map(fn (FundSource $s) => $this->present($s, $counts))->values(),
        ]);
    }

    /** The copies that came from this source (what a card shows when it is pressed, and what stops it being deleted). */
    public function copies(Request $request, FundSource $fundSource)
    {
        $search = trim((string) $request->query('search', ''));

        $page = BookCopy::where('sourceOfFund', $fundSource->name)->with('book')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('accessionNumber', 'like', "%{$search}%")
                ->orWhere('barcodeValue', 'like', "%{$search}%")
                ->orWhereHas('book', fn ($b) => $b->where('title', 'like', "%{$search}%"))))
            ->orderBy('accessionNumber')->paginate(12);

        return response()->json([
            'total'     => $page->total(),
            'last_page' => $page->lastPage(),
            'items' => $page->getCollection()->map(fn ($c) => [
                'id'     => $c->uuid,
                'badge'  => (string) $c->accessionNumber,
                'title'  => $c->book?->title ?? 'Unknown book',
                'detail' => $c->barcodeValue ?: 'No barcode',
                'meta'   => ucfirst((string) $c->status).($c->cost !== null ? ' · ₱'.number_format((float) $c->cost, 2) : ''),
            ])->values(),
        ]);
    }

    public function store(SaveFundSourceRequest $request)
    {
        $source = FundSource::create([
            'uuid'      => (string) Str::uuid(),
            'name'      => trim($request->validated()['name']),
            'sortOrder' => (int) FundSource::max('sortOrder') + 1,
        ]);

        return response()->json(['message' => 'Source of fund added.', 'source' => $this->present($source, collect())], 201);
    }

    public function update(SaveFundSourceRequest $request, FundSource $fundSource)
    {
        $new = trim($request->validated()['name']);
        $old = $fundSource->name;

        // "Donated" drives the cost-0 rule, so it keeps its name.
        if ($this->isDonated($old) && ! $this->isDonated($new)) {
            throw ValidationException::withMessages(['name' => ['"Donated" cannot be renamed: copies from it cost nothing.']]);
        }

        // Copies that came from it follow the new name, so none is left pointing at a name that no longer exists.
        DB::transaction(function () use ($fundSource, $new, $old) {
            $fundSource->update(['name' => $new]);
            BookCopy::where('sourceOfFund', $old)->update(['sourceOfFund' => $new]);
        });

        return response()->json(['message' => 'Source of fund updated.', 'source' => $this->present($fundSource->fresh(), collect())]);
    }

    public function destroy(FundSource $fundSource)
    {
        if ($this->isDonated($fundSource->name)) {
            throw ValidationException::withMessages(['source' => ['"Donated" cannot be deleted: copies from it cost nothing.']]);
        }

        // Copies still recorded as coming from it keep it on the list.
        if (BookCopy::where('sourceOfFund', $fundSource->name)->exists()) {
            throw ValidationException::withMessages(['source' => ['Cannot delete: copies are still recorded as coming from this source.']]);
        }

        $fundSource->delete();

        return response()->json(['message' => 'Source of fund removed.']);
    }

    private function isDonated(string $name): bool
    {
        return \App\Services\FundSourceService::isDonated($name);
    }

    private function present(FundSource $source, $counts): array
    {
        return [
            'uuid'         => $source->uuid,
            'name'         => $source->name,
            'locked'       => $this->isDonated($source->name),
            'copies_count' => (int) ($counts[$source->name] ?? 0),
        ];
    }
}
