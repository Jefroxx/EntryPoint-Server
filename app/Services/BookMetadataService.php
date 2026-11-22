<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What other catalogues already know about a book, by ISBN, so the Add Book form can be filled in from a scan
 * instead of typed: title, authors, publisher, year, pages, edition, volume, a description and a cover link, plus
 * the Dewey number.
 *
 * Three free sources are asked at the same time (so the slowest one sets the wait, not their sum) and merged field
 * by field, the first one with an answer winning, in this order:
 *   1. Library of Congress (MARC records: careful, cataloguer-checked data, but no covers),
 *   2. Open Library (covers, and many titles the Library of Congress does not hold),
 *   3. Google Books (the widest net, and descriptions).
 *
 * Like {@see LibraryClassificationService}, every source is best-effort: a timeout, an outage or a miss only
 * removes that source's answer, never fails the request. What changed is that the outcome is no longer thrown away:
 * each source ends as 'found', 'none' (it answered and has no record), 'unreachable' (it did not answer) or 'off'
 * (switched off in config), so the screen can say "the Library of Congress didn't answer" instead of "not found".
 * A source that did not answer is never remembered as a miss for long (see {@see self::fetchSources()}).
 */
class BookMetadataService
{
    private const MARC = 'http://www.loc.gov/MARC21/slim';

    /** The short names used inside this class, and the names the rest of the app (and the screen) uses. */
    public const SOURCES = ['loc' => 'library_of_congress', 'open' => 'open_library', 'google' => 'google_books'];

    private const SOURCE_LABELS = ['loc' => 'The Library of Congress', 'open' => 'Open Library', 'google' => 'Google Books'];

    public function __construct(private LibraryClassificationService $classification)
    {
    }

