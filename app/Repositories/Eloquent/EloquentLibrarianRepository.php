<?php

namespace App\Repositories\Eloquent;

use App\Models\Librarian;
use App\Repositories\Contracts\LibrarianRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentLibrarianRepository extends BaseRepository implements LibrarianRepositoryInterface
{
    public function __construct(Librarian $model)
    {
        parent::__construct($model);
    }

    public function all(): Collection
    {
        return Librarian::all();
    }

    public function allExcept(int $librarianID): Collection
    {
        return Librarian::where('librarianID', '!=', $librarianID)->get();
    }
}
