<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Hall of Fame, achievements, points and the points shop are for students. Faculty and staff members borrow and
 * visit like anyone, but those parts of the portal are closed to them.
 */
class EnsureStudentTakesPartInEngagement
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        if ($student && ! $student->takesPartInEngagement()) {
            return response()->json([
                'message' => 'Points, achievements and the points shop are for students only.',
            ], 403);
        }

        return $next($request);
    }
}
