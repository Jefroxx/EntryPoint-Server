<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'librarian'        => \App\Http\Middleware\EnsureUserIsLibrarian::class,
            'student'          => \App\Http\Middleware\EnsureUserIsStudent::class,
            // Staff roles: `admin` is the admin side; `staff.permission` keeps librarians and assistants to their areas.
            'admin'            => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'staff.permission' => \App\Http\Middleware\EnforceStaffPermission::class,
            'engagement'       => \App\Http\Middleware\EnsureStudentTakesPartInEngagement::class,
            'audit'            => \App\Http\Middleware\LogStaffActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
