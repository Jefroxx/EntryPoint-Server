<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Librarian\BookController;
use App\Http\Controllers\Librarian\LoanController;
use App\Http\Controllers\Librarian\ReservationController as LibrarianReservationController;
use App\Http\Controllers\Librarian\StudentApprovalController;
use App\Http\Controllers\Student\CartController;
use App\Http\Controllers\Student\ReservationController as StudentReservationController;
use App\Http\Controllers\Student\WishlistController;
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

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Catalog browsing — any authenticated user (student or librarian)
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{book}', [BookController::class, 'show']);

    // Student-only actions
    Route::middleware('student')->prefix('student')->group(function () {
        Route::get('/wishlist', [WishlistController::class, 'index']);
        Route::post('/wishlist', [WishlistController::class, 'store']);
        Route::delete('/wishlist/{wishlist}', [WishlistController::class, 'destroy']);

        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::delete('/cart/{wishlist}', [CartController::class, 'destroy']);

        Route::get('/reservations', [StudentReservationController::class, 'index']);
        Route::post('/reservations', [StudentReservationController::class, 'store']);
        Route::delete('/reservations/{reservation}', [StudentReservationController::class, 'destroy']);
    });

    // Librarian-only actions
    Route::middleware('librarian')->prefix('librarian')->group(function () {
        Route::post('/students/{student}/approve', [StudentApprovalController::class, 'approve']);
        Route::post('/students/{student}/reject', [StudentApprovalController::class, 'reject']);

        Route::post('/books', [BookController::class, 'store']);
        Route::patch('/books/{book}', [BookController::class, 'update']);
        Route::delete('/books/{book}', [BookController::class, 'destroy']);

        Route::post('/loans', [LoanController::class, 'store']);

        Route::get('/reservations', [LibrarianReservationController::class, 'index']);
        Route::get('/reservations/queue/{bookID}', [LibrarianReservationController::class, 'queueForBook']);
        Route::post('/reservations/{reservation}/accept', [LibrarianReservationController::class, 'accept']);
        Route::post('/reservations/{reservation}/reject', [LibrarianReservationController::class, 'reject']);
    });
});
