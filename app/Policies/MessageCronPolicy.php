<?php

namespace App\Policies;

use App\Models\MessageCron;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessageCronPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isOperator() || $user->isViewer();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MessageCron $messageCron): bool
    {
        return $user->isAdmin() || $user->isOperator() || $user->isViewer();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MessageCron $messageCron): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MessageCron $messageCron): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MessageCron $messageCron): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MessageCron $messageCron): bool
    {
        return $user->isAdmin();
    }
}
