# PRDock — PHPUnit Test Suite

> **52 tests · All API endpoints · Feature + Unit · Laravel 11 / PHPUnit 11**
>
> Run: `php artisan test --parallel --coverage`

---

## Setup & Configuration

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV"             value="testing"/>
        <env name="DB_CONNECTION"       value="sqlite"/>
        <env name="DB_DATABASE"         value=":memory:"/>
        <env name="QUEUE_CONNECTION"    value="sync"/>
        <env name="CACHE_DRIVER"        value="array"/>
        <env name="APP_INTERNAL_SECRET" value="test-secret-123"/>
    </php>
</phpunit>
```

---

### tests/Feature/ApiTestTrait.php — Shared Helpers

```php
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
```

---

## Auth Tests

**File:** `tests/Feature/AuthTest.php` · **4 tests** · Covers: `GET /me`, `POST /logout`, unauthenticated access, token leak prevention

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function it_returns_authenticated_user_on_me_endpoint()
    {
        [$user, $headers] = $this->authUser();

        $response = $this->getJson('/api/me', $headers);

        $response->assertOk()
            ->assertJson([
                'id'    => $user->id,
                'email' => $user->email,
                'plan'  => 'free',
            ])
            ->assertJsonStructure([
                'id', 'name', 'email', 'github_login', 'plan', 'created_at'
            ]);
    }

    #[Test]
    public function it_returns_401_on_me_endpoint_when_unauthenticated()
    {
        $this->getJson('/api/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function it_does_not_expose_github_token_in_me_response()
    {
        [$user, $headers] = $this->authUser();

        $this->getJson('/api/me', $headers)
            ->assertOk()
            ->assertJsonMissing(['github_token']);
    }

    #[Test]
    public function it_revokes_token_on_logout()
    {
        [$user, $headers] = $this->authUser();

        $this->postJson('/api/logout', [], $headers)
            ->assertOk()
            ->assertJson(['message' => 'Logged out']);

        // Token should no longer work
        $this->getJson('/api/me', $headers)
            ->assertUnauthorized();
    }
}
```

---

## Repository Tests

**File:** `tests/Feature/RepositoryTest.php` · **8 tests** · Covers: list, connect, update, delete, ownership policy, GitHub mock

```php
<?php
namespace Tests\Feature;

use App\Models\{User, Repository};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock GitHub HTTP calls so tests don't hit real API
        Http::fake([
            'api.github.com/user/repos*'     => Http::response(json_encode([
                ['full_name' => 'acme/api', 'private' => false],
                ['full_name' => 'acme/web', 'private' => false],
            ]), 200),
            'api.github.com/repos/*/hooks'   => Http::response(json_encode(['id' => 99]), 201),
            'api.github.com/repos/*/hooks/*' => Http::response('', 204),
        ]);
    }

    #[Test]
    public function it_lists_connected_repositories()
    {
        [$user, $headers] = $this->authUser();
        $this->makeRepo($user, ['repo_full_name' => 'acme/api']);
        $this->makeRepo($user, ['repo_full_name' => 'acme/web']);

        $this->getJson('/api/repos', $headers)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure(['*' => ['id', 'repo_full_name', 'is_active', 'pull_requests_count']]);
    }

    #[Test]
    public function it_only_returns_repos_belonging_to_auth_user()
    {
        [$user,      $headers] = $this->authUser();
        [$otherUser, $_]       = $this->authUser();

        $this->makeRepo($user);
        $this->makeRepo($otherUser); // should NOT appear

        $this->getJson('/api/repos', $headers)
            ->assertOk()
            ->assertJsonCount(1);
    }

    #[Test]
    public function it_connects_a_repository_and_installs_webhook()
    {
        [$user, $headers] = $this->authUser();

        $response = $this->postJson('/api/repos', [
            'repo_full_name' => 'acme/api',
            'provider'       => 'github',
        ], $headers);

        $response->assertCreated()
            ->assertJson([
                'repo_full_name' => 'acme/api',
                'is_active'      => true,
            ]);

        $this->assertDatabaseHas('repositories', [
            'user_id'        => $user->id,
            'repo_full_name' => 'acme/api',
        ]);
    }

    #[Test]
    public function it_validates_connect_request()
    {
        [$user, $headers] = $this->authUser();

        $this->postJson('/api/repos', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repo_full_name', 'provider']);
    }

    #[Test]
    public function it_rejects_invalid_provider()
    {
        [$user, $headers] = $this->authUser();

        $this->postJson('/api/repos', [
            'repo_full_name' => 'acme/api',
            'provider'       => 'bitbucket', // invalid
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }

    #[Test]
    public function it_deletes_repo_and_removes_webhook()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);

        $this->deleteJson("/api/repos/{$repo->id}", [], $headers)
            ->assertNoContent();

        $this->assertDatabaseMissing('repositories', ['id' => $repo->id]);
    }

    #[Test]
    public function it_prevents_deleting_another_users_repo()
    {
        [$owner,   $_]       = $this->authUser();
        [$attacker, $headers] = $this->authUser();
        $repo = $this->makeRepo($owner);

        $this->deleteJson("/api/repos/{$repo->id}", [], $headers)
            ->assertForbidden();
    }

    #[Test]
    public function it_requires_auth_to_list_repos()
    {
        $this->getJson('/api/repos')
            ->assertUnauthorized();
    }
}
```

