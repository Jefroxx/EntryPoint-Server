<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes an audit entry for each successful staff change listed in config/audit.php. It runs after the response is
 * built, so a request that was refused or failed leaves no entry. A logging problem never fails the request.
 */
class LogStaffActivity
{
    public function __construct(private AuditLogService $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->isSuccessful() && $request->user()) {
            try {
                $this->audit->recordRequest($request->user(), $request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
