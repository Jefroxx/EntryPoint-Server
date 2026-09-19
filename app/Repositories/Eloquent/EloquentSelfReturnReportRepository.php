<?php

namespace App\Repositories\Eloquent;

use App\Models\SelfReturnReport;
use App\Repositories\Contracts\SelfReturnReportRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentSelfReturnReportRepository extends BaseRepository implements SelfReturnReportRepositoryInterface
{
    public function __construct(SelfReturnReport $model)
    {
        parent::__construct($model);
    }

    public function pendingWithRelations(): Collection
    {
        return SelfReturnReport::with(['loan.student.user', 'loan.copy.book'])
            ->where('verificationStatus', 'Pending')
            ->orderBy('reportedAt')
            ->get();
    }

    public function loadForResponse(SelfReturnReport $report): SelfReturnReport
    {
        return $report->load('loan.copy.book');
    }
}
