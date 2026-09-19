<?php

// EntryPoint — Loan period & penalty rules per "Area of the Library" (books.areasOfLibrary)
//
// - 'loanable' => false means the item never gets a due date / cannot be checked out
//   at all (Thesis, Journal, Dissertation are reference-only, in-library use).
// - 'period_unit' + 'period_value' define how the due date is computed from checkout time.
// - 'penalty_rate' + 'penalty_unit' define the overdue fine, applied per unit elapsed
//   past the due date (unit matches how the fine accrues, e.g. per day vs per hour).

return [

    'circulation' => [
        'loanable'      => true,
        'period_value'  => 2,
        'period_unit'   => 'days',
        'penalty_rate'  => 25.00,
        'penalty_unit'  => 'day',
    ],

    'reserved' => [
        'loanable'      => true,
        'period_value'  => 1,
        'period_unit'   => 'overnight', // due back next library opening
        'penalty_rate'  => 25.00,
        'penalty_unit'  => 'hour',
    ],

    'filipiniana' => [
        'loanable'      => true,
        'period_value'  => 3,
        'period_unit'   => 'days',
        'penalty_rate'  => 25.00,
        'penalty_unit'  => 'day',
    ],

    'fiction' => [
        'loanable'      => true,
        'period_value'  => 5,
        'period_unit'   => 'days',
        'penalty_rate'  => 25.00,
        'penalty_unit'  => 'day',
    ],

    'thesis' => [
        'loanable'      => false,
        'period_value'  => null,
        'period_unit'   => null,
        'penalty_rate'  => null,
        'penalty_unit'  => null,
    ],

    'journal' => [
        'loanable'      => false,
        'period_value'  => null,
        'period_unit'   => null,
        'penalty_rate'  => null,
        'penalty_unit'  => null,
    ],

    'dissertation' => [
        'loanable'      => false,
        'period_value'  => null,
        'period_unit'   => null,
        'penalty_rate'  => null,
        'penalty_unit'  => null,
    ],

];
