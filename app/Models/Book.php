<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use UsesUuidRouteKey;

    use HasFactory, SoftDeletes;

    protected $primaryKey = 'bookID';

    protected $fillable = [
        'uuid',
        'subjectID',
        'areaOfLibrary',
        'title',
        'isbn',
        'publicationYear',
        'volume',
        'edition',
        'pageCount',
        'publisher',
        'sourceOfFund',
        'cost',
        'remarks',
        'coverImageURL',
        'shelfLocation',
        'isNewArrival',
        'newArrivalAt',
        'deweyNumber',
        'cutter',
        'deweySource',
        'deweyStatus',
    ];

    protected $casts = [
        'isNewArrival' => 'boolean',
        'newArrivalAt' => 'datetime',
    ];

    protected $appends = ['callNumber'];

    /**
     * The call number as it is written on the shelf: the Dewey number and the Cutter, e.g. "005.13 C67". It is not
     * stored; it is always worked out from `deweyNumber` and `cutter`, so the two can't disagree.
     */
    public function getCallNumberAttribute(): ?string
    {
        $call = trim("{$this->deweyNumber} {$this->cutter}");

        return $call === '' ? null : $call;
    }

    /**
     * The call number students may see: only once a catalogue or a librarian has confirmed the Dewey number.
     * A guessed one is flagged "needs review" and stays off the shelf label and the student app until then.
     */
    public function publicCallNumber(): ?string
    {
        return $this->deweyStatus === 'ok' ? $this->callNumber : null;
    }

    /**
     * The four lines of the spine label, top to bottom: area of the library, Dewey number, the author's Cutter
     * and the year of publication. While the number is unchecked, the Dewey and Cutter lines are left empty
     * (`pending`), so nobody shelves by a number we guessed.
     *
     * @return array{area: string, dewey: ?string, cutter: ?string, year: ?int, pending: bool}
     */
    public function spineLabel(): array
    {
        $ok = $this->deweyStatus === 'ok' && $this->deweyNumber;

        return [
            'area'    => \Illuminate\Support\Str::headline((string) ($this->areaOfLibrary ?: 'circulation')),
            'dewey'   => $ok ? $this->deweyNumber : null,
            'cutter'  => $ok && $this->cutter ? $this->cutter : null,
            'year'    => $this->publicationYear ? (int) $this->publicationYear : null,
            'pending' => ! $ok,
        ];
    }

    public function subject()
    {
        return $this->belongsTo(BookSubject::class, 'subjectID', 'subjectID');
    }

    public function pagePhotos()
    {
        return $this->hasMany(BookPage::class, 'bookID', 'bookID');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'bookID', 'bookID');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'bookID', 'bookID');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'book_author', 'bookID', 'authorID')
            ->withPivot('role');
    }
}