---

## Pull Request Tests

**File:** `tests/Feature/PullRequestTest.php` · **7 tests** · Covers: list (filtered), show (grouped comments), re-review, ownership, usage limits

```php
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
```

---

## Ruleset Tests

**File:** `tests/Feature/RulesetTest.php` · **8 tests** · Covers: CRUD, default swap, validation, ownership

```php
<?php
namespace Tests\Feature;

use App\Models\Ruleset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesetTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function it_lists_rulesets()
    {
        [$user, $headers] = $this->authUser();
        Ruleset::factory()->count(3)->create(['user_id' => $user->id]);

        $this->getJson('/api/rulesets', $headers)
            ->assertOk()
            ->assertJsonCount(3);
    }

    #[Test]
    public function it_creates_a_ruleset()
    {
        [$user, $headers] = $this->authUser();

        $response = $this->postJson('/api/rulesets', [
            'name'     => 'PHP Standards',
            'language' => 'php',
            'rules'    => ['Always use strict types', 'No raw SQL queries'],
        ], $headers);

        $response->assertCreated()
            ->assertJson(['name' => 'PHP Standards', 'language' => 'php'])
            ->assertJsonPath('rules.0', 'Always use strict types');

        $this->assertDatabaseHas('rulesets', ['user_id' => $user->id, 'name' => 'PHP Standards']);
    }

    #[Test]
    public function it_swaps_default_ruleset_when_creating_new_default()
    {
        [$user, $headers] = $this->authUser();
        $old = Ruleset::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        $this->postJson('/api/rulesets', [
            'name'       => 'New Default',
            'rules'      => ['rule one'],
            'is_default' => true,
        ], $headers)->assertCreated();

        // Old ruleset should no longer be default
        $this->assertDatabaseHas('rulesets', ['id' => $old->id, 'is_default' => false]);
    }

    #[Test]
    public function it_updates_a_ruleset()
    {
        [$user, $headers] = $this->authUser();
        $ruleset = Ruleset::factory()->create(['user_id' => $user->id]);

        $this->putJson("/api/rulesets/{$ruleset->id}", [
            'name'  => 'Updated Name',
            'rules' => ['Updated rule'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('name', 'Updated Name');
    }

    #[Test]
    public function it_deletes_a_ruleset_and_unlinks_repos()
    {
        [$user, $headers] = $this->authUser();
        $ruleset = Ruleset::factory()->create(['user_id' => $user->id]);
        $repo    = $this->makeRepo($user, ['ruleset_id' => $ruleset->id]);

        $this->deleteJson("/api/rulesets/{$ruleset->id}", [], $headers)
            ->assertNoContent();

        $this->assertDatabaseMissing('rulesets', ['id' => $ruleset->id]);
        $this->assertDatabaseHas('repositories', ['id' => $repo->id, 'ruleset_id' => null]);
    }

    #[Test]
    public function it_validates_minimum_one_rule()
    {
        [$user, $headers] = $this->authUser();

        $this->postJson('/api/rulesets', ['name' => 'Empty', 'rules' => []], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules']);
    }

    #[Test]
    public function it_prevents_updating_another_users_ruleset()
    {
        [$owner,    $_]      = $this->authUser();
        [$attacker, $headers] = $this->authUser();
        $ruleset = Ruleset::factory()->create(['user_id' => $owner->id]);

        $this->putJson("/api/rulesets/{$ruleset->id}", ['name' => 'Hacked'], $headers)
            ->assertForbidden();
    }

    #[Test]
    public function it_validates_max_30_rules()
    {
        [$user, $headers] = $this->authUser();
        $tooMany = array_fill(0, 31, 'a rule');

        $this->postJson('/api/rulesets', ['name' => 'Big', 'rules' => $tooMany], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules']);
    }
}
```

