<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Librarian\AchievementController as LibrarianAchievementController;
use App\Http\Controllers\Librarian\AttendanceLogController;
use App\Http\Controllers\Librarian\BookController;
use App\Http\Controllers\Librarian\BookSuggestionController as LibrarianBookSuggestionController;
use App\Http\Controllers\Librarian\DashboardController;
use App\Http\Controllers\Librarian\LoanController;
use App\Http\Controllers\Librarian\MarketItemController as LibrarianMarketItemController;
use App\Http\Controllers\Librarian\PenaltyController;
use App\Http\Controllers\Librarian\PointRedemptionController;
use App\Http\Controllers\Librarian\ReportController;
use App\Http\Controllers\Librarian\ReservationController as LibrarianReservationController;
use App\Http\Controllers\Librarian\ResourceController as LibrarianResourceController;
use App\Http\Controllers\Librarian\ResourceUsageLogController;
use App\Http\Controllers\Librarian\SelfReturnReportController;
use App\Http\Controllers\Librarian\SettingsController;
use App\Http\Controllers\Librarian\StudentApprovalController;
use App\Http\Controllers\Librarian\SubjectController;
use App\Http\Controllers\Student\AchievementController as StudentAchievementController;
use App\Http\Controllers\Student\BookSuggestionController as StudentBookSuggestionController;
use App\Http\Controllers\Student\CartController;
use App\Http\Controllers\Student\LoanController as StudentLoanController;
use App\Http\Controllers\Student\MarketCartController;
use App\Http\Controllers\Student\MarketItemController as StudentMarketItemController;
use App\Http\Controllers\Student\PortalController;
use App\Http\Controllers\Student\ReservationController as StudentReservationController;
use App\Http\Controllers\Student\ResourceController as StudentResourceController;
use App\Http\Controllers\Student\WishlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/librarian/login', [AuthController::class, 'librarianLogin']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/books', [BookController::class, 'index']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Student-only actions
    Route::middleware('student')->prefix('student')->group(function () {
        Route::get('/profile', [PortalController::class, 'profile']);
        Route::get('/loans', [PortalController::class, 'loans']);
        Route::get('/penalties', [PortalController::class, 'penalties']);
        Route::get('/attendance', [PortalController::class, 'attendance']);
        Route::get('/redemptions', [PortalController::class, 'redemptions']);

        // `subjects` must stay above `{book}` or it would be read as a book id.
        Route::get('/catalog', [PortalController::class, 'catalog']);
        Route::get('/catalog/subjects', [PortalController::class, 'subjects']);
        Route::get('/catalog/{book}', [PortalController::class, 'catalogShow']);

        Route::get('/wishlist', [WishlistController::class, 'index']);
        Route::post('/wishlist', [WishlistController::class, 'store']);
        Route::delete('/wishlist/{wishlist}', [WishlistController::class, 'destroy']);

        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::delete('/cart/{wishlist}', [CartController::class, 'destroy']);

        Route::get('/reservations', [StudentReservationController::class, 'index']);
        Route::post('/reservations', [StudentReservationController::class, 'store']);
        Route::delete('/reservations/{reservation}', [StudentReservationController::class, 'destroy']);

        Route::post('/loans/{loan}/self-return', [StudentLoanController::class, 'selfReturn']);

        Route::get('/resources', [StudentResourceController::class, 'index']);

        Route::get('/achievements', [StudentAchievementController::class, 'index']);
        Route::post('/achievements/{achievement}/redeem', [StudentAchievementController::class, 'redeem']);

        Route::get('/market-items', [StudentMarketItemController::class, 'index']);
        Route::post('/market-items/{item}/redeem', [StudentMarketItemController::class, 'redeem']);

        Route::get('/market-cart', [MarketCartController::class, 'index']);
        Route::post('/market-cart', [MarketCartController::class, 'store']);
        Route::patch('/market-cart/{cartItem}', [MarketCartController::class, 'update']);
        Route::delete('/market-cart/{cartItem}', [MarketCartController::class, 'destroy']);
        Route::post('/market-cart/checkout', [MarketCartController::class, 'checkout']);

        Route::get('/book-suggestions', [StudentBookSuggestionController::class, 'index']);
        Route::post('/book-suggestions', [StudentBookSuggestionController::class, 'store']);
    });

    // Librarian-only actions
    Route::middleware('librarian')->prefix('librarian')->group(function () {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/borrowing-overview', [DashboardController::class, 'borrowingOverview']);
        Route::get('/dashboard/book-status', [DashboardController::class, 'bookStatus']);
        Route::get('/dashboard/recent-loans', [DashboardController::class, 'recentLoans']);
        Route::get('/dashboard/overdue-loans', [DashboardController::class, 'overdueLoans']);

        Route::get('/attendance-logs', [AttendanceLogController::class, 'index']);
        Route::get('/attendance-logs/stats', [AttendanceLogController::class, 'stats']);
        Route::post('/attendance-logs/scan', [AttendanceLogController::class, 'store']);

        Route::get('/students', [StudentApprovalController::class, 'index']);
        Route::get('/students/stats', [StudentApprovalController::class, 'stats']);
        Route::post('/students/{student}/approve', [StudentApprovalController::class, 'approve']);
        Route::post('/students/{student}/reject', [StudentApprovalController::class, 'reject']);

        Route::get('/library/stats', [BookController::class, 'libraryStats']);

        Route::post('/books', [BookController::class, 'store']);
        Route::patch('/books/{book}', [BookController::class, 'update']);
        Route::delete('/books/{book}', [BookController::class, 'destroy']);

        Route::get('/subjects', [SubjectController::class, 'index']);
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::patch('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings/loan-periods', [SettingsController::class, 'saveLoanPeriods']);
        Route::put('/settings/fine-rules/{area}', [SettingsController::class, 'saveFineRule']);
        Route::delete('/settings/fine-rules/{area}', [SettingsController::class, 'deleteFineRule']);
        Route::patch('/settings/account', [SettingsController::class, 'updateAccount']);
        Route::put('/settings/password', [SettingsController::class, 'changePassword']);

        Route::get('/loans', [LoanController::class, 'index']);
        Route::get('/loans/stats', [LoanController::class, 'stats']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::post('/loans/{loan}/return', [LoanController::class, 'returnBook']);

        Route::get('/penalties', [PenaltyController::class, 'index']);
        Route::get('/penalties/stats', [PenaltyController::class, 'stats']);
        Route::post('/penalties/{penalty}/settle', [PenaltyController::class, 'settle']);

        Route::get('/self-return-reports', [SelfReturnReportController::class, 'index']);
        Route::post('/self-return-reports/{report}/verify', [SelfReturnReportController::class, 'verify']);
        Route::post('/self-return-reports/{report}/reject', [SelfReturnReportController::class, 'reject']);

        Route::get('/reports/overview', [ReportController::class, 'overview']);

        Route::get('/reservations', [LibrarianReservationController::class, 'index']);
        Route::get('/reservations/queue/{bookID}', [LibrarianReservationController::class, 'queueForBook']);
        Route::post('/reservations/{reservation}/accept', [LibrarianReservationController::class, 'accept']);
        Route::post('/reservations/{reservation}/reject', [LibrarianReservationController::class, 'reject']);

        Route::get('/resources', [LibrarianResourceController::class, 'index']);
        Route::post('/resources', [LibrarianResourceController::class, 'store']);
        Route::patch('/resources/{resource}', [LibrarianResourceController::class, 'update']);
        Route::delete('/resources/{resource}', [LibrarianResourceController::class, 'destroy']);

        Route::get('/resource-usage-logs', [ResourceUsageLogController::class, 'index']);
        Route::post('/resource-usage-logs', [ResourceUsageLogController::class, 'store']);
        Route::post('/resource-usage-logs/{usageLog}/end', [ResourceUsageLogController::class, 'end']);

        Route::get('/achievements', [LibrarianAchievementController::class, 'index']);
        Route::post('/achievements', [LibrarianAchievementController::class, 'store']);
        Route::patch('/achievements/{achievement}', [LibrarianAchievementController::class, 'update']);
        Route::delete('/achievements/{achievement}', [LibrarianAchievementController::class, 'destroy']);

        Route::get('/market-items', [LibrarianMarketItemController::class, 'index']);
        Route::post('/market-items', [LibrarianMarketItemController::class, 'store']);
        Route::patch('/market-items/{item}', [LibrarianMarketItemController::class, 'update']);
        Route::delete('/market-items/{item}', [LibrarianMarketItemController::class, 'destroy']);

        Route::get('/point-redemptions', [PointRedemptionController::class, 'index']);
        Route::post('/point-redemptions/{redemption}/fulfill', [PointRedemptionController::class, 'fulfill']);
        Route::post('/point-redemptions/{redemption}/cancel', [PointRedemptionController::class, 'cancel']);

        Route::get('/book-suggestions', [LibrarianBookSuggestionController::class, 'index']);
        Route::post('/book-suggestions/{suggestion}/approve', [LibrarianBookSuggestionController::class, 'approve']);
        Route::post('/book-suggestions/{suggestion}/reject', [LibrarianBookSuggestionController::class, 'reject']);
    });
});
