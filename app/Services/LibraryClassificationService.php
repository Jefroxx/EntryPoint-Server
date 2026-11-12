<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds the Dewey Decimal number (and LC Cutter) other libraries already gave a book, by ISBN, so a new title
 * gets a real classification instead of a guess.
 *
 * Two free sources, tried in this order:
 *   1. Library of Congress catalogue (SRU, MARC 082 = Dewey, 050 = LC call number). In a test on 15 well known
 *      ISBNs it had a Dewey number for 11; Open Library had 5.
 *   2. Open Library, for what the first one doesn't have.
 *
 * Every public method is best-effort: disabled config, a network failure, a timeout, or a miss all resolve to
 * null rather than throwing, so a flaky third party never blocks adding a book. Results (and misses) are
 * cached per ISBN, since neither service publishes usage limits and the answer rarely changes.
 */
class LibraryClassificationService
{
    public const SOURCE_LOC = 'library_of_congress';
    public const SOURCE_OPEN_LIBRARY = 'open_library';

    /** A well formed Dewey number: three digits, then an optional decimal part ("005.1", "813.54"). */
    private const DEWEY_PATTERN = '/^\d{3}(\.\d{1,10})?$/';

    /**
     * What happened to each catalogue on the last {@see lookupByIsbn()} call: 'found', 'none' (answered, but has no
     * record) or 'unreachable' (timed out or errored). Empty when the answer came from the cache. A caller that has to
     * tell "nobody has it" from "a catalogue didn't answer" reads this straight after the lookup.
     *
     * @var array<string, string>
     */
    private array $status = [];

    /** @return array<string, string> */
    public function lastStatus(): array
    {
        return $this->status;
    }