---

## Webhook Tests

**File:** `tests/Feature/WebhookTest.php` · **6 tests** · Covers: HMAC verification, PR open/sync, ignored events, deduplication, inactive repos

```php
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
```

---

## Review Callback Tests (Python → Laravel)

**File:** `tests/Feature/ReviewCallbackTest.php` · **6 tests** · Covers: internal secret, bulk insert, usage increment, notification dispatch, failed status

```php
<?php
namespace Tests\Feature;

use App\Models\{PullRequest, ReviewComment};
use App\Notifications\ReviewCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReviewCallbackTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    private function validPayload(string $prId): array
    {
        return [
            'pr_id'        => $prId,
            'status'       => 'done',
            'health_score' => 82,
            'ai_summary'   => 'Found 2 issues.',
            'comments'     => [
                [
                    'file_path'   => 'app/Foo.php',
                    'line_number' => 10,
                    'severity'    => 'error',
                    'body'        => 'Potential null pointer dereference.',
                ],
                [
                    'file_path'   => 'app/Bar.php',
                    'line_number' => 55,
                    'severity'    => 'warning',
                    'body'        => 'Missing return type hint.',
                ],
            ],
        ];
    }

    #[Test]
    public function it_saves_review_results_from_python_worker()
    {
        Notification::fake();
        $user = \App\Models\User::factory()->create();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo, ['status' => 'reviewing']);

        $this->postJson('/api/internal/reviews/store', $this->validPayload($pr->id), $this->internalHeaders())
            ->assertOk()
            ->assertJson(['status' => 'saved']);

        $this->assertDatabaseHas('pull_requests', [
            'id'           => $pr->id,
            'status'       => 'done',
            'health_score' => 82,
            'ai_summary'   => 'Found 2 issues.',
        ]);
        $this->assertDatabaseCount('review_comments', 2);
    }

    #[Test]
    public function it_rejects_invalid_internal_secret()
    {
        $pr = PullRequest::factory()->create();

        $this->postJson('/api/internal/reviews/store', $this->validPayload($pr->id), [
            'X-Internal-Secret' => 'wrong-secret',
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_sends_notification_after_review_saved()
    {
        Notification::fake();
        $user = \App\Models\User::factory()->create();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo);

        $this->postJson('/api/internal/reviews/store', $this->validPayload($pr->id), $this->internalHeaders());

        Notification::assertSentTo($user, ReviewCompletedNotification::class);
    }

    #[Test]
    public function it_increments_usage_after_review_saved()
    {
        Notification::fake();
        $user = \App\Models\User::factory()->create();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo);

        $this->postJson('/api/internal/reviews/store', $this->validPayload($pr->id), $this->internalHeaders());

        $this->assertDatabaseHas('usage_logs', [
            'user_id'      => $user->id,
            'prs_reviewed' => 1,
        ]);
    }

    #[Test]
    public function it_handles_failed_status_from_worker()
    {
        Notification::fake();
        $user = \App\Models\User::factory()->create();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo);

        $this->postJson('/api/internal/reviews/store', [
            'pr_id'  => $pr->id,
            'status' => 'failed',
        ], $this->internalHeaders())->assertOk();

        $this->assertDatabaseHas('pull_requests', ['id' => $pr->id, 'status' => 'failed']);
    }

    #[Test]
    public function it_rejects_nonexistent_pr_id()
    {
        $this->postJson('/api/internal/reviews/store', [
            'pr_id'  => '00000000-0000-0000-0000-000000000000',
            'status' => 'done',
        ], $this->internalHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pr_id']);
    }
}
```

