<?php

namespace App\Providers;

use App\Repositories\Contracts\AchievementRepositoryInterface;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\AuthorRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\BookSuggestionRepositoryInterface;
use App\Repositories\Contracts\LibrarianRepositoryInterface;
use App\Repositories\Contracts\LoanPeriodRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\MarketCartItemRepositoryInterface;
use App\Repositories\Contracts\MarketItemRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\PointRedemptionRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Contracts\ResourceUsageLogRepositoryInterface;
use App\Repositories\Contracts\SelfReturnReportRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use App\Repositories\Eloquent\EloquentAchievementRepository;
use App\Repositories\Eloquent\EloquentAttendanceLogRepository;
use App\Repositories\Eloquent\EloquentAuthorRepository;
use App\Repositories\Eloquent\EloquentBookSuggestionRepository;
use App\Repositories\Eloquent\EloquentBookCopyRepository;
use App\Repositories\Eloquent\EloquentBookRepository;
use App\Repositories\Eloquent\EloquentBookSubjectRepository;
use App\Repositories\Eloquent\EloquentLibrarianRepository;
use App\Repositories\Eloquent\EloquentLoanPeriodRepository;
use App\Repositories\Eloquent\EloquentLoanRepository;
use App\Repositories\Eloquent\EloquentMarketCartItemRepository;
use App\Repositories\Eloquent\EloquentMarketItemRepository;
use App\Repositories\Eloquent\EloquentNotificationRepository;
use App\Repositories\Eloquent\EloquentPenaltyRepository;
use App\Repositories\Eloquent\EloquentPointRedemptionRepository;
use App\Repositories\Eloquent\EloquentReservationRepository;
use App\Repositories\Eloquent\EloquentResourceRepository;
use App\Repositories\Eloquent\EloquentResourceUsageLogRepository;
use App\Repositories\Eloquent\EloquentSelfReturnReportRepository;
use App\Repositories\Eloquent\EloquentStudentRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Repositories\Eloquent\EloquentWishlistRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Interface => Eloquent implementation bindings. New repositories are
     * added here as later refactor batches introduce them.
     */
    private const BINDINGS = [
        BookRepositoryInterface::class => EloquentBookRepository::class,
        BookCopyRepositoryInterface::class => EloquentBookCopyRepository::class,
        AuthorRepositoryInterface::class => EloquentAuthorRepository::class,
        BookSubjectRepositoryInterface::class => EloquentBookSubjectRepository::class,
        LoanRepositoryInterface::class => EloquentLoanRepository::class,
        PenaltyRepositoryInterface::class => EloquentPenaltyRepository::class,
        ReservationRepositoryInterface::class => EloquentReservationRepository::class,
        WishlistRepositoryInterface::class => EloquentWishlistRepository::class,
        NotificationRepositoryInterface::class => EloquentNotificationRepository::class,
        LibrarianRepositoryInterface::class => EloquentLibrarianRepository::class,
        SelfReturnReportRepositoryInterface::class => EloquentSelfReturnReportRepository::class,
        MarketItemRepositoryInterface::class => EloquentMarketItemRepository::class,
        MarketCartItemRepositoryInterface::class => EloquentMarketCartItemRepository::class,
        PointRedemptionRepositoryInterface::class => EloquentPointRedemptionRepository::class,
        AchievementRepositoryInterface::class => EloquentAchievementRepository::class,
        StudentRepositoryInterface::class => EloquentStudentRepository::class,
        ResourceRepositoryInterface::class => EloquentResourceRepository::class,
        ResourceUsageLogRepositoryInterface::class => EloquentResourceUsageLogRepository::class,
        AttendanceLogRepositoryInterface::class => EloquentAttendanceLogRepository::class,
        UserRepositoryInterface::class => EloquentUserRepository::class,
        BookSuggestionRepositoryInterface::class => EloquentBookSuggestionRepository::class,
        LoanPeriodRepositoryInterface::class => EloquentLoanPeriodRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
