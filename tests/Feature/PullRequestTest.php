<?php
namespace Tests\Feature;

use App\Jobs\DispatchReviewJob;
use App\Models\{PullRequest, ReviewComment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PullRequestTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function it_lists_prs_with_pagination()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        PullRequest::factory()->count(5)->create(['repository_id' => $repo->id]);

        $response = $this->getJson('/api/pull-requests', $headers);

        $response->assertOk()
            ->assertJsonStructure([
                'data', 'current_page', 'total', 'per_page'
            ])
            ->assertJsonPath('total', 5);
    }

    #[Test]
    public function it_filters_prs_by_status()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        PullRequest::factory()->create(['repository_id' => $repo->id, 'status' => 'done']);
        PullRequest::factory()->create(['repository_id' => $repo->id, 'status' => 'pending']);

        $this->getJson('/api/pull-requests?status=done', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'done');
    }

    #[Test]
    public function it_shows_pr_detail_with_comments_grouped_by_file()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo, ['status' => 'done']);

        ReviewComment::factory()->create([
            'pull_request_id' => $pr->id,
            'file_path'       => 'app/Http/Foo.php',
            'severity'        => 'error',
        ]);
        ReviewComment::factory()->create([
            'pull_request_id' => $pr->id,
            'file_path'       => 'app/Http/Foo.php',
            'severity'        => 'warning',
        ]);

        $response = $this->getJson("/api/pull-requests/{$pr->id}", $headers);

        $response->assertOk()
            ->assertJsonStructure(['pr', 'comments_by_file', 'summary'])
            ->assertJsonPath('summary.total',    2)
            ->assertJsonPath('summary.errors',   1)
            ->assertJsonPath('summary.warnings', 1);

        // Comments grouped by file key
        $this->assertArrayHasKey(
            'app/Http/Foo.php',
            $response->json('comments_by_file')
        );
    }

    #[Test]
    public function it_forbids_viewing_another_users_pr()
    {
        [$owner,    $_]      = $this->authUser();
        [$attacker, $headers] = $this->authUser();
        $repo = $this->makeRepo($owner);
        $pr   = $this->makePR($repo);

        $this->getJson("/api/pull-requests/{$pr->id}", $headers)
            ->assertForbidden();
    }

    #[Test]
    public function it_queues_job_on_re_review()
    {
        Queue::fake();
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo, ['status' => 'done']);

        $this->postJson("/api/pull-requests/{$pr->id}/re-review", [], $headers)
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        Queue::assertPushedOn('reviews', DispatchReviewJob::class);
    }

    #[Test]
    public function it_clears_old_comments_on_re_review()
    {
        Queue::fake();
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo, ['status' => 'done']);
        ReviewComment::factory()->count(3)->create(['pull_request_id' => $pr->id]);

        $this->postJson("/api/pull-requests/{$pr->id}/re-review", [], $headers);

        $this->assertDatabaseCount('review_comments', 0);
        $this->assertDatabaseHas('pull_requests', ['id' => $pr->id, 'status' => 'pending']);
    }

    #[Test]
    public function it_returns_429_when_monthly_limit_reached()
    {
        Queue::fake();
        $user = User::factory()->create(['plan' => 'free']);
        [$_, $headers] = $this->authUser($user);

        // Exhaust free plan limit (50)
        \App\Models\UsageLog::factory()->create([
            'user_id'      => $user->id,
            'prs_reviewed' => 50,
            'month'        => now()->startOfMonth(),
        ]);

        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo);

        $this->postJson("/api/pull-requests/{$pr->id}/re-review", [], $headers)
            ->assertStatus(429)
            ->assertJsonStructure(['error', 'upgrade']);
    }
}
