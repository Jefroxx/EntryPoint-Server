<?php

namespace App\Repositories\Contracts;

use App\Models\BookSubject;
use Illuminate\Database\Eloquent\Collection;

interface BookSubjectRepositoryInterface extends RepositoryInterface
{
    public function firstOrCreateByName(string $name): BookSubject;

    public function classificationCodeExists(string $code): bool;

    public function withBooksCount(): Collection;

    public function hasBooks(BookSubject $subject): bool;
}
