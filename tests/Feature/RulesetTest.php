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