    /**
     * Digits (and a trailing X) only; null unless that is a real ISBN-10 or ISBN-13 with a correct check digit.
     * An ISBN-10 is returned as the ISBN-13 it converts to, which is what catalogues and barcodes use.
     */
    public static function normalizeIsbn(?string $raw): ?string
    {
        $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $raw) ?? '');

        if (strlen($digits) === 13 && ctype_digit($digits)) {
            return self::isbn13CheckDigit(substr($digits, 0, 12)) === (int) $digits[12] ? $digits : null;
        }

        if (strlen($digits) === 10 && preg_match('/^\d{9}[\dX]$/', $digits)) {
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $sum += ($digits[$i] === 'X' ? 10 : (int) $digits[$i]) * (10 - $i);
            }

            if ($sum % 11 !== 0) {
                return null;
            }

            $core = '978' . substr($digits, 0, 9);

            return $core . self::isbn13CheckDigit($core);
        }

        return null;
    }

    /**
     * Every form one book's ISBN can be stored in, hyphens and spaces left out: the ISBN-13, and the ISBN-10 it was
     * made from (978-prefixed ones only). Empty when the ISBN isn't valid.
     *
     * @return string[]
     */
    public static function isbnVariants(?string $raw): array
    {
        $isbn13 = self::normalizeIsbn($raw);

        if ($isbn13 === null) {
            return [];
        }

        $variants = [$isbn13];

        if (str_starts_with($isbn13, '978')) {
            $core = substr($isbn13, 3, 9);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += (int) $core[$i] * (10 - $i);
            }
            $check = (11 - $sum % 11) % 11;
            $variants[] = $core . ($check === 10 ? 'X' : (string) $check);
        }

        return $variants;
    }

    private static function isbn13CheckDigit(string $first12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @return array{isbn: string, title: ?string, authors: string[], publisher: ?string, publishYear: ?int, pageCount: ?int,
     *               edition: ?string, volume: ?string, hints: string[],
     *               description: ?string, coverUrl: ?string, deweyNumber: ?string, deweySource: ?string, sources: string[]}|null
     *         null when the ISBN is not valid, or no catalogue knows the book
     */
    public function lookupByIsbn(string $isbn): ?array
    {
        return $this->lookup($isbn)['book'];
    }

    /**
     * The same lookup with what happened to each catalogue alongside the book, for the Add Book screen.
     *
     * @return array{book: ?array, sourceStatus: array<string, string>, deweyStatus: string}
     */
    public function lookup(string $isbn): array
    {
        $isbn = self::normalizeIsbn($isbn);

        if ($isbn === null) {
            return ['book' => null, 'sourceStatus' => [], 'deweyStatus' => 'none'];
        }

        $key = $this->mergedKey($isbn);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $found = [];
        $status = [];

        foreach ($this->fetchSources($isbn, array_keys(self::SOURCES)) as $short => $result) {
            $status[self::SOURCES[$short]] = $result['status'];

            if ($result['fields']) {
                $found[$short] = $result['fields'];
            }
        }

        $merged = $this->merge($isbn, $found);

        // No catalogue record had a cover, but Open Library often holds one under the ISBN itself.
        if ($merged && ! $merged['coverUrl'] && ($cover = $this->openLibraryCover($isbn))) {
            $merged['coverUrl'] = $cover;
            if (! in_array('open_library', $merged['sources'], true)) {
                $merged['sources'][] = 'open_library';
            }
        }

        // The Dewey number has its own (cached) lookup, so it is not asked twice. When the Library of Congress has just
        // failed to answer, that lookup doesn't wait for it a second time.
        $locDown = ($status['library_of_congress'] ?? null) === 'unreachable';
        $classification = $this->classification->lookupByIsbn($isbn, $locDown);
        $deweyFound = $classification && ! empty($classification['deweyClass']);

        if ($merged && $deweyFound) {
            $merged['deweyNumber'] = $classification['deweyClass'];
            $merged['deweySource'] = $classification['source'] ?? null;
        }

        $deweyDown = $locDown || in_array('unreachable', $this->classification->lastStatus(), true);
        $deweyStatus = $deweyFound ? 'found' : ($deweyDown ? 'unreachable' : 'none');

        $out = ['book' => $merged, 'sourceStatus' => $status, 'deweyStatus' => $deweyStatus];

        // A hit is kept for 30 days (a cover or a title can be corrected upstream); a miss for a day, since catalogues
        // add records over time. When a catalogue did not answer the result proves little, so it only lasts a minute.
        $failed = in_array('unreachable', $status, true) || $deweyStatus === 'unreachable';
        Cache::put($key, $out, $failed ? now()->addMinute() : ($merged ? now()->addDays(30) : now()->addDay()));

        return $out;
    }

    /**
     * Asks ONE catalogue about an ISBN and says how it went, for the progress list on the Add Book screen (which asks
     * the three one by one so each can be shown as it finishes). The answer is remembered, so the lookup that follows
     * to put the details together does not ask again.
     *
     * @param string $source one of the values of {@see self::SOURCES}
     * @param bool   $retry  ask again even if this catalogue failed to answer a moment ago
     * @return array{source: string, status: string, ms: int, cached: bool, title: ?string}
     */
    public function lookupSource(string $isbn, string $source, bool $retry = false): array
    {
        $short = array_search($source, self::SOURCES, true);

        if ($short === false) {
            return ['source' => $source, 'status' => 'off', 'ms' => 0, 'cached' => false, 'title' => null];
        }

        $isbn = self::normalizeIsbn($isbn);

        if ($isbn === null) {
            return ['source' => $source, 'status' => 'none', 'ms' => 0, 'cached' => false, 'title' => null];
        }

        $result = $this->fetchSources($isbn, [$short], $retry)[$short];

        return [
            'source' => $source,
            'status' => $result['status'],
            'ms'     => $result['ms'],
            'cached' => $result['cached'],
            'title'  => $result['fields']['title'] ?? null,
        ];
    }

    private function mergedKey(string $isbn): string
    {
        return 'book-metadata:v2:isbn:' . $isbn;
    }

    private function sourceKey(string $short, string $isbn): string
    {
        return "book-source:v2:{$short}:{$isbn}";
    }

    /**
     * Asks the given catalogues (all at once) and works out how each one went. What each answered is remembered per
     * catalogue: a record for 30 days, "no record" for a day, and "didn't answer" for only a minute, which is enough
     * that putting the details together right after a failed ask doesn't wait for the same dead server again, and
     * short enough that trying again soon really asks again.
     *
     * @param string[] $shorts keys of {@see self::SOURCES}
     * @return array<string, array{status: string, fields: ?array, ms: int, cached: bool}>
     */
    private function fetchSources(string $isbn, array $shorts, bool $retry = false): array
    {
        $out = [];
        $todo = [];

        foreach ($shorts as $short) {
            $cached = Cache::get($this->sourceKey($short, $isbn));

            if (is_array($cached) && ! ($retry && $cached['status'] === 'unreachable')) {
                $out[$short] = $cached + ['ms' => 0, 'cached' => true];

                continue;
            }

            $todo[] = $short;
        }

        if (! $todo) {
            return $out;
        }

        $config = [
            'loc'    => config('classification.library_of_congress'),
            'open'   => config('classification.open_library'),
            'google' => config('classification.google_books'),
        ];

        $asking = array_values(array_filter($todo, fn ($short) => $config[$short]['enabled'] ?? true));

        foreach (array_diff($todo, $asking) as $short) {
            $out[$short] = ['status' => 'off', 'fields' => null, 'ms' => 0, 'cached' => false];
        }

        if (! $asking) {
            return $out;
        }

        // Anything asked afresh may change the book the screen is about to be shown, so an older put-together answer goes.
        Cache::forget($this->mergedKey($isbn));

        $started = microtime(true);
        $responses = [];

        try {
            $responses = Http::pool(function (Pool $pool) use ($isbn, $asking, $config) {
                $calls = [];

                foreach ($asking as $short) {
                    $c = $config[$short];

                    if ($short === 'loc') {
                        $calls[] = $pool->as('loc')->timeout($c['timeout'])->connectTimeout($c['connect_timeout'])
                            ->get($c['base_url'], [
                                'version'        => '1.1',
                                'operation'      => 'searchRetrieve',
                                'query'          => "bath.isbn={$isbn}",
                                'maximumRecords' => 1,
                                'recordSchema'   => 'marcxml',
                            ]);
                    } elseif ($short === 'open') {
                        $url = rtrim($c['base_url'], '/') . '/api/books';
                        $calls[] = $pool->as('open')->timeout($c['timeout'])->connectTimeout($c['connect_timeout'])
                            ->get($url, ['bibkeys' => "ISBN:{$isbn}", 'jscmd' => 'data', 'format' => 'json']);
                        // The "details" view is the one that carries the edition's own name ("Rev.", "3rd ed.").
                        $calls[] = $pool->as('open_details')->timeout($c['timeout'])->connectTimeout($c['connect_timeout'])
                            ->get($url, ['bibkeys' => "ISBN:{$isbn}", 'jscmd' => 'details', 'format' => 'json']);
                    } else {
                        $calls[] = $pool->as('google')->timeout($c['timeout'])->connectTimeout($c['connect_timeout'])
                            ->get(rtrim($c['base_url'], '/') . '/volumes', array_filter([
                                'q'          => "isbn:{$isbn}",
                                'maxResults' => 1,
                                'key'        => $c['key'] ?? null,
                            ]));
                    }
                }

                return $calls;
            });
        } catch (Throwable $e) {
            Log::warning("Book details lookup failed for ISBN {$isbn}: {$e->getMessage()}");
        }

        $parsers = ['loc' => 'fromLibraryOfCongress', 'open' => 'fromOpenLibrary', 'google' => 'fromGoogleBooks'];

        foreach ($asking as $short) {
            $response = $responses[$short] ?? null;
            $fields = null;

            // The Library of Congress has a second address: when the first did not answer, the second is asked (one
            // after the other, so it only costs time on the days the first one is down).
            if ($short === 'loc' && (! $response || $response instanceof Throwable || ! $response->ok())) {
                $response = $this->libraryOfCongressFallback($isbn, $config['loc']) ?? $response;
            }

            $ms = (int) round((microtime(true) - $started) * 1000);

            // A source that could not be reached comes back as an exception object instead of a response.
            if (! $response || $response instanceof Throwable || ! $response->ok()) {
                $status = 'unreachable';

                if ($response instanceof Throwable && ! $response instanceof ConnectionException) {
                    Log::warning("The {$short} lookup for ISBN {$isbn} failed: {$response->getMessage()}");
                }
            } else {
                try {
                    $fields = $this->{$parsers[$short]}($response, $isbn, $responses['open_details'] ?? null);
                    $status = $fields ? 'found' : 'none';
                } catch (Throwable $e) {
                    Log::warning("Could not read the {$short} record for ISBN {$isbn}: {$e->getMessage()}");
                    $status = 'unreachable';
                }
            }

            $entry = ['status' => $status, 'fields' => $fields];
            Cache::put(
                $this->sourceKey($short, $isbn),
                $entry,
                match ($status) {
                    'found'  => now()->addDays(30),
                    'none'   => now()->addDay(),
                    default  => now()->addMinute(),
                },
            );

            $out[$short] = $entry + ['ms' => $ms, 'cached' => false];
        }

        return $out;
    }

    /**
     * The Library of Congress's second address, asked after the first one failed. Null when there is none, it is the same
     * as the first, or it didn't answer either.
     */
    private function libraryOfCongressFallback(string $isbn, array $config)
    {
        $url = $config['fallback_url'] ?? null;

        if (! $url || $url === ($config['base_url'] ?? null)) {
            return null;
        }

        try {
            $response = Http::timeout($config['timeout'])->connectTimeout($config['connect_timeout'])->get($url, [
                'version'        => '1.1',
                'operation'      => 'searchRetrieve',
                'query'          => "bath.isbn={$isbn}",
                'maximumRecords' => 1,
                'recordSchema'   => 'marcxml',
            ]);
        } catch (Throwable $e) {
            Log::warning("Library of Congress lookup at {$url} failed for ISBN {$isbn}: {$e->getMessage()}");

            return null;
        }

        return $response->ok() ? $response : null;
    }

    /**
     * The first source (in trust order) with a value wins each field. Null when no source gave a title or an author:
     * a record with neither isn't worth filling a form from.
     *
     * @param array<string, array<string, mixed>> $found
     */
    private function merge(string $isbn, array $found): ?array
    {
        $result = [
            'isbn' => $isbn, 'title' => null, 'authors' => [], 'publisher' => null, 'publishYear' => null,
            'pageCount' => null, 'edition' => null, 'volume' => null, 'hints' => [],
            'description' => null, 'coverUrl' => null, 'deweyNumber' => null, 'deweySource' => null, 'sources' => [],
        ];

        // Open Library and Google Books are open to anyone and sometimes hold a junk or wrong record for an ISBN (a
        // spam page filed under a textbook's number). The first title found is the anchor: a later source whose title
        // has no word in common with it is describing some other book, so none of its fields are used.
        $anchor = null;
        $set = null;

        foreach (['loc', 'open', 'google'] as $source) {
            $fields = $found[$source] ?? null;

            if (! $fields) {
                continue;
            }

            if (! empty($fields['title'])) {
                if ($anchor !== null && ! $this->titlesAgree($anchor, $fields['title'])) {
                    continue;
                }
                $anchor ??= $fields['title'];
            }

            $used = false;
            foreach (['title', 'publisher', 'publishYear', 'pageCount', 'edition', 'volume', 'description', 'coverUrl'] as $field) {
                if ($result[$field] === null && ! empty($fields[$field])) {
                    $result[$field] = $fields[$field];
                    $used = true;
                }
            }

            if (! $result['authors'] && ! empty($fields['authors'])) {
                $result['authors'] = $fields['authors'];
                $used = true;
            }

            // A catalogue that says "7 v." is describing a whole set. The ISBN does not say which volume is on the desk.
            if ($set === null && ! empty($fields['setVolumes'])) {
                $set = ['count' => (int) $fields['setVolumes'], 'source' => self::SOURCE_LABELS[$source]];
            }

            if ($used) {
                $result['sources'][] = self::SOURCES[$source];
            }
        }

        if ($set !== null && $result['volume'] === null) {
            $result['hints'][] = "{$set['source']} lists this ISBN as a set of {$set['count']} volumes, so the volume can't be read from it. "
                . 'Enter which volume this is (or N/A for the whole set). The page count may also be for the whole set.';
        }

        return $result['title'] === null && ! $result['authors'] ? null : $result;
    }

    /**
     * Open Library's cover for an ISBN, only when it really has one: with `default=false` it answers 404 instead of
     * a blank placeholder image, so a book without a cover never ends up with a broken or empty picture.
     */
    private function openLibraryCover(string $isbn): ?string
    {
        $config = config('classification.open_library');

        if (! ($config['enabled'] ?? true)) {
            return null;
        }

        $url = rtrim($config['covers_url'], '/') . "/b/isbn/{$isbn}-L.jpg";

        try {
            $response = Http::timeout($config['timeout'])->connectTimeout($config['connect_timeout'])->head($url . '?default=false');
        } catch (Throwable $e) {
            Log::warning("Open Library cover check failed for ISBN {$isbn}: {$e->getMessage()}");

            return null;
        }

        return $response->successful() ? $url : null;
    }

    /** Two titles of one book share at least one real word ("Introduction to algorithms" / "Introduction to Algorithms, Third Edition"). */
    private function titlesAgree(string $a, string $b): bool
    {
        $words = fn (string $title) => array_values(array_diff(
            array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) ?: [], fn ($w) => mb_strlen($w) >= 3),
            ['the', 'and', 'for', 'with', 'from', 'into', 'edition', 'volume'],
        ));

        $left = $words($a);
        $right = $words($b);

        // A title made only of short words ("It", "Us") can't be compared; give it the benefit of the doubt.
        return ! $left || ! $right || (bool) array_intersect($left, $right);
    }

    /** Library of Congress: MARC 245 title (and $n part number), 100/700 authors, 250 edition, 264/260 publisher and year, 300 pages, 520 summary. */
    private function fromLibraryOfCongress($response, string $isbn, $details = null): ?array
    {
        $xml = @simplexml_load_string($response->body());

        // (A parsed document with only namespaced children is "falsy" in PHP, so compare with false.)
        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('m', self::MARC);

        $title = $this->subfields($xml, '245', ['a', 'b']);
        $authors = array_merge(
            $this->subfieldList($xml, '100', 'a'),
            $this->subfieldList($xml, '110', 'a'),
            $this->subfieldList($xml, '700', 'a'),
        );

        if (! $title && ! $authors) {
            return null;
        }

        $publisher = $this->subfields($xml, '264', ['b']) ?: $this->subfields($xml, '260', ['b']);
        $dateText = $this->subfields($xml, '264', ['c']) ?: $this->subfields($xml, '260', ['c']);
        $pages = $this->subfields($xml, '300', ['a']);
        $year = $xml->xpath('//m:controlfield[@tag="008"]');

        return [
            'title'       => $this->cleanTitle($title),
            'authors'     => $this->cleanAuthors($authors),
            'publisher'   => $this->cleanText($publisher),
            'publishYear' => $this->year($dateText) ?? ($year && preg_match('/^\d{6}[a-z|]\s?(\d{4})/i', (string) $year[0], $m) ? (int) $m[1] : null),
            'pageCount'   => $pages && preg_match('/(\d{1,5})\s*(?:p\b|pages|pp\b|leaves)/i', $pages, $m) ? (int) $m[1] : null,
            // 250 is the edition statement ("3rd ed."); 245 $n is the part or volume number ("v. 2").
            'edition'     => $this->normalizeEdition($this->subfields($xml, '250', ['a'])) ?? $this->editionFromText($title),
            'volume'      => $this->normalizeVolume($this->subfields($xml, '245', ['n'])) ?? $this->volumeFromText($title),
            'setVolumes'  => $this->setVolumes($pages),
            'description' => $this->cleanText($this->subfields($xml, '520', ['a'])),
            'coverUrl'    => null,
        ];
    }

    /** Open Library's "data" record: it has covers, and many editions the Library of Congress does not. */
    private function fromOpenLibrary($response, string $isbn, $details = null): ?array
    {
        $record = $response->json("ISBN:{$isbn}");

        if (! is_array($record)) {
            return null;
        }

        $title = trim((string) ($record['title'] ?? ''));
        if ($title !== '' && ! empty($record['subtitle'])) {
            $title .= ': ' . trim((string) $record['subtitle']);
        }

        $authors = array_map(fn ($a) => (string) ($a['name'] ?? ''), $record['authors'] ?? []);

        if ($title === '' && ! array_filter($authors)) {
            return null;
        }

        // The "details" view of the same record (a second request, made at the same time) holds the edition's own
        // name and sometimes a page count the "data" view leaves out. Its absence only means those two stay empty.
        $edition = null;
        $detailPages = null;
        if ($details && ! ($details instanceof Throwable) && $details->ok()) {
            $detail = $details->json("ISBN:{$isbn}.details");
            if (is_array($detail)) {
                $edition = $this->normalizeEdition(isset($detail['edition_name']) ? (string) $detail['edition_name'] : null);
                $detailPages = isset($detail['number_of_pages']) && (int) $detail['number_of_pages'] > 0 ? (int) $detail['number_of_pages'] : null;
            }
        }

        // The credit line ("edited by ... Vol. 2") is read for a volume; the free-text notes are not, since they
        // mention other volumes in passing ("Vol. 6 cover title: Biography").
        $credit = trim($title . ' ' . (string) ($record['by_statement'] ?? ''));

        return [
            'title'       => $this->cleanTitle($title),
            'authors'     => $this->cleanAuthors($authors),
            'publisher'   => $this->cleanText($record['publishers'][0]['name'] ?? null),
            'publishYear' => $this->year($record['publish_date'] ?? null),
            'pageCount'   => isset($record['number_of_pages']) && (int) $record['number_of_pages'] > 0 ? (int) $record['number_of_pages'] : $detailPages,
            'edition'     => $edition ?? $this->editionFromText($title),
            'volume'      => $this->volumeFromText($credit),
            'setVolumes'  => $this->setVolumes(isset($record['pagination']) ? (string) $record['pagination'] : null),
            'description' => null,
            'coverUrl'    => $this->httpsUrl($record['cover']['large'] ?? $record['cover']['medium'] ?? null),
        ];
    }

    /** Google Books' volume record: the widest coverage, and the only one that usually has a description. */
    private function fromGoogleBooks($response, string $isbn, $details = null): ?array
    {
        $info = $response->json('items.0.volumeInfo');

        if (! is_array($info)) {
            return null;
        }

        $title = trim((string) ($info['title'] ?? ''));
        if ($title !== '' && ! empty($info['subtitle'])) {
            $title .= ': ' . trim((string) $info['subtitle']);
        }

        $authors = array_map('strval', $info['authors'] ?? []);

        if ($title === '' && ! array_filter($authors)) {
            return null;
        }

        // Google's thumbnails come as http links with a page-curl effect; ask for the plain image over https.
        $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
        if ($cover) {
            $cover = str_replace(['&edge=curl', '&edge=curl&'], ['', '&'], $cover);
        }

        return [
            'title'       => $this->cleanTitle($title),
            'authors'     => $this->cleanAuthors($authors),
            'publisher'   => $this->cleanText($info['publisher'] ?? null),
            'publishYear' => $this->year($info['publishedDate'] ?? null),
            'pageCount'   => isset($info['pageCount']) && (int) $info['pageCount'] > 0 ? (int) $info['pageCount'] : null,
            // Google has no edition or volume field; at most the title says "Third Edition" or "Vol. 2".
            'edition'     => $this->editionFromText($title),
            'volume'      => $this->volumeFromText($title),
            'setVolumes'  => null,
            'description' => $this->cleanText($info['description'] ?? null),
            'coverUrl'    => $this->httpsUrl($cover),
        ];
    }

    private function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10]);

        return $n . $suffix;
    }

    /**
     * "3rd ed.", "Third Edition" and "Revised edition" in a title or credit line, as a short "3rd ed." / "Revised ed.".
     * Only an explicit statement counts; a number or a word on its own is never taken for an edition.
     */
    private function editionFromText(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10];

        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+(?:ed\b\.?|edition)/i', $text, $m) && (int) $m[1] > 0) {
            return $this->ordinal((int) $m[1]) . ' ed.';
        }

        if (preg_match('/\b(' . implode('|', array_keys($words)) . ')\s+(?:ed\b\.?|edition)/i', $text, $m)) {
            return $this->ordinal($words[strtolower($m[1])]) . ' ed.';
        }

        if (preg_match('/\b(revised|updated|international|abridged|expanded|student|new)\s+(?:ed\b\.?|edition)/i', $text, $m)) {
            return ucfirst(strtolower($m[1])) . ' ed.';
        }

        return null;
    }

    /** A catalogue's own edition statement ("3rd edition.", "Rev.", "Second ed."), tidied; kept as written when it is not a standard form. */
    private function normalizeEdition(?string $text): ?string
    {
        $text = $this->cleanText($text);

        if ($text === null) {
            return null;
        }

        return $this->editionFromText($text) ?? (mb_strlen($text) <= 50 ? $text : null);
    }

    /** "Vol. 2" from "Vol. 2" / "Volume 2" / "v. 2" / "2"; null for anything that isn't clearly one volume (a range, a name). */
    private function normalizeVolume(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $text = trim($text, " \t.,;:/[]()");

        if (preg_match('/^(?:v(?:ol(?:ume)?)?\.?\s*)?(\d{1,3}|(?-i:[IVXL]{1,6}))$/i', $text, $m)) {
            return 'Vol. ' . strtoupper($m[1]);
        }

        if (preg_match('/^(?:pt\.?|part)\s*(\d{1,3}|(?-i:[IVXL]{1,6}))$/i', $text, $m)) {
            return 'Part ' . strtoupper($m[1]);
        }

        return null;
    }

    /** "Vol. 2" / "Volume 2" written out in a title or credit line. "Vols. 1-3" is not one volume, so it doesn't match. */
    private function volumeFromText(?string $text): ?string
    {
        if ($text && preg_match('/\bvol(?:ume)?\.?\s*(\d{1,3}|(?-i:[IVXL]{1,6}))\b/i', $text, $m)) {
            return 'Vol. ' . strtoupper($m[1]);
        }

        return null;
    }

    /** "7 v. :" (MARC 300 / Open Library "pagination") means the record is a whole set of 7 volumes. */
    private function setVolumes(?string $text): ?int
    {
        if ($text && preg_match('/^\s*(\d{1,3})\s*(?:v\b\.?|vols?\b\.?|volumes)/i', $text, $m) && (int) $m[1] > 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** The subfields (in the order given) of the first data field with this tag, joined by spaces. */
    private function subfields(\SimpleXMLElement $xml, string $tag, array $codes): ?string
    {
        $field = $xml->xpath("//m:datafield[@tag=\"{$tag}\"]");

        if (! $field) {
            return null;
        }

        $field[0]->registerXPathNamespace('m', self::MARC);
        $parts = [];

        foreach ($codes as $code) {
            foreach ($field[0]->xpath("m:subfield[@code=\"{$code}\"]") ?: [] as $sub) {
                $parts[] = trim((string) $sub);
            }
        }

        $text = trim(implode(' ', array_filter($parts)));

        return $text === '' ? null : $text;
    }

    /** One value per data field with this tag (an author per 700, say). @return string[] */
    private function subfieldList(\SimpleXMLElement $xml, string $tag, string $code): array
    {
        $out = [];

        foreach ($xml->xpath("//m:datafield[@tag=\"{$tag}\"]/m:subfield[@code=\"{$code}\"]") ?: [] as $sub) {
            $out[] = trim((string) $sub);
        }

        return array_values(array_filter($out));
    }

    /** "Introduction to algorithms /" -> "Introduction to algorithms". Trailing catalogue punctuation goes. */
    private function cleanTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        $title = rtrim($title, " \t/:;,=");
        // MARC writes "Title : subtitle /": keep a readable "Title: subtitle".
        $title = preg_replace('/\s+:\s+/', ': ', $title) ?? $title;

        return $title === '' ? null : $title;
    }

    /**
     * Catalogues write "Cormen, Thomas H., 1956-"; the form wants "Thomas H. Cormen". Dates and trailing punctuation
     * are dropped, a "Surname, Given" name is turned round, and duplicates (the same author in two fields) are removed.
     *
     * @param string[] $names
     * @return string[]
     */
    private function cleanAuthors(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
            // Life dates and role words: "Smith, John, 1950-", "Smith, John, editor".
            $name = preg_replace('/,?\s*\(?\d{4}\s*-\s*(\d{4})?\)?\.?$/', '', $name) ?? $name;
            $name = preg_replace('/,\s*(editor|ed\.|author|translator|illustrator|joint author|compiler)\.?$/i', '', $name) ?? $name;
            $name = rtrim($name, " ,;");

            if (substr_count($name, ',') === 1) {
                [$surname, $given] = array_map('trim', explode(',', $name));
                if ($surname !== '' && $given !== '') {
                    $name = "{$given} {$surname}";
                }
            }

            // A trailing full stop is catalogue punctuation, except after an initial ("Thomas H.").
            if (str_ends_with($name, '.') && ! preg_match('/(^|\s)\p{L}\.$/u', $name)) {
                $name = rtrim($name, '.');
            }

            if ($name !== '' && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $name;
            }
        }

        return array_slice($clean, 0, 6);
    }

    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(strip_tags(preg_replace('/\s+/', ' ', $text) ?? ''));
        $text = rtrim($text, " ,;:/");

        return $text === '' ? null : $text;
    }

    private function year(?string $text): ?int
    {
        // A catalogue may list a forthcoming book under next year; the form doesn't accept a year in the future.
        if ($text && preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $text, $m) && (int) $m[1] <= now()->year) {
            return (int) $m[1];
        }

        return null;
    }

    private function httpsUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = preg_replace('/^http:\/\//i', 'https://', trim($url)) ?? $url;

        return filter_var($url, FILTER_VALIDATE_URL) && strlen($url) <= 255 ? $url : null;
    }
}
