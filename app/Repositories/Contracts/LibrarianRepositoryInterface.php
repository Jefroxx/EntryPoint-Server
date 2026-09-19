<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface LibrarianRepositoryInterface extends RepositoryInterface
{
    public function all(): Collection;

    public function allExcept(int $librarianID): Collection;
}
