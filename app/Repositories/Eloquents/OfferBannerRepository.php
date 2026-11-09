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
            $rawStoreId = $request->store_id ?: $request->header('X-Store-Id');
            $store_id = ($rawStoreId !== null && $rawStoreId !== '') ? (int) $rawStoreId : null;
            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $store_id = Helpers::getCurrentVendorStoreId() ?? $store_id;
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
            if (is_object($request)) {
                $data = $request->all();
                if ($request->has('store_id')) {
                    $data['store_id'] = ($request->store_id !== '' && $request->store_id !== null) ? (int) $request->store_id : null;
                }
            } else {
                $data = $request;
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
            $offerBanner->update(['status' => $status]);

            return $offerBanner;
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function deleteAll($ids)
    {
        try {
            return $this->model->whereIn('id', $ids)->delete();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
