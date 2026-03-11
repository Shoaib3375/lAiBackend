<?php
namespace App\Policies;

use App\Models\{User, Repository};

class RepositoryPolicy
{
    public function view(User $user, Repository $repo): bool {
        return $repo->user_id === $user->id;
    }
    public function update(User $user, Repository $repo): bool {
        return $repo->user_id === $user->id;
    }
    public function delete(User $user, Repository $repo): bool {
        return $repo->user_id === $user->id;
    }
}
