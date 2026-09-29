<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookPage;
use App\Services\BookPageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Page photos (table of contents, appendix, bibliography, index, about the author) for a book. */
class BookPageController extends Controller
{
    public function __construct(private BookPageService $pages)
    {
    }

    public function store(Request $request, Book $book)
    {
        $validated = $request->validate([
            'section'  => ['required', Rule::in(BookPage::SECTIONS)],
            'photos'   => ['required', 'array', 'min:1', 'max:' . BookPageService::MAX_PER_SECTION],
            // The browser shrinks photos to well under this before sending them.
            'photos.*' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        return response()->json([
            'message' => count($validated['photos']) === 1 ? 'Photo added.' : 'Photos added.',
            'pages'   => $this->pages->add($book, $validated['section'], $request->file('photos')),
        ], 201);
    }

    public function reorder(Request $request, Book $book)
    {
        $validated = $request->validate([
            'section'   => ['required', Rule::in(BookPage::SECTIONS)],
            'pageIDs'   => ['present', 'array'],
            'pageIDs.*' => ['integer'],
        ]);

        $this->pages->reorder($book, $validated['section'], $validated['pageIDs']);

        return response()->json(['message' => 'Order saved.']);
    }

    public function destroy(BookPage $page)
    {
        $this->pages->remove($page);

        return response()->json(['message' => 'Photo removed.']);
    }
}
