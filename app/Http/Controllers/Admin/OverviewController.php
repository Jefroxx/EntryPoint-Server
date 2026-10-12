<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAccountService;

class OverviewController extends Controller
{
    public function __construct(private AdminAccountService $accounts)
    {
    }

    public function show()
    {
        return response()->json($this->accounts->overview());
    }
}
