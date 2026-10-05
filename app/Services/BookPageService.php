<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos of a book's reference pages: its table of contents, appendix, bibliography, index and "about the
 * author". Librarians add them from the Add/Edit Book window; librarians and students see them under
 * "Look inside". The browser resizes photos before upload (the server has no image library), so files
 * are stored as received, on the public disk under book-pages/{bookID}/.
 */
class BookPageService
{
    /** A table of contents or an index can run long; this keeps a section from turning into a scan of the book. */
    public const MAX_PER_SECTION = 20;

    private const DISK = 'public';

    /**
     * Every page photo of a book, in book order: by section, then position.
     *
     * @return array<int, array{pageID: int, section: string, position: int, url: string}>
     */
    public function forBook(Book $book): array
    {
        return BookPage::where('bookID', $book->bookID)
            ->orderByRaw('FIELD(section, ' . implode(', ', array_fill(0, count(BookPage::SECTIONS), '?')) . ')', BookPage::SECTIONS)
            ->orderBy('position')
            ->get()
            ->map(fn (BookPage $page) => $this->present($page))
            ->all();
    }

    /**
     * Adds photos to the end of a section, in the order given.
     *
     * @param  UploadedFile[]  $photos
     * @return array<int, array{pageID: int, section: string, position: int, url: string}>
     */
    public function add(Book $book, string $section, array $photos): array
    {
        return DB::transaction(function () use ($book, $section, $photos) {
            $existing = BookPage::where('bookID', $book->bookID)->where('section', $section)->lockForUpdate()->get();

            if ($existing->count() + count($photos) > self::MAX_PER_SECTION) {
                throw ValidationException::withMessages([
                    'photos' => ['A section can hold up to ' . self::MAX_PER_SECTION . ' photos.'],
                ]);
            }

            $position = (int) $existing->max('position');

            return array_map(function (UploadedFile $photo) use ($book, $section, &$position) {
                $uuid = (string) Str::uuid();
                $path = $photo->storeAs("book-pages/{$book->bookID}", $uuid . '.' . $this->extension($photo), self::DISK);

                return $this->present(BookPage::create([
                    'uuid'     => $uuid,
                    'bookID'   => $book->bookID,
                    'section'  => $section,
                    'position' => ++$position,
                    'path'     => $path,
                ]));
            }, $photos);
        });
    }

    /** Puts a section's photos in the given order. IDs that aren't that section's photos are refused. */
    public function reorder(Book $book, string $section, array $pageIDs): void
    {
        $pages = BookPage::where('bookID', $book->bookID)->where('section', $section)->get()->keyBy('pageID');

        if (count($pageIDs) !== $pages->count() || collect($pageIDs)->diff($pages->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pageIDs' => ["Send every photo in this section, once each."],
            ]);
        }

        DB::transaction(function () use ($pageIDs, $pages) {
            foreach (array_values($pageIDs) as $index => $pageID) {
                $pages[$pageID]->update(['position' => $index + 1]);
            }
        });
    }

    public function remove(BookPage $page): void
    {
        Storage::disk(self::DISK)->delete($page->path);
        $page->delete();
    }

    private function present(BookPage $page): array
    {
        return [
            'pageID'   => $page->pageID,
            'uuid'     => $page->uuid,
            'section'  => $page->section,
            'position' => $page->position,
            // Built from the request's own address, so the link works whatever APP_URL says.
            'url'      => asset('storage/' . $page->path),
        ];
    }

    private function extension(UploadedFile $photo): string
    {
        return match ($photo->getMimeType()) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
