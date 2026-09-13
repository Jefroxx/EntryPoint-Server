<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'bookID';

    protected $fillable = [
        'uuid',
        'subjectID',
        'areasOfLibrary',
        'title',
        'classNumber',
        'isbn',
        'volume',
        'edition',
        'pages',
        'publisher',
        'sourceOfFund',
        'cost',
        'copyNumber',
        'remarks',
        'coverImageURL',
        'shelfLocation',
    ];

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function subject()
    {
        return $this->belongsTo(BookSubject::class, 'subjectID', 'subjectID');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'bookID', 'bookID');
    }

    // ---------------------------------------------------------------
    // Loan rule helpers (config/loans.php, keyed by areasOfLibrary)
    // ---------------------------------------------------------------

    public function loanRules(): array
    {
        return Config::get("loans.{$this->areasOfLibrary}", []);
    }

    public function isLoanable(): bool
    {
        return (bool) ($this->loanRules()['loanable'] ?? false);
    }

    public function assertLoanable(): void
    {
        if (! $this->isLoanable()) {
            throw new \RuntimeException(
                "Books under '{$this->areasOfLibrary}' are for library use only and cannot be loaned out."
            );
        }
    }

    public function computeDueDate(\DateTimeInterface $from = null): ?\Illuminate\Support\Carbon
    {
        $rules = $this->loanRules();

        if (empty($rules['loanable'])) {
            return null;
        }

        $from = $from ? \Illuminate\Support\Carbon::instance($from) : now();

        return match ($rules['period_unit']) {
            'days'      => $from->copy()->addDays($rules['period_value']),
            'overnight' => $from->copy()->addDay(),
            default     => $from->copy()->addDays($rules['period_value'] ?? 0),
        };
    }
}
