<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What other catalogues already know about a book, by ISBN, so the Add Book form can be filled in from a scan
 * instead of typed: title, authors, publisher, year, pages, a description and a cover link, plus the Dewey number.
 *
 * Three free sources are asked at the same time (so the slowest one sets the wait, not their sum) and merged field
 * by field, the first one with an answer winning, in this order:
 *   1. Library of Congress (MARC records: careful, cataloguer-checked data, but no covers),
 *   2. Open Library (covers, and many titles the Library of Congress does not hold),
 *   3. Google Books (the widest net, and descriptions).
 *
 * Like {@see LibraryClassificationService}, every source is best-effort: a timeout, an outage or a miss only
 * removes that source's answer, never fails the request. Results (and misses) are cached per ISBN.
 */
class BookMetadataService
{
    private const MARC = 'http://www.loc.gov/MARC21/slim';

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
     *               description: ?string, coverUrl: ?string, deweyNumber: ?string, deweySource: ?string, sources: string[]}|null
     *         null when the ISBN is not valid, or no catalogue knows the book
     */
    public function lookupByIsbn(string $isbn): ?array
    {
        $isbn = self::normalizeIsbn($isbn);

        if ($isbn === null) {
            return null;
        }

        $key = 'book-metadata:isbn:' . $isbn;

        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        $found = $this->fetchAll($isbn);

        $merged = $this->merge($isbn, $found);

        // No catalogue record had a cover, but Open Library often holds one under the ISBN itself.
        if ($merged && ! $merged['coverUrl'] && ($cover = $this->openLibraryCover($isbn))) {
            $merged['coverUrl'] = $cover;
            if (! in_array('open_library', $merged['sources'], true)) {
                $merged['sources'][] = 'open_library';
            }
        }

        // The Dewey number has its own (cached) lookup, so it is not asked twice.
        $classification = $this->classification->lookupByIsbn($isbn);
        if ($merged && $classification && ! empty($classification['deweyClass'])) {
            $merged['deweyNumber'] = $classification['deweyClass'];
            $merged['deweySource'] = $classification['source'] ?? null;
        }

        // A hit is kept for 30 days (a cover or a title can be corrected upstream); a miss for a day, since
        // catalogues add records over time.
        Cache::put($key, $merged ?? false, $merged ? now()->addDays(30) : now()->addDay());

        return $merged;
    }

    /**
     * @return array<string, array<string, mixed>> the fields each source that answered knew, keyed by source name
     */
    private function fetchAll(string $isbn): array
    {
        $loc = config('classification.library_of_congress');
        $open = config('classification.open_library');
        $google = config('classification.google_books');

        try {
            $responses = Http::pool(function (Pool $pool) use ($isbn, $loc, $open, $google) {
                $calls = [];

                if ($loc['enabled'] ?? true) {
                    $calls[] = $pool->as('loc')->timeout($loc['timeout'])->connectTimeout($loc['connect_timeout'])
                        ->get($loc['base_url'], [
                            'version'        => '1.1',
                            'operation'      => 'searchRetrieve',
                            'query'          => "bath.isbn={$isbn}",
                            'maximumRecords' => 1,
                            'recordSchema'   => 'marcxml',
                        ]);
                }

                if ($open['enabled'] ?? true) {
                    $calls[] = $pool->as('open')->timeout($open['timeout'])->connectTimeout($open['connect_timeout'])
                        ->get(rtrim($open['base_url'], '/') . '/api/books', ['bibkeys' => "ISBN:{$isbn}", 'jscmd' => 'data', 'format' => 'json']);
                }

                if ($google['enabled'] ?? true) {
                    $calls[] = $pool->as('google')->timeout($google['timeout'])->connectTimeout($google['connect_timeout'])
                        ->get(rtrim($google['base_url'], '/') . '/volumes', array_filter([
                            'q'          => "isbn:{$isbn}",
                            'maxResults' => 1,
                            'key'        => $google['key'] ?? null,
                        ]));
                }

                return $calls;
            });
        } catch (Throwable $e) {
            Log::warning("Book details lookup failed for ISBN {$isbn}: {$e->getMessage()}");

            return [];
        }

        $found = [];

        // A source that could not be reached comes back as an exception object instead of a response.
        foreach (['loc' => 'fromLibraryOfCongress', 'open' => 'fromOpenLibrary', 'google' => 'fromGoogleBooks'] as $name => $parse) {
            $response = $responses[$name] ?? null;

            if (! $response || $response instanceof Throwable || ! $response->ok()) {
                continue;
            }

            try {
                $fields = $this->{$parse}($response, $isbn);
            } catch (Throwable $e) {
                Log::warning("Could not read the {$name} record for ISBN {$isbn}: {$e->getMessage()}");

                continue;
            }

            if ($fields) {
                $found[$name] = $fields;
            }
        }

        return $found;
    }

    /**
     * The first source (in trust order) with a value wins each field. Null when no source gave a title or an author:
     * a record with neither isn't worth filling a form from.
     *
     * @param array<string, array<string, mixed>> $found
     */
    private function merge(string $isbn, array $found): ?array
    {
        $names = ['loc' => 'library_of_congress', 'open' => 'open_library', 'google' => 'google_books'];
        $result = [
            'isbn' => $isbn, 'title' => null, 'authors' => [], 'publisher' => null, 'publishYear' => null,
            'pageCount' => null, 'description' => null, 'coverUrl' => null, 'deweyNumber' => null, 'deweySource' => null, 'sources' => [],
        ];

        // Open Library and Google Books are open to anyone and sometimes hold a junk or wrong record for an ISBN (a
        // spam page filed under a textbook's number). The first title found is the anchor: a later source whose title
        // has no word in common with it is describing some other book, so none of its fields are used.
        $anchor = null;

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
            foreach (['title', 'publisher', 'publishYear', 'pageCount', 'description', 'coverUrl'] as $field) {
                if ($result[$field] === null && ! empty($fields[$field])) {
                    $result[$field] = $fields[$field];
                    $used = true;
                }
            }

            if (! $result['authors'] && ! empty($fields['authors'])) {
                $result['authors'] = $fields['authors'];
                $used = true;
            }

            if ($used) {
                $result['sources'][] = $names[$source];
            }
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

    /** Library of Congress: MARC 245 title, 100/700 authors, 264/260 publisher and year, 300 pages, 520 summary. */
    private function fromLibraryOfCongress($response, string $isbn): ?array
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
            'description' => $this->cleanText($this->subfields($xml, '520', ['a'])),
            'coverUrl'    => null,
        ];
    }

    /** Open Library's "data" record: it has covers, and many editions the Library of Congress does not. */
    private function fromOpenLibrary($response, string $isbn): ?array
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

        return [
            'title'       => $this->cleanTitle($title),
            'authors'     => $this->cleanAuthors($authors),
            'publisher'   => $this->cleanText($record['publishers'][0]['name'] ?? null),
            'publishYear' => $this->year($record['publish_date'] ?? null),
            'pageCount'   => isset($record['number_of_pages']) && (int) $record['number_of_pages'] > 0 ? (int) $record['number_of_pages'] : null,
            'description' => null,
            'coverUrl'    => $this->httpsUrl($record['cover']['large'] ?? $record['cover']['medium'] ?? null),
        ];
    }

    /** Google Books' volume record: the widest coverage, and the only one that usually has a description. */
    private function fromGoogleBooks($response, string $isbn): ?array
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
            'description' => $this->cleanText($info['description'] ?? null),
            'coverUrl'    => $this->httpsUrl($cover),
        ];
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
