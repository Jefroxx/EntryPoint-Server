<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface BookSuggestionRepositoryInterface extends RepositoryInterface
{
    public function listWithFilters(?string $status): Collection;

    public function forStudent(int $studentID): Collection;
}
