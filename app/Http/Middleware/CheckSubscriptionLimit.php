<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user   = auth()->user();
        $limits = ['free' => 50, 'pro' => 500, 'team' => 9999];
        $used   = $user->usageLogs()->whereMonth('created_at', now())->sum('prs_reviewed');

        if ($used >= ($limits[$user->plan] ?? 50)) {
            return response()->json([
                'error'   => 'Monthly PR limit reached',
                'upgrade' => route('billing.checkout')
            ], 429);
        }

        return $next($request);
    }
}
