<?php
namespace App\Policies;

use App\Models\{User, Ruleset};

class RulesetPolicy
{
    public function view(User $user, Ruleset $ruleset): bool {
        return $ruleset->user_id === $user->id;
    }
    public function update(User $user, Ruleset $ruleset): bool {
        return $ruleset->user_id === $user->id;
    }
    public function delete(User $user, Ruleset $ruleset): bool {
        return $ruleset->user_id === $user->id;
    }
}
