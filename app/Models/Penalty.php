<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'penaltyID';

    /**
     * A fine becomes payable once its book is back. Still unpaid this many days after the return, it is flagged
     * as long unpaid in the Penalties tab.
     */
    public const LONG_UNPAID_DAYS = 30;

    /**
     * The penalty types for a lost or damaged copy (not a late return). The student pays the book's price or replaces
     * the book; either way the copy leaves the catalog.
     */
    public const COPY_CATEGORIES = ['lost', 'damaged'];

    protected $fillable = [
        'uuid', 'loanID', 'studentID', 'copyID', 'penaltyTypeID', 'amount',
        'computedAt', 'settledAt', 'paymentStatus', 'receiptNumber',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'computedAt' => 'datetime',
        'settledAt' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A penalty knows its student and copy itself. Whoever creates one from a loan (the fine accrual, the seeders,
        // an import) need not copy them over: they are read from the loan.
        static::creating(function (Penalty $penalty) {
            if ($penalty->loanID && (! $penalty->studentID || ! $penalty->copyID)) {
                $loan = Loan::find($penalty->loanID);
                $penalty->studentID ??= $loan?->studentID;
                $penalty->copyID ??= $loan?->copyID;
            }
        });
    }

    /** The loan behind it: always for a late fee, and for a lost/damaged book that was lent (none if it was lost in the library). */
    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loanID', 'loanID');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function copy()
    {
        return $this->belongsTo(BookCopy::class, 'copyID', 'copyID');
    }

    /** What the librarian's penalty lists need, loaded in one go (the book even if it was since removed). */
    public static function listingRelations(): array
    {
        return [
            'student.user',
            'copy.book' => fn ($query) => $query->withTrashed(),
            'loan.student.user',
            'loan.copy.book' => fn ($query) => $query->withTrashed(),
            'penaltyType',
        ];
    }

    public function penaltyType()
    {
        return $this->belongsTo(PenaltyType::class, 'penaltyTypeID', 'penaltyTypeID');
    }

    /** True for a penalty over a lost or damaged copy, false for a late-return fine. */
    public function isForCopy(): bool
    {
        return in_array($this->penaltyType?->category, self::COPY_CATEGORIES, true);
    }
}
