<?php

namespace App\Services;

use App\Models\BookSubject;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubjectService
{
    public function __construct(private BookSubjectRepositoryInterface $subjects)
    {
    }

    public function list(): Collection
    {
        return $this->subjects->withBooksCount();
    }

    public function create(array $validated): BookSubject
    {
        return $this->subjects->create([
            'uuid'               => Str::uuid(),
            'name'               => $validated['name'],
            'classificationCode' => $validated['classificationCode'] ?? null,
        ]);
    }

    public function update(BookSubject $subject, array $validated): BookSubject
    {
        return $this->subjects->update($subject, $validated)->fresh();
    }

    public function delete(BookSubject $subject): void
    {
        if ($this->subjects->hasBooks($subject)) {
            throw ValidationException::withMessages([
                'subject' => ['Cannot delete: this subject has books assigned to it.'],
            ]);
        }

        $this->subjects->delete($subject);
    }
}
