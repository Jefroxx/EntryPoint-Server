<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'bookID';

    protected $fillable = [
        'uuid',
        'subjectID',
        'title',
        'callNumber',
        'isbn',
        'publicationYear',
        'coverImageURL',
        'shelfLocation',
    ];

    protected $casts = [
        'publicationYear' => 'integer',
    ];

    public function subject()
    {
        return $this->belongsTo(BookSubject::class, 'subjectID', 'subjectID');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'book_author', 'bookID', 'authorID')
            ->withPivot('role');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'bookID', 'bookID');
    }

    public function totalCopiesCount(): int
    {
        return $this->copies()->where('status', '!=', 'retired')->count();
    }

    public function availableCopiesCount(): int
    {
        return $this->copies()->where('status', 'available')->count();
    }

    public function isAvailable(): bool
    {
        return $this->availableCopiesCount() > 0;
    }

    // ---------------------------------------------------------------
    // Loan rule helpers (config/loans.php, keyed by circulationType)
    // ---------------------------------------------------------------

    /**
     * Every circulationType currently configured in config/loans.php is
     * loanable — there's no "library use only" restriction defined yet.
     * This exists so LoanController::store() has one place to check
     * rather than inlining the config lookup, and so a future restricted
     * type (e.g. reference-only Filipiniana) has somewhere to plug in.
     */
    public function isLoanable(): bool
    {
        return config()->has("loans.due_days.{$this->circulationType}");
    }

    public function assertLoanable(): void
    {
        if (! $this->isLoanable()) {
            throw new \RuntimeException(
                "Books under '{$this->circulationType}' are not configured for loaning."
            );
        }
    }

    public function computeDueDate(?\DateTimeInterface $from = null): Carbon
    {
        $from = $from ? Carbon::instance($from) : now();
        $dueDays = config("loans.due_days.{$this->circulationType}", 7);

        return $from->copy()->addDays($dueDays);
    }
}
