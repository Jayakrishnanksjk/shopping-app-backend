<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\OfferBanner;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
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
        $storeId = Helpers::getRequestedStoreId($request);
        if ($storeId) {
            $offerBanners = Helpers::scopeToStore($offerBanners, $storeId);
        }

        if ($request->field && $request->sort) {
            $offerBanners = $offerBanners->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $offerBanners = $offerBanners->where('status', $request->status);
        }

        return $offerBanners;
    }
}
