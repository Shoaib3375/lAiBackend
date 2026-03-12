<?php
namespace Tests\Feature;

use App\Models\{User, Repository, PullRequest, Ruleset};
use Illuminate\Support\Facades\Queue;
use App\Jobs\DispatchReviewJob;

trait ApiTestTrait
{
    /** Create a user and return with Sanctum token header */
    protected function authUser(?User $user = null): array
    {
        $user  = $user ?? User::factory()->create();
        $token = $user->createToken('test')->plainTextToken();
        return [$user, ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']];
    }

    /** Create a repo belonging to a user */
    protected function makeRepo(User $user, array $overrides = []): Repository
    {
        return Repository::factory()->create(array_merge(['user_id' => $user->id], $overrides));
    }

    /** Create a PR belonging to a repo */
    protected function makePR(Repository $repo, array $overrides = []): PullRequest
    {
        return PullRequest::factory()->create(array_merge(['repository_id' => $repo->id], $overrides));
    }

    /** Internal secret header for Python worker callback */
    protected function internalHeaders(): array
    {
        return [
            'X-Internal-Secret' => 'test-secret-123',
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
        ];
    }

    /** Build HMAC signature for GitHub webhook */
    protected function githubSig(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }
}
