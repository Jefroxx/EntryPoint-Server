<?php

/*
|--------------------------------------------------------------------------
| Audit log
|--------------------------------------------------------------------------
|
| `actions` maps a staff request, as "METHOD route-uri", to [action key, wording]. The LogStaffActivity middleware
| records an entry whenever one of these succeeds, naming the record from the route's bound model ("Approved a student:
| Baby Littel (84-7820-039)"). A request that isn't listed (the scanner, a login) is not logged.
|
| The action key's first part is its area, which is what the admin screen filters on (`areas`).
|
*/

return [
    'areas' => [
        'student'     => 'Students',
        'loan'        => 'Loans and returns',
        'fine'        => 'Fines',
        'reservation' => 'Reservations',
        'catalog'     => 'Library catalog',
        'engagement'  => 'Engagement',
        'resource'    => 'Resources',
        'settings'    => 'Settings',
        'account'     => 'Accounts',
        'roles'       => 'Roles and permissions',
    ],

    'actions' => [
        'POST librarian/students/{student}/approve' => ['student.approved', 'Approved a student'],
        'POST librarian/students/{student}/reject'  => ['student.rejected', 'Rejected a student'],

        'POST librarian/loans'                              => ['loan.checked-out', 'Checked out a book'],
        'POST librarian/loans/{loan}/return'                => ['loan.returned', 'Returned a book'],
        'POST librarian/loans/{loan}/receive'               => ['loan.received', 'Received a book to check'],
        'POST librarian/loans/{loan}/finish-return'         => ['loan.returned', 'Finished checking a returned book'],
        'POST librarian/self-return-reports/{report}/verify' => ['loan.returned', 'Verified a self-return'],
        'POST librarian/self-return-reports/{report}/reject' => ['loan.self-return-rejected', 'Rejected a self-return'],

        'POST librarian/penalties/{penalty}/settle' => ['fine.paid', 'Marked a fine as paid'],

        'POST librarian/reservations/{reservation}/accept' => ['reservation.accepted', 'Accepted a reservation'],
        'POST librarian/reservations/{reservation}/reject' => ['reservation.rejected', 'Rejected a reservation'],

        'POST librarian/books'                        => ['catalog.book-added', 'Added a book'],
        'PATCH librarian/books/{book}'                => ['catalog.book-edited', 'Edited a book'],
        'DELETE librarian/books/{book}'               => ['catalog.book-removed', 'Removed a book'],
        'POST librarian/books/{book}/copies'          => ['catalog.copies-added', 'Added copies of a book'],
        'PATCH librarian/copies/{copy}'               => ['catalog.copy-edited', 'Edited a copy'],
        'POST librarian/copies/{copy}/remove'         => ['catalog.copy-removed', 'Removed a copy'],
        'POST librarian/subjects'                     => ['catalog.category-added', 'Added a category'],
        'PATCH librarian/subjects/{subject}'          => ['catalog.category-edited', 'Edited a category'],
        'DELETE librarian/subjects/{subject}'         => ['catalog.category-removed', 'Removed a category'],
        'POST librarian/book-suggestions/{suggestion}/approve' => ['catalog.request-approved', 'Approved a book request'],
        'POST librarian/book-suggestions/{suggestion}/reject'  => ['catalog.request-rejected', 'Rejected a book request'],

        'POST librarian/achievements'                  => ['engagement.achievement-added', 'Added an achievement'],
        'PATCH librarian/achievements/{achievement}'   => ['engagement.achievement-edited', 'Edited an achievement'],
        'DELETE librarian/achievements/{achievement}'  => ['engagement.achievement-removed', 'Removed an achievement'],
        'POST librarian/market-items'                  => ['engagement.item-added', 'Added a points shop item'],
        'PATCH librarian/market-items/{item}'          => ['engagement.item-edited', 'Edited a points shop item'],
        'DELETE librarian/market-items/{item}'         => ['engagement.item-removed', 'Removed a points shop item'],
        'POST librarian/point-redemptions/{redemption}/fulfill' => ['engagement.redemption-fulfilled', 'Fulfilled a redemption'],
        'POST librarian/point-redemptions/{redemption}/cancel'  => ['engagement.redemption-cancelled', 'Cancelled a redemption'],

        'POST librarian/resources'               => ['resource.added', 'Added a resource'],
        'PATCH librarian/resources/{resource}'   => ['resource.edited', 'Edited a resource'],
        'DELETE librarian/resources/{resource}'  => ['resource.removed', 'Removed a resource'],

        'PUT librarian/settings/loan-periods'          => ['settings.loan-periods', 'Changed the loan periods'],
        'PUT librarian/settings/fine-rules/{area}'     => ['settings.fine-rule', 'Changed a fine rule'],
        'DELETE librarian/settings/fine-rules/{area}'  => ['settings.fine-rule-removed', 'Removed a fine rule'],
    ],
];
