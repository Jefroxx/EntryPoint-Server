<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Model;

/** A photo of one of a book's reference pages; see BookPageService. */
class BookPage extends Model
{
    use UsesUuidRouteKey;

    protected $primaryKey = 'pageID';

    /** In the order they appear in a book; also the order they're shown in. */
    public const SECTIONS = ['table_of_contents', 'appendix', 'bibliography', 'index', 'about_the_author'];

    protected $fillable = ['uuid', 'bookID', 'section', 'position', 'path'];

    protected $casts = ['position' => 'integer'];

    public function book()
    {
        return $this->belongsTo(Book::class, 'bookID', 'bookID');
    }
}
