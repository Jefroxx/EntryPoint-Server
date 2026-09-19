<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls already-published Dewey Decimal / LC Cutter data for a book from the
 * free, keyless Open Library Books API, so newly catalogued titles reuse the
 * real classification other libraries already assigned instead of guessing.
 *
 * Every public method is best-effort: disabled config, a network failure, a
 * timeout, or a miss all resolve to null/empty rather than throwing, so a
 * flaky or unreachable third party never blocks adding a book.
 */
class LibraryClassificationService
{
    /**
     * @return array{deweyClass: ?string, cutterCandidates: string[], publishYear: ?int}|null
     */
    public function lookupByIsbn(string $isbn): ?array
    {
        if (! config('classification.open_library.enabled', true)) {
            return null;
        }

        $isbn = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';

        if ($isbn === '') {
            return null;
        }

        try {
            $response = Http::timeout(config('classification.open_library.timeout', 3))
                ->connectTimeout(config('classification.open_library.connect_timeout', 2))
                ->get(rtrim(config('classification.open_library.base_url'), '/') . '/api/books', [
                    'bibkeys' => "ISBN:{$isbn}",
                    'jscmd'   => 'data',
                    'format'  => 'json',
                ]);
        } catch (Throwable $e) {
            Log::warning("Open Library lookup failed for ISBN {$isbn}: {$e->getMessage()}");

            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $record = $response->json("ISBN:{$isbn}");

        if (! $record) {
            return null;
        }

        $classifications = $record['classifications'] ?? [];

        return [
            'deweyClass'       => $classifications['dewey_decimal_class'][0] ?? null,
            'cutterCandidates' => $this->extractCutters($classifications['lc_classifications'] ?? []),
            'publishYear'      => $this->extractYear($record['publish_date'] ?? null),
        ];
    }

    /**
     * The API often returns more than one LC classification for a title
     * (one filed under the author, one under the title/editor) — pick the
     * candidate whose letter matches the given surname's first letter.
     *
     * @param string[] $cutterCandidates
     */
    public function matchCutterForSurname(array $cutterCandidates, string $surname): ?string
    {
        $initial = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $surname) ?? '', 0, 1));

        if ($initial === '') {
            return null;
        }

        foreach ($cutterCandidates as $candidate) {
            if (str_starts_with($candidate, $initial)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractCutters(array $lcClassifications): array
    {
        $cutters = [];

        foreach ($lcClassifications as $lcc) {
            if (preg_match('/\.\s*([A-Za-z]\d{2,4})/', (string) $lcc, $matches)) {
                $cutters[] = strtoupper($matches[1]);
            }
        }

        return array_values(array_unique($cutters));
    }

    private function extractYear(?string $publishDate): ?int
    {
        if ($publishDate && preg_match('/\d{4}/', $publishDate, $matches)) {
            return (int) $matches[0];
        }

        return null;
    }
}
