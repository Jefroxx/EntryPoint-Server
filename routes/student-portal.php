<?php

use App\Http\Controllers\Student\PortalController;
use Illuminate\Support\Facades\Route;

// Student app read endpoints. Included from routes/api.php, so it inherits the /api prefix.
Route::middleware(['auth:sanctum', 'student'])->prefix('student')->group(function () {
    Route::get('/profile', [PortalController::class, 'profile']);
    Route::get('/loans', [PortalController::class, 'loans']);
    Route::get('/penalties', [PortalController::class, 'penalties']);
    Route::get('/attendance', [PortalController::class, 'attendance']);
    Route::get('/redemptions', [PortalController::class, 'redemptions']);

    Route::get('/catalog', [PortalController::class, 'catalog']);
    Route::get('/catalog/subjects', [PortalController::class, 'subjects']);
    Route::get('/catalog/{book}', [PortalController::class, 'catalogShow']);
});
