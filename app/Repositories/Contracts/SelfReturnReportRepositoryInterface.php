<?php

namespace App\Repositories\Contracts;

use App\Models\SelfReturnReport;
use Illuminate\Database\Eloquent\Collection;

interface SelfReturnReportRepositoryInterface extends RepositoryInterface
{
    public function pendingWithRelations(): Collection;

    public function loadForResponse(SelfReturnReport $report): SelfReturnReport;
}
