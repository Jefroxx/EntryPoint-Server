<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Loan extends Model
{
    use HasFactory;

    protected $primaryKey = 'loanID';

    protected $fillable = [
        'uuid', 'studentID', 'copyID', 'loanType',
        'checkoutDate', 'dueDate', 'returnDate', 'status',
        'dueSoonNotifiedAt', 'overdueNotifiedAt',
    ];

    protected $casts = [
        'checkoutDate'      => 'datetime',
        'dueDate'           => 'datetime',
        'returnDate'        => 'datetime',
        'dueSoonNotifiedAt' => 'datetime',
        'overdueNotifiedAt' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function copy()
    {
        return $this->belongsTo(BookCopy::class, 'copyID', 'copyID');
    }

    public function selfReturnReport()
    {
        return $this->hasOne(SelfReturnReport::class, 'loanID', 'loanID');
    }

    public function penalties()
    {
        return $this->hasMany(Penalty::class, 'loanID', 'loanID');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'Active' && $this->dueDate->isPast();
    }

    /**
     * Mark this loan as returned: frees the copy, closes the loan,
     * and does one final penalty accrual to lock in the total fine
     * (if any) as of the exact return moment.
     */
    public function markReturned(): void
    {
        $this->returnDate = now();
        $this->status = 'Returned';
        $this->save();

        $this->copy->update(['status' => 'available']);

        Penalty::accrueForLoan($this);
    }

    /**
     * Student self-reports a return without a librarian present.
     * Creates a Pending report the first time; a Rejected report can be
     * resubmitted after a 6-hour cooldown (see the rejection notification's
     * wording in SelfReturnReportController::reject()).
     */
    public function submitSelfReturn(): SelfReturnReport
    {
        if ($this->status !== 'Active') {
            throw new \RuntimeException('Only an active loan can be self-returned.');
        }

        $existing = $this->selfReturnReport;

        if ($existing && $existing->verificationStatus === 'Pending') {
            throw new \RuntimeException('A self-return report for this loan is already pending verification.');
        }

        if ($existing && $existing->verificationStatus === 'Rejected') {
            $cooldownEnds = $existing->updated_at->addHours(6);

            if (now()->lessThan($cooldownEnds)) {
                throw new \RuntimeException("You can report this return again after {$cooldownEnds->format('M d, Y g:i A')}.");
            }

            $existing->update([
                'verifiedByLibrarianID' => null,
                'reportedAt'            => now(),
                'verificationStatus'    => 'Pending',
            ]);

            return $existing->fresh();
        }

        return SelfReturnReport::create([
            'uuid'               => Str::uuid(),
            'loanID'             => $this->loanID,
            'reportedAt'         => now(),
            'verificationStatus' => 'Pending',
        ]);
    }
}
