<?php
namespace Tests\Feature;

use App\Jobs\DispatchReviewJob;
use App\Models\{Repository, PullRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Cache};
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    private function webhookPayload(string $action = 'opened'): array
    {
        return [
            'action'       => $action,
            'number'       => 42,
            'pull_request' => [
                'title'    => 'feat: add auth',
                'html_url' => 'https://github.com/acme/api/pull/42',
                'user'     => ['login' => 'johndoe'],
                'head'     => ['sha'   => 'abc123sha'],
            ],
            'repository' => ['full_name' => 'acme/api'],
        ];
    }

    #[Test]
    public function it_rejects_webhook_with_invalid_signature()
    {
        $this->postJson('/webhook/github', $this->webhookPayload(), [
            'X-GitHub-Event'      => 'pull_request',
            'X-Hub-Signature-256' => 'sha256=invalidsignature',
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_queues_review_on_pr_opened()
    {
        Queue::fake();
        $user    = \App\Models\User::factory()->create();
        $repo    = $this->makeRepo($user, ['repo_full_name' => 'acme/api', 'webhook_secret' => 'secret']);
        $payload = $this->webhookPayload('opened');
        $body    = json_encode($payload);

        $this->postJson('/webhook/github', $payload, [
            'X-GitHub-Event'      => 'pull_request',
            'X-Hub-Signature-256' => $this->githubSig($body, 'secret'),
        ])->assertOk()
          ->assertJson(['status' => 'queued']);

        Queue::assertPushedOn('reviews', DispatchReviewJob::class);
        $this->assertDatabaseHas('pull_requests', ['pr_number' => 42, 'author' => 'johndoe']);
    }

    #[Test]
    public function it_queues_review_on_pr_synchronize()
    {
        Queue::fake();
        $user    = \App\Models\User::factory()->create();
        $repo    = $this->makeRepo($user, ['repo_full_name' => 'acme/api', 'webhook_secret' => 'secret']);
        $payload = $this->webhookPayload('synchronize');
        $body    = json_encode($payload);

        $this->postJson('/webhook/github', $payload, [
            'X-GitHub-Event'      => 'pull_request',
            'X-Hub-Signature-256' => $this->githubSig($body, 'secret'),
        ])->assertOk()
          ->assertJson(['status' => 'queued']);

        Queue::assertPushedOn('reviews', DispatchReviewJob::class);
    }

    #[Test]
    public function it_ignores_non_pr_events()
    {
        Queue::fake();
        $payload = ['ref' => 'refs/heads/main'];
        $body    = json_encode($payload);

        $this->postJson('/webhook/github', $payload, [
            'X-GitHub-Event'      => 'push',
            'X-Hub-Signature-256' => $this->githubSig($body, 'secret'),
        ])->assertOk()
          ->assertJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_deduplicates_same_commit_sha()
    {
        Queue::fake();
        $user    = \App\Models\User::factory()->create();
        $this->makeRepo($user, ['repo_full_name' => 'acme/api', 'webhook_secret' => 'secret']);
        $payload = $this->webhookPayload();
        $body    = json_encode($payload);
        $headers = [
            'X-GitHub-Event'      => 'pull_request',
            'X-Hub-Signature-256' => $this->githubSig($body, 'secret'),
        ];

        $this->postJson('/webhook/github', $payload, $headers); // first
        $this->postJson('/webhook/github', $payload, $headers)  // duplicate
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        Queue::assertPushedTimes(DispatchReviewJob::class, 1); // only once
    }

    #[Test]
    public function it_ignores_inactive_repos()
    {
        Queue::fake();
        $user = \App\Models\User::factory()->create();
        $this->makeRepo($user, [
            'repo_full_name' => 'acme/api',
            'is_active'      => false,
            'webhook_secret' => 'secret',
        ]);
        $payload = $this->webhookPayload();
        $body    = json_encode($payload);

        $this->postJson('/webhook/github', $payload, [
            'X-GitHub-Event'      => 'pull_request',
            'X-Hub-Signature-256' => $this->githubSig($body, 'secret'),
        ])->assertOk()
          ->assertJson(['status' => 'repo_not_found']);

        Queue::assertNothingPushed();
    }
}
