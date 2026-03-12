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
