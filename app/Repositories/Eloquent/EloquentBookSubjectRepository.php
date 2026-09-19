<?php

namespace App\Repositories\Eloquent;

use App\Models\BookSubject;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class EloquentBookSubjectRepository extends BaseRepository implements BookSubjectRepositoryInterface
{
    public function __construct(BookSubject $model)
    {
        parent::__construct($model);
    }

    public function firstOrCreateByName(string $name): BookSubject
    {
        return BookSubject::firstOrCreate(['name' => $name], ['uuid' => Str::uuid()]);
    }

    public function classificationCodeExists(string $code): bool
    {
        return $this->existsBy('classificationCode', $code);
    }

    public function withBooksCount(): Collection
    {
        return BookSubject::withCount('books')->orderBy('name')->get();
    }

    public function hasBooks(BookSubject $subject): bool
    {
        return $subject->books()->exists();
    }
}
