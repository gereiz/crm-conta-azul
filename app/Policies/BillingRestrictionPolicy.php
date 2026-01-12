<?php

namespace App\Policies;

use App\Models\BillingRestriction;
use App\Models\User;

class BillingRestrictionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isOperator() || $user->isViewer();
    }

    public function view(User $user, BillingRestriction $restriction): bool
    {
        return $user->isAdmin() || $user->isOperator() || $user->isViewer();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, BillingRestriction $restriction): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, BillingRestriction $restriction): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, BillingRestriction $restriction): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, BillingRestriction $restriction): bool
    {
        return $user->isAdmin();
    }
}

