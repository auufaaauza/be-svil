<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;
use App\Role;

class MaterialPolicy
{
    public function view(User $user, Material $material): bool
    {
        return Material::query()->visibleTo($user)->whereKey($material->id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [Role::Teacher, Role::Admin], true);
    }

    public function update(User $user, Material $material): bool
    {
        return $user->role === Role::Admin || ($user->role === Role::Teacher && $material->teacher_id === $user->id && $user->canAccessClassroom($material->classroom_id));
    }
}
