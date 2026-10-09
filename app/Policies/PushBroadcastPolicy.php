<?php

namespace App\Policies;

use App\Models\PushBroadcast;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PushBroadcastPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user, PushBroadcast $pushBroadcast)
    {
        //
    }

    public function create(User $user)
    {
        if ($user->can('push_broadcast.create')) {
            return true;
        }
    }

    public function update(User $user, PushBroadcast $pushBroadcast)
    {
        if ($user->can('push_broadcast.edit')) {
            return true;
        }
    }

    public function delete(User $user, PushBroadcast $pushBroadcast)
    {
        if ($user->can('push_broadcast.destroy')) {
            return true;
        }
    }

    public function restore(User $user, PushBroadcast $pushBroadcast)
    {
        //
    }

    public function forceDelete(User $user, PushBroadcast $pushBroadcast)
    {
        //
    }
}
