<?php

namespace App\Repositories\Eloquent;

use App\Models\BookSuggestion;
use App\Repositories\Contracts\BookSuggestionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentBookSuggestionRepository extends BaseRepository implements BookSuggestionRepositoryInterface
{
    public function __construct(BookSuggestion $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?string $status): Collection
    {
        return BookSuggestion::with('student.user')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('submittedAt')
            ->get();
    }

    public function forStudent(int $studentID): Collection
    {
        return BookSuggestion::where('studentID', $studentID)
            ->orderByDesc('submittedAt')
            ->get();
    }
}
