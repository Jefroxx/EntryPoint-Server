<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Librarian\BookController;
use App\Http\Controllers\Librarian\StudentApprovalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // Student-only actions
    Route::middleware('student')->group(function () {
    });

    // Librarian-only actions
    Route::middleware('librarian')->group(function () {
        Route::post('/students/{student}/approve', [StudentApprovalController::class, 'approve']);
        Route::post('/students/{student}/reject', [StudentApprovalController::class, 'reject']);

        Route::post('/books', [BookController::class, 'store']);
        Route::patch('/books/{book}', [BookController::class, 'update']);
        Route::delete('/books/{book}', [BookController::class, 'destroy']);
    });
});