    /**
     * @param bool $skipLibraryOfCongress the caller just found that server unreachable: don't wait for it a second time
     * @return array{deweyClass: ?string, cutterCandidates: string[], publishYear: ?int, source: ?string}|null
     */
    public function lookupByIsbn(string $isbn, bool $skipLibraryOfCongress = false): ?array
    {
        $this->status = [];
        $isbn = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';

        if ($isbn === '') {
            return null;
        }

        $key = 'classification:isbn:' . strtoupper($isbn);

        if (Cache::has($key)) {
            // A miss that was only remembered because a catalogue didn't answer is still "couldn't ask", not "nobody has it".
            if (Cache::has($key . ':down')) {
                $this->status[self::SOURCE_LOC] = 'unreachable';
            }

            return Cache::get($key) ?: null;
        }

        if ($skipLibraryOfCongress) {
            $this->status[self::SOURCE_LOC] = 'unreachable';
            $result = null;
        } else {
            $result = $this->fromLibraryOfCongress($isbn);
        }

        // Open Library fills in what the Library of Congress lacks (a number, or the Cutter).
        if (! $result || ! $result['deweyClass']) {
            $fallback = $this->fromOpenLibrary($isbn);
            if ($fallback && (! $result || $fallback['deweyClass'])) {
                $result = $fallback;
            }
        }

        // A hit is kept for 90 days; a miss only for a day, since catalogues add records over time. A miss where a
        // catalogue didn't answer proves nothing, so it is only remembered for a few minutes (enough not to hammer a
        // server that is down, short enough that the next try asks again).
        $failed = in_array('unreachable', $this->status, true);
        $found = $result && ! empty($result['deweyClass']);
        Cache::put($key, $result ?? false, $found ? now()->addDays(90) : ($failed ? now()->addMinutes(5) : now()->addDay()));

        if ($failed && ! $found) {
            Cache::put($key . ':down', true, now()->addMinutes(5));
        } else {
            Cache::forget($key . ':down');
        }

        return $result;
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
     * Turns whatever a catalogue wrote into a plain Dewey number, or null if there isn't a usable one.
     * "823/.7" and "005.1/2" are written with slashes marking where the number may be shortened, so they are
     * removed ("823.7", "005.12"); a whole call number in the field ("813.54 V66c 2006") is cut back to its number.
     */
    public static function normalizeDewey(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim(str_replace('/', '', $raw));

        if (! preg_match('/^\d{3}(?:\.\d+)?/', $raw, $m)) {
            return null;
        }

        return preg_match(self::DEWEY_PATTERN, $m[0]) ? $m[0] : null;
    }

    public static function isValidDewey(?string $value): bool
    {
        return $value !== null && preg_match(self::DEWEY_PATTERN, $value) === 1;
    }

    /**
     * @return array{deweyClass: ?string, cutterCandidates: string[], publishYear: ?int, source: string}|null
     */
    private function fromLibraryOfCongress(string $isbn): ?array
    {
        if (! config('classification.library_of_congress.enabled', true)) {
            return null;
        }

        // The first address that answers wins; the next is only asked when the one before it didn't (see config).
        $response = null;
        foreach (array_unique(array_filter([
            config('classification.library_of_congress.base_url'),
            config('classification.library_of_congress.fallback_url'),
        ])) as $url) {
            try {
                $candidate = Http::timeout(config('classification.library_of_congress.timeout', 8))
                    ->connectTimeout(config('classification.library_of_congress.connect_timeout', 4))
                    ->get($url, [
                        'version'        => '1.1',
                        'operation'      => 'searchRetrieve',
                        'query'          => "bath.isbn={$isbn}",
                        'maximumRecords' => 1,
                        'recordSchema'   => 'marcxml',
                    ]);
            } catch (Throwable $e) {
                Log::warning("Library of Congress lookup at {$url} failed for ISBN {$isbn}: {$e->getMessage()}");

                continue;
            }

            if ($candidate->ok()) {
                $response = $candidate;

                break;
            }
        }

        if (! $response) {
            $this->status[self::SOURCE_LOC] = 'unreachable';

            return null;
        }

        $xml = @simplexml_load_string($response->body());

        // (A parsed document with only namespaced children is "falsy" in PHP, so compare with false.)
        if ($xml === false) {
            $this->status[self::SOURCE_LOC] = 'unreachable';

            return null;
        }

        $xml->registerXPathNamespace('m', 'http://www.loc.gov/MARC21/slim');

        $dewey = $xml->xpath('//m:datafield[@tag="082"]/m:subfield[@code="a"]');
        $lcc = $xml->xpath('//m:datafield[@tag="050"]');
        $year = $xml->xpath('//m:controlfield[@tag="008"]');

        if (! $dewey && ! $lcc) {
            $this->status[self::SOURCE_LOC] = 'none';

            return null;
        }

        $this->status[self::SOURCE_LOC] = 'found';

        $lccStrings = [];
        foreach ($lcc ?: [] as $field) {
            $field->registerXPathNamespace('m', 'http://www.loc.gov/MARC21/slim');
            $parts = [];
            foreach ($field->xpath('m:subfield[@code="a" or @code="b"]') ?: [] as $sub) {
                $parts[] = (string) $sub;
            }
            $lccStrings[] = implode(' ', $parts);
        }

        return [
            'deweyClass'       => $dewey ? self::normalizeDewey((string) $dewey[0]) : null,
            'cutterCandidates' => $this->extractCutters($lccStrings),
            // The 008 control field is "yymmdd" (when the record was made), a one letter date type, then the year of publication.
            'publishYear'      => $year && preg_match('/^\d{6}[a-z|]\s?(\d{4})/i', (string) $year[0], $m) ? (int) $m[1] : null,
            'source'           => self::SOURCE_LOC,
        ];
    }

    /**
     * @return array{deweyClass: ?string, cutterCandidates: string[], publishYear: ?int, source: string}|null
     */
    private function fromOpenLibrary(string $isbn): ?array
    {
        if (! config('classification.open_library.enabled', true)) {
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
            $this->status[self::SOURCE_OPEN_LIBRARY] = 'unreachable';

            return null;
        }

        if (! $response->ok()) {
            $this->status[self::SOURCE_OPEN_LIBRARY] = 'unreachable';

            return null;
        }

        $record = $response->json("ISBN:{$isbn}");

        if (! $record) {
            $this->status[self::SOURCE_OPEN_LIBRARY] = 'none';

            return null;
        }

        $this->status[self::SOURCE_OPEN_LIBRARY] = 'found';

        $classifications = $record['classifications'] ?? [];

        return [
            'deweyClass'       => self::normalizeDewey($classifications['dewey_decimal_class'][0] ?? null),
            'cutterCandidates' => $this->extractCutters($classifications['lc_classifications'] ?? []),
            'publishYear'      => $this->extractYear($record['publish_date'] ?? null),
            'source'           => self::SOURCE_OPEN_LIBRARY,
        ];
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
