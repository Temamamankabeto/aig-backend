<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RecipePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'recipes.read'); }
    public function view(User $user, Recipe $model): bool { return $this->allows($user, 'recipes.read'); }
    public function create(User $user): bool { return $this->allows($user, 'recipes.create'); }
    public function update(User $user, Recipe $model): bool { return $this->allows($user, 'recipes.update'); }
}
