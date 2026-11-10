<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\OfferBanner;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class OfferBannerRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'name' => 'like',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (ExceptionHandler $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    function model()
    {
        return OfferBanner::class;
    }

    public function show($id)
    {
        try {
            return $this->model->findOrFail($id);
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function store($request)
    {
        DB::beginTransaction();
        try {
            $store_id = Helpers::getRequestedStoreId($request);
            $store_id = ($store_id && $store_id > 0) ? (int) $store_id : null;
            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $store_id = Helpers::getCurrentVendorStoreId() ?: $store_id;
            }

            $offerBanner = $this->model->create([
                'name' => $request->name,
                'banner_image_id' => $request->banner_image_id,
                'redirect_type' => $request->redirect_type,
                'redirect_id' => $request->redirect_id,
                'store_id' => $store_id,
                'status' => $request->status,
            ]);

            DB::commit();
            return $offerBanner;
        } catch (Exception $e) {
            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {
            $offerBanner = $this->model->findOrFail($id);
            $data = is_object($request) ? $request->all() : $request;

            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $data['store_id'] = Helpers::getCurrentVendorStoreId() ?: ($offerBanner->store_id ?: null);
            } elseif (array_key_exists('store_id', $data)) {
                $data['store_id'] = ($data['store_id'] !== '' && $data['store_id'] !== null) ? (int) $data['store_id'] : null;
            }

            $offerBanner->update($data);

            DB::commit();
            return $offerBanner;
        } catch (Exception $e) {
            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function destroy($id)
    {
        try {
            return $this->model->findOrFail($id)->delete();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function status($id, $status)
    {
        try {
            $offerBanner = $this->model->findOrFail($id);
            if (!Helpers::canManageRecord($offerBanner)) {
                throw new Exception('You are not allowed to manage records of another store.', 403);
            }

            $offerBanner->update(['status' => $status]);

            return $offerBanner;
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function deleteAll($ids)
    {
        try {
            $offerBanners = $this->model->whereIn('id', $ids);

            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $storeId = Helpers::getCurrentVendorStoreId();
                if (!$storeId) {
                    return 0;
                }

                $offerBanners = $offerBanners->where('store_id', $storeId);
            }

            return $offerBanners->delete();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
