<?php

namespace App\Repositories\Contracts;

use App\Models\Author;

interface AuthorRepositoryInterface extends RepositoryInterface
{
    public function firstOrCreateByName(string $name): Author;

    public function cutterNumberExists(string $cutterNumber): bool;
}
