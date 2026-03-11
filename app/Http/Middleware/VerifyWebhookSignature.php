<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $signature = $request->header('X-Hub-Signature-256');
        $body      = $request->getContent();

        // Get secret per repo based on payload
        $repoName = $request->input('repository.full_name');
        $repo     = Repository::where('repo_full_name', $repoName)->first();
        $secret   = $repo?->webhook_secret ?? config('services.github.webhook_secret');

        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

        if (!hash_equals($expected, $signature ?? '')) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