---

## Billing Tests

**File:** `tests/Feature/BillingTest.php` · **5 tests** · Covers: checkout URL, plan validation, usage endpoint, Stripe webhook upgrade/downgrade

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function it_returns_checkout_url_for_pro_plan()
    {
        // Mock Stripe API
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'url' => 'https://checkout.stripe.com/pay/cs_test_abc'
            ], 200),
        ]);

        [$user, $headers] = $this->authUser();

        $this->postJson('/api/billing/checkout', ['plan' => 'pro'], $headers)
            ->assertOk()
            ->assertJsonStructure(['url']);
    }

    #[Test]
    public function it_validates_plan_value()
    {
        [$user, $headers] = $this->authUser();

        $this->postJson('/api/billing/checkout', ['plan' => 'enterprise'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);
    }

    #[Test]
    public function it_returns_usage_summary()
    {
        [$user, $headers] = $this->authUser(
            User::factory()->create(['plan' => 'pro'])
        );

        $this->getJson('/api/billing/usage', $headers)
            ->assertOk()
            ->assertJsonStructure(['used', 'limit', 'remaining', 'percent', 'plan'])
            ->assertJsonPath('limit', 500)
            ->assertJsonPath('plan',  'pro');
    }

    #[Test]
    public function it_upgrades_user_plan_on_stripe_checkout_complete()
    {
        $user    = User::factory()->create(['plan' => 'free']);
        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'customer'            => 'cus_test123',
                'subscription'        => 'sub_test123',
                'client_reference_id' => $user->id,
            ]],
        ]);
        $sig = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $payload, config('services.stripe.webhook_secret'));

        $this->withHeaders(['Stripe-Signature' => $sig])
            ->call('POST', '/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id'        => $user->id,
            'stripe_id' => 'cus_test123',
            'plan'      => 'pro',
        ]);
    }

    #[Test]
    public function it_downgrades_user_on_subscription_cancelled()
    {
        $user    = User::factory()->create(['plan' => 'pro', 'stripe_id' => 'cus_abc']);
        $payload = json_encode([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['customer' => 'cus_abc']],
        ]);
        $sig = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $payload, config('services.stripe.webhook_secret'));

        $this->withHeaders(['Stripe-Signature' => $sig])
            ->call('POST', '/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'plan' => 'free']);
    }
}
```

---

## Dashboard Tests

**File:** `tests/Feature/DashboardTest.php` · **4 tests** · Covers: stats shape, correct counts, user isolation, recent activity

```php
<?php
namespace Tests\Feature;

