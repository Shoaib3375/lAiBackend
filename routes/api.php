<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController, RepositoryController, PullRequestController,
    ReviewController, RulesetController, WebhookController,
    BillingController, DashboardController
};

// ─── Public routes ───────────────────────────────
Route::get('/auth/github',          [AuthController::class, 'redirectToGithub']);
Route::get('/auth/github/callback',  [AuthController::class, 'handleGithubCallback']);

// ─── Webhooks (HMAC verified) ─────────────────────
Route::middleware(['throttle:60,1', 'verify.webhook'])->group(function () {
    Route::post('/webhook/github',  [WebhookController::class, 'github'])->name('webhook.github');
    Route::post('/webhook/gitlab',  [WebhookController::class, 'gitlab']);
    Route::post('/webhook/stripe',  [BillingController::class, 'stripeWebhook']);
});

// ─── Authenticated API ────────────────────────────
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Repos
    Route::apiResource('repos', RepositoryController::class);
    Route::get('/repos/available', [RepositoryController::class, 'available']);

    // PRs
    Route::get('/pull-requests',                    [PullRequestController::class, 'index']);
    Route::get('/pull-requests/{pr}',               [PullRequestController::class, 'show']);
    Route::post('/pull-requests/{pr}/re-review',    [PullRequestController::class, 'reReview']);

    // Rulesets
    Route::apiResource('rulesets', RulesetController::class);

    // Billing
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::get('/billing/portal',   [BillingController::class, 'portal']);
    Route::get('/billing/usage',    [BillingController::class, 'usage']);

    // Dashboard
    Route::get('/dashboard/stats',      [DashboardController::class, 'stats']);
    Route::get('/dashboard/activity',   [DashboardController::class, 'activity']);
    Route::get('/dashboard/top-issues', [DashboardController::class, 'topIssues']);

    // Internal route for Python worker — verified by X-Internal-Secret header
    Route::post('/internal/reviews/store', [ReviewController::class, 'store'])
        ->middleware('throttle:200,1');

// Also add APP_INTERNAL_SECRET to .env
// APP_INTERNAL_SECRET=some_long_random_256bit_string
// Python worker sends: headers={"X-Internal-Secret": os.getenv("INTERNAL_SECRET")}
});
