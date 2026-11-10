<?php

namespace App\Policies;

use App\Models\OfferBanner;
use App\Models\User;
use App\Enums\RoleEnum;
use Illuminate\Auth\Access\HandlesAuthorization;

class OfferBannerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user, OfferBanner $offerBanner)
    {
        //
    }

    public function create(User $user)
    {
        if ($user->can('offer_banner.create')) {
            return true;
        }
    }

    public function update(User $user, OfferBanner $offerBanner)
    {
        if ($user->can('offer_banner.edit') &&
            ($user->role->name != RoleEnum::VENDOR || $user->store?->id == $offerBanner->store_id)) {
            return true;
        }
    }

    public function delete(User $user, OfferBanner $offerBanner)
    {
        if ($user->can('offer_banner.destroy') &&
            ($user->role->name != RoleEnum::VENDOR || $user->store?->id == $offerBanner->store_id)) {
            return true;
        }
    }

    public function restore(User $user, OfferBanner $offerBanner)
    {
        //
    }

    public function forceDelete(User $user, OfferBanner $offerBanner)
    {
        //
    }
}
