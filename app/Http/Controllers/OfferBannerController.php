<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\OfferBanner;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\CreateOfferBannerRequest;
use App\Http\Requests\UpdateOfferBannerRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\OfferBannerRepository;

class OfferBannerController extends Controller
{
    protected $repository;

    public function __construct(OfferBannerRepository $repository)
    {
        $this->authorizeResource(OfferBanner::class, 'offer_banner', [
            'except' => [ 'index', 'show' ],
        ]);

        $this->repository = $repository;
    }

    public function index(Request $request)
    {
        try {
            $offerBanners = $this->filter($this->repository, $request);
            return $offerBanners->latest('created_at')->paginate($request->paginate ?? $offerBanners->count());
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function store(CreateOfferBannerRequest $request)
    {
        return $this->repository->store($request);
    }

    public function show(OfferBanner $offerBanner)
    {
        return $this->repository->show($offerBanner->id);
    }

    public function update(UpdateOfferBannerRequest $request, OfferBanner $offerBanner)
    {
        return $this->repository->update($request->all(), $offerBanner->id);
    }

    public function destroy(Request $request, OfferBanner $offerBanner)
    {
        return $this->repository->destroy($offerBanner->id);
    }

    public function status($id, $status)
    {
        return $this->repository->status($id, $status);
    }

    public function deleteAll(Request $request)
    {
        return $this->repository->deleteAll($request->ids);
    }

    public function filter($offerBanners, $request)
    {
        if (Helpers::isUserLogin()) {
            $roleName = Helpers::getCurrentRoleName();
            if ($roleName == RoleEnum::VENDOR) {
                $vendorStoreId = Helpers::getCurrentVendorStoreId();
                if ($vendorStoreId) {
                    $offerBanners = $offerBanners->where('store_id', $vendorStoreId);
                }
            }
        }

        if ($request->field && $request->sort) {
            $offerBanners = $offerBanners->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $offerBanners = $offerBanners->where('status', $request->status);
        }

        $storeId = $request->store_id ?: $request->header('X-Store-Id');
        if ($storeId) {
            $offerBanners = $offerBanners->where(function ($q) use ($storeId) {
                $id = (int) $storeId;
                if ($id === 1 || $id === 3) {
                    $q->whereIn('store_id', [1, 3])->orWhereNull('store_id');
                } else {
                    $q->where('store_id', $id)->orWhereNull('store_id');
                }
            });
        }

        if ($request->store_slug) {
            $slug = $request->store_slug;
            $offerBanners = $offerBanners->where(function ($q) use ($slug) {
                if ($slug === 'grocery' || $slug === 'main-store') {
                    $q->whereHas('store', function (Builder $stores) {
                        $stores->whereIn('id', [1, 3]);
                    })->orWhereNull('store_id');
                } else {
                    $q->whereHas('store', function (Builder $stores) use ($slug) {
                        $stores->where('slug', $slug);
                    })->orWhereNull('store_id');
                }
            });
        }

        return $offerBanners;
    }
}
