<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProductCategory;
use App\Models\User;
use App\Policies\Concerns\DeniesOtherBusinesses;

class ProductCategoryPolicy
{
    use DeniesOtherBusinesses;

    /**
     * Reading the category management screen, as opposed to merely choosing a category while
     * working on a product. Only the roles that may change categories may open the screen that
     * changes them, so a Sales Representative is refused server-side and not merely hidden from.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, ProductCategory $category): bool
    {
        return $this->canManage($user);
    }

    /**
     * Choosing an existing category on a product form. Anyone who may work on the product itself
     * needs this, including a Sales Representative, which is why it is distinct from viewAny.
     */
    public function select(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $this->canManage($user);
    }

    public function changeStatus(User $user, ProductCategory $category): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
