<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductDeletionGuard;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canManage($user)
            || ($user->role === UserRole::SalesRep && $product->is_active);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManage($user);
    }

    public function adjustStock(User $user, Product $product): bool
    {
        return $this->canManage($user) && $product->is_active;
    }

    /**
     * Archiving is the ordinary way to retire a product, so it follows the same permission as any
     * other inventory management action.
     */
    public function archive(User $user, Product $product): bool
    {
        return $this->canManage($user) && $product->is_active;
    }

    public function reactivate(User $user, Product $product): bool
    {
        return $this->canManage($user) && ! $product->is_active;
    }

    /**
     * Permanent deletion is an Administrator-only exception, and only for a product nothing in the
     * business record refers to. The history check lives in ProductDeletionGuard; this decides the
     * role question alone, and the controller applies both.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->role === UserRole::Admin && app(ProductDeletionGuard::class)->isDeletable($product);
    }

    public function viewCost(User $user, Product $product): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
