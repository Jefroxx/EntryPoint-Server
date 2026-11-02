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
        'classNumber',
        'isbn',
        'publicationYear',
        'volume',
        'edition',
        'pages',
        'publisher',
        'sourceOfFund',
        'cost',
        'remarks',
        'coverImageURL',
        'shelfLocation',
        'isNewArrival',
        'newArrivalAt',
        'deweyNumber',
        'deweySource',
        'deweyStatus',
    ];

    protected $casts = [
        'isNewArrival' => 'boolean',
        'newArrivalAt' => 'datetime',
    ];

    /**
     * The call number students may see: only once a catalogue or a librarian has confirmed the Dewey number.
     * A guessed one is flagged "needs review" and stays off the shelf label and the student app until then.
     */
    public function publicCallNumber(): ?string
    {
        return $this->deweyStatus === 'ok' ? $this->classNumber : null;
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

        // classNumber is "<dewey> <cutter>[work letter]"; whatever follows the Dewey number is the Cutter line.
        $cutter = $ok ? trim(substr((string) $this->classNumber, strlen((string) $this->deweyNumber))) : '';

        return [
            'area'    => \Illuminate\Support\Str::headline((string) ($this->areaOfLibrary ?: 'circulation')),
            'dewey'   => $ok ? $this->deweyNumber : null,
            'cutter'  => $cutter !== '' ? $cutter : null,
            'year'    => $this->publicationYear ? (int) $this->publicationYear : null,
            'pending' => ! $ok,
        ];
    }

    public function subject()
    {
        return $this->belongsTo(BookSubject::class, 'subjectID', 'subjectID');
    }

    public function pages()
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
