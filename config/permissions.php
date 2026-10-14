<?php

/*
|--------------------------------------------------------------------------
| Staff permissions
|--------------------------------------------------------------------------
|
| Everyone on the librarian side is a staff account (users.userType = 'librarian'); librarians.role says which kind:
| admin (everything, and the admin side), librarian, or assistant (a student assistant at the desk).
|
| `keys` are the areas a role can be given. `defaults` are what a role gets until the administrator saves its own
| list (role_permissions). `routes` maps the first path segment after /api/librarian/ to the permission it needs:
| `write` for changing things, and `read` (when it differs) for looking things up. A request may pass with ANY of the
| listed permissions. A key is the first path segment after /api/librarian/, or the first two when only part of an area is restricted
| (settings/loan-periods). Anything not listed (the dashboard, /me, a staff member's own account) is open to every
| staff account.
|
*/

return [
    'keys' => [
        'circulation' => 'Circulation: loans, returns, reservations and fines',
        'attendance'  => 'Attendance and the scanner',
        'library'     => 'Library: books, copies, categories and book requests',
        'students'    => 'Students and members: approve, reject and reinstate',
        'resources'   => 'Resources: computers, rooms and the usage log',
        'engagement'  => 'Engagement: achievements, points shop and redemptions',
        'reports'     => 'Reports',
        'settings'    => 'Settings: loan periods and fines',
    ],

    'defaults' => [
        'librarian' => ['circulation', 'attendance', 'library', 'students', 'resources', 'engagement', 'reports', 'settings'],
        'assistant' => ['circulation', 'attendance'],
    ],

    'routes' => [
        'loans'               => ['write' => ['circulation']],
        'reservations'        => ['write' => ['circulation']],
        'self-return-reports' => ['write' => ['circulation']],
        'penalties'           => ['write' => ['circulation']],

        'attendance-logs' => ['write' => ['attendance']],
        // The universal scanner reads student IDs, receipts, slips and facility labels.
        'scan'            => ['write' => ['attendance', 'circulation']],

        // Checkout and returns look students and books up, so those reads open to the desk permissions too.
        'students'     => ['write' => ['students'], 'read' => ['students', 'circulation', 'attendance']],
        'books'        => ['write' => ['library'], 'read' => ['library', 'circulation', 'attendance']],
        'copies'       => ['write' => ['library'], 'read' => ['library', 'circulation', 'attendance']],
        'subjects'     => ['write' => ['library'], 'read' => ['library', 'circulation', 'attendance', 'students']],
        'library'      => ['write' => ['library'], 'read' => ['library', 'circulation', 'attendance']],
        'book-pages'   => ['write' => ['library']],
        'stock-logs'   => ['write' => ['library']],
        'book-suggestions' => ['write' => ['library']],

        'resources'           => ['write' => ['resources'], 'read' => ['resources', 'attendance', 'circulation']],
        'resource-usage-logs' => ['write' => ['resources']],

        'achievements'      => ['write' => ['engagement']],
        'market-items'      => ['write' => ['engagement']],
        'point-redemptions' => ['write' => ['engagement']],

        'reports' => ['write' => ['reports']],

        // Everyone may read the settings page and change their own account; only loan periods and fines need 'settings'.
        'settings/loan-periods' => ['write' => ['settings']],
        'settings/fine-rules'   => ['write' => ['settings']],
    ],
];
