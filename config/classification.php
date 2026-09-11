<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open Library lookup
    |--------------------------------------------------------------------------
    |
    | Free, keyless lookup used to pull an already-published Dewey Decimal
    | class and LC Cutter number for a book by ISBN, instead of guessing.
    | Best-effort only: disabled entirely, unreachable, or a miss all fall
    | back to the local classification below without failing the request.
    |
    */

    'open_library' => [
        'enabled'    => env('OPEN_LIBRARY_LOOKUP_ENABLED', true),
        'base_url'   => env('OPEN_LIBRARY_BASE_URL', 'https://openlibrary.org'),
        // On a cold PHP/Windows TLS handshake, 2-3s was cutting it close and
        // intermittently timed out in testing — 6/4 gives it room without
        // letting a genuinely dead host hang the request for long.
        'timeout'    => (int) env('OPEN_LIBRARY_TIMEOUT', 6),
        'connect_timeout' => (int) env('OPEN_LIBRARY_CONNECT_TIMEOUT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dewey Decimal fallback table
    |--------------------------------------------------------------------------
    |
    | Used when there's no ISBN, the lookup above misses, or it's disabled.
    | Keyword is matched as a case-insensitive substring against the book
    | category's name; first match wins. This is the standard, widely
    | published top-level/second-level Dewey breakdown (000-900) — not the
    | detailed, licensed WebDewey schedules.
    |
    */

    'dewey' => [
        'default' => '000',

        'map' => [
            'computer science'    => '004',
            'programming'         => '005',
            'information technology' => '004',
            'software'            => '005',
            'database'            => '005.7',
            'mathematic'          => '510',
            'science'             => '500',
            'biology'             => '570',
            'chemistry'           => '540',
            'physics'             => '530',
            'engineering'         => '620',
            'technology'          => '600',
            'medicine'            => '610',
            'nursing'             => '610',
            'agriculture'         => '630',
            'business'            => '650',
            'management'          => '658',
            'economics'           => '330',
            'education'           => '370',
            'philosophy'          => '100',
            'psychology'          => '150',
            'religion'            => '200',
            'theology'            => '230',
            'social science'      => '300',
            'sociology'           => '301',
            'political'           => '320',
            'law'                 => '340',
            'language'            => '400',
            'linguistics'         => '410',
            'literature'          => '800',
            'fiction'             => '813',
            'poetry'              => '811',
            'drama'               => '812',
            'art'                 => '700',
            'music'               => '780',
            'sports'              => '796',
            'history'             => '900',
            'geography'           => '910',
            'biography'           => '920',
            'filipiniana'         => '959.9',
            'philippine'          => '959.9',
            'reference'           => '030',
            'general'             => '000',
        ],
    ],

];
