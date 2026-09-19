<?php

namespace App\Repositories\Eloquent;

use App\Models\Author;
use App\Repositories\Contracts\AuthorRepositoryInterface;
use Illuminate\Support\Str;

class EloquentAuthorRepository extends BaseRepository implements AuthorRepositoryInterface
{
    public function __construct(Author $model)
    {
        parent::__construct($model);
    }

    public function firstOrCreateByName(string $name): Author
    {
        return Author::firstOrCreate(['name' => $name], ['uuid' => Str::uuid()]);
    }

    public function cutterNumberExists(string $cutterNumber): bool
    {
        return $this->existsBy('cutterNumber', $cutterNumber);
    }
}
