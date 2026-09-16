<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Penalty extends Model
{
    use HasFactory;

    protected $primaryKey = 'penaltyID';

    protected $fillable = [
        'uuid', 'loanID', 'penaltyTypeID', 'amount',
        'computedAt', 'settledAt', 'paymentStatus',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'computedAt' => 'datetime',
        'settledAt' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loanID', 'loanID');
    }

    public function penaltyType()
    {
        return $this->belongsTo(PenaltyType::class, 'penaltyTypeID', 'penaltyTypeID');
    }

    // ---------------------------------------------------------------
    // Accrual logic — recomputes (or creates) the single running
    // penalty for a loan, based on how overdue it currently is.
    // Called both by the scheduled command (while still Active) and
    // by Loan::markReturned() (final tally at the moment of return).
    // ---------------------------------------------------------------

    public static function accrueForLoan(Loan $loan): ?self
    {
        $book = $loan->copy->book;
        $rule = self::ruleForArea($book->circulationType);

        if (! $rule) {
            return null; // area isn't configured for penalties
        }

        $endTime = $loan->returnDate ?? now();
        $elapsedUnits = self::elapsedUnits($loan->dueDate, $endTime, $rule->rateUnit);

        if ($elapsedUnits <= 0) {
            return null; // not actually overdue
        }

        $penalty = self::firstOrNew(['loanID' => $loan->loanID]);
        $penalty->uuid ??= (string) Str::uuid();
        $penalty->penaltyTypeID = $rule->penaltyTypeID;
        $penalty->amount = round($rule->rate * $elapsedUnits, 2);
        $penalty->computedAt = now();
        $penalty->paymentStatus ??= 'Unpaid';
        $penalty->save();

        return $penalty;
    }

    public static function ruleForArea(string $area): ?PenaltyRule
    {
        return PenaltyType::where('category', $area)->first()?->rules()->first();
    }

    private static function elapsedUnits(\DateTimeInterface $due, \DateTimeInterface $end, string $unit): int
    {
        $due = Carbon::parse($due);
        $end = Carbon::parse($end);

        if ($end->lessThanOrEqualTo($due)) {
            return 0;
        }

        $minutes = $due->diffInMinutes($end);

        return match ($unit) {
            'hour'  => (int) ceil($minutes / 60),
            default => (int) ceil($minutes / (60 * 24)), // 'day'
        };
    }
}
