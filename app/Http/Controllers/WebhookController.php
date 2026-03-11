<?php
namespace App\Http\Controllers;

use App\Jobs\DispatchReviewJob;
use App\Models\{Repository, PullRequest};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function github(Request $request)
    {
        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();

        // Only handle PR open / new commits pushed
        if ($event !== 'pull_request') {
            return response()->json(['status' => 'ignored']);
        }

        if (!in_array($payload['action'], ['opened', 'synchronize'])) {
            return response()->json(['status' => 'ignored']);
        }

        // Idempotency: skip if same commit already queued
        $commitSha = $payload['pull_request']['head']['sha'];
        $cacheKey = 'webhook:' . $commitSha;
        if (Cache::has($cacheKey)) {
            return response()->json(['status' => 'duplicate']);
        }
        Cache::put($cacheKey, true, now()->addMinutes(10));

        // Find repo in our DB
        $repoFullName = $payload['repository']['full_name'];
        $repo = Repository::where('repo_full_name', $repoFullName)
            ->where('is_active', true)
            ->first();

        if (!$repo) {
            return response()->json(['status' => 'repo_not_found'], 200);
        }

        // Upsert the PR record
        $pr = PullRequest::updateOrCreate(
            [
                'repository_id' => $repo->id,
                'pr_number'     => $payload['number'],
            ],
            [
                'title'      => $payload['pull_request']['title'],
                'author'     => $payload['pull_request']['user']['login'],
                'pr_url'     => $payload['pull_request']['html_url'],
                'commit_sha' => $commitSha,
                'status'     => 'pending',
            ]
        );

        // Dispatch job to Redis → Python Worker picks it up
        DispatchReviewJob::dispatch($pr, $repo)
            ->onQueue('reviews');

        return response()->json(['status' => 'queued', 'pr_id' => $pr->id]);
    }
}
