<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAreaRequest;
use App\Models\LoanPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Areas of the library: name, acronym and lending rule (Library page, Categories tab). */
class AreaController extends Controller
{
    public function index()
    {
        $areas = LoanPeriod::query()->withCount('books')->orderBy('sortOrder')->orderBy('name')->get();

        return response()->json(['areas' => $areas->map(fn (LoanPeriod $a) => $this->present($a))->values()]);
    }

    /** The books shelved in this area (what a card shows when it is pressed, and what stops it being deleted). */
    public function books(Request $request, LoanPeriod $area)
    {
        $search = trim((string) $request->query('search', ''));

        $page = $area->books()->with('authors')->withCount('copies')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('isbn', 'like', "%{$search}%")))
            ->orderBy('title')->paginate(12);

        return response()->json([
            'total'     => $page->total(),
            'last_page' => $page->lastPage(),
            'items' => $page->getCollection()->map(fn ($b) => [
                'id'     => $b->uuid,
                'title'  => $b->title,
                'detail' => $b->authors->pluck('name')->join(', ') ?: 'No author listed',
                'meta'   => trim(($b->callNumber ?: '').' · '.$b->copies_count.($b->copies_count === 1 ? ' copy' : ' copies'), ' ·'),
            ])->values(),
        ]);
    }

    public function store(SaveAreaRequest $request)
    {
        $data = $request->validated();

        $area = LoanPeriod::create([
            'area'        => $this->freeKey(Str::slug($data['name']) ?: 'area'),
            'uuid'        => (string) Str::uuid(),
            'name'        => trim($data['name']),
            'acronym'     => strtoupper(trim($data['acronym'])),
            'loanable'    => $data['loanable'],
            'periodValue' => $data['loanable'] ? $data['periodValue'] : null,
            'periodUnit'  => $data['loanable'] ? $data['periodUnit'] : null,
            'sortOrder'   => (int) LoanPeriod::max('sortOrder') + 1,
        ]);

        return response()->json(['message' => 'Area added.', 'area' => $this->present($area->loadCount('books'))], 201);
    }

    public function update(SaveAreaRequest $request, LoanPeriod $area)
    {
        $data = $request->validated();

        $area->update([
            'name'        => trim($data['name']),
            'acronym'     => strtoupper(trim($data['acronym'])),
            'loanable'    => $data['loanable'],
            'periodValue' => $data['loanable'] ? $data['periodValue'] : null,
            'periodUnit'  => $data['loanable'] ? $data['periodUnit'] : null,
        ]);

        return response()->json(['message' => 'Area updated.', 'area' => $this->present($area->loadCount('books'))]);
    }

    /** The areas Settings (loan periods, fine rules) and new books lean on by name: they can be edited, never deleted. */
    public const CORE = ['circulation', 'reserved', 'filipiniana'];

    public function destroy(LoanPeriod $area)
    {
        if (in_array($area->area, self::CORE, true)) {
            throw ValidationException::withMessages(['area' => ["{$area->name} is one of the areas the system relies on (loan periods, fines and new books), so it can't be deleted. You can rename it or change how it is lent out."]]);
        }

        // Past loans and fine rules are recorded against the area too.
        if (\Illuminate\Support\Facades\DB::table('loans')->where('loanType', $area->area)->exists()
            || \App\Models\PenaltyType::where('areaOfLibrary', $area->area)->exists()) {
            throw ValidationException::withMessages(['area' => ["{$area->name} has loans or fine rules recorded against it, so it can't be deleted."]]);
        }

        if ($area->books()->exists()) {
            throw ValidationException::withMessages(['area' => ['Cannot delete: books are still shelved in this area.']]);
        }

        $area->delete();

        return response()->json(['message' => 'Area removed.']);
    }

    private function present(LoanPeriod $area): array
    {
        return [
            'uuid'        => $area->uuid,
            'key'         => $area->area,
            'name'        => $area->name,
            'acronym'     => $area->acronym,
            'loanable'    => $area->loanable,
            'periodValue' => $area->periodValue,
            'periodUnit'  => $area->periodUnit,
            'books_count' => (int) ($area->books_count ?? 0),
            // Settings and new books lean on these by name, so they can't be deleted.
            'locked'      => in_array($area->area, self::CORE, true),
        ];
    }

    private function freeKey(string $base): string
    {
        $key = $base;
        for ($n = 2; LoanPeriod::whereKey($key)->exists(); $n++) {
            $key = "{$base}-{$n}";
        }

        return $key;
    }
}