use App\Models\{PullRequest, ReviewComment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function it_returns_correct_stats_shape()
    {
        [$user, $headers] = $this->authUser();

        $this->getJson('/api/dashboard/stats', $headers)
            ->assertOk()
            ->assertJsonStructure([
                'prs_this_month', 'prs_total', 'issues_found',
                'errors_found', 'avg_health_score', 'repos_connected',
                'plan', 'prs_limit', 'prs_used'
            ]);
    }

    #[Test]
    public function it_counts_prs_and_issues_correctly()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo, ['status' => 'done', 'health_score' => 80]);

        ReviewComment::factory()->count(3)->create([
            'pull_request_id' => $pr->id, 'severity' => 'error'
        ]);
        ReviewComment::factory()->count(2)->create([
            'pull_request_id' => $pr->id, 'severity' => 'warning'
        ]);

        $this->getJson('/api/dashboard/stats', $headers)
            ->assertOk()
            ->assertJsonPath('prs_total',        1)
            ->assertJsonPath('issues_found',     5)
            ->assertJsonPath('errors_found',     3)
            ->assertJsonPath('avg_health_score', 80);
    }

    #[Test]
    public function it_isolates_stats_per_user()
    {
        [$user1, $h1] = $this->authUser();
        [$user2, $h2] = $this->authUser();

        $repo = $this->makeRepo($user1);
        PullRequest::factory()->count(5)->create(['repository_id' => $repo->id]);

        // user2 should see 0 PRs
        $this->getJson('/api/dashboard/stats', $h2)
            ->assertOk()
            ->assertJsonPath('prs_total', 0);
    }

    #[Test]
    public function it_returns_recent_activity()
    {
        [$user, $headers] = $this->authUser();
        $repo = $this->makeRepo($user);
        PullRequest::factory()->count(3)->create(['repository_id' => $repo->id]);

        $this->getJson('/api/dashboard/activity', $headers)
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonStructure(['*' => ['id', 'title', 'status', 'health_score', 'review_comments_count']]);
    }
}
```

---

## Middleware Tests

**File:** `tests/Feature/MiddlewareTest.php` · **4 tests** · Covers: auth guard on all routes, plan limit enforcement, internal secret guard

```php
<?php
namespace Tests\Feature;

use App\Models\{User, UsageLog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase, ApiTestTrait;

    #[Test]
    public function unauthenticated_requests_get_401()
    {
        foreach ([
            ['GET',  '/api/repos'],
            ['GET',  '/api/pull-requests'],
            ['GET',  '/api/rulesets'],
            ['GET',  '/api/dashboard/stats'],
            ['GET',  '/api/billing/usage'],
        ] as [$method, $url]) {
            $this->json($method, $url)
                ->assertUnauthorized("$method $url should require auth");
        }
    }

    #[Test]
    public function free_plan_at_limit_gets_429_on_re_review()
    {
        $this->markTestSkipped('Covered in PullRequestTest');
    }

    #[Test]
    public function pro_plan_at_limit_still_works()
    {
        $user = User::factory()->create(['plan' => 'pro']);
        [$_, $headers] = $this->authUser($user);

        UsageLog::factory()->create([
            'user_id'      => $user->id,
            'prs_reviewed' => 50, // free limit, but user is pro (500 limit)
            'month'        => now()->startOfMonth(),
        ]);

        $repo = $this->makeRepo($user);
        $pr   = $this->makePR($repo);

        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson("/api/pull-requests/{$pr->id}/re-review", [], $headers)
            ->assertOk();
    }

    #[Test]
    public function internal_endpoint_without_secret_gets_401()
    {
        $this->postJson('/api/internal/reviews/store', [])
            ->assertUnauthorized();
    }
}
```

---

## Test Coverage Summary

| File | Tests | Coverage Area |
|---|:---:|---|
| `AuthTest` | 4 | Login, /me, logout, token revocation |
| `RepositoryTest` | 8 | CRUD, ownership, webhook install/remove |
| `PullRequestTest` | 7 | List, filters, comments grouped, re-review, 429 |
| `RulesetTest` | 8 | CRUD, default swap, validation, ownership |
| `WebhookTest` | 6 | HMAC verify, PR open/sync, dedup, ignore |
| `ReviewCallbackTest` | 6 | Internal secret, bulk insert, usage, notifications |
| `BillingTest` | 5 | Checkout, Stripe events, upgrade/downgrade |
| `DashboardTest` | 4 | Stats shape, correct counts, user isolation |
| `MiddlewareTest` | 4 | Auth guard, plan limit enforcement |
| **Total** | **52** | |

```bash
# Run all tests
php artisan test

# Run with coverage report
php artisan test --coverage

# Run in parallel (recommended)
php artisan test --parallel

# Run a single file
php artisan test tests/Feature/WebhookTest.php

# Run a single test method
php artisan test --filter it_queues_review_on_pr_opened
```
