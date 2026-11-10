<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\Coupon;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class CouponRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'title' => 'like',
        'code' => 'like',
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
        return Coupon::class;
    }

    public function show($id)
    {
        try {

            return $this->model->with(['products','exclude_products'])->findOrFail($id);

        } catch (Exception $e){

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

            $coupon =  $this->model->create([
                'title' => $request->title,
                'description' => $request->description,
                'code' => $request->code,
                'type' => $request->type,
                'amount'=> $request->amount,
                'min_spend' => $request->min_spend,
                'is_unlimited' => $request->is_unlimited,
                'usage_per_coupon' => $request->usage_per_coupon,
                'usage_per_customer' => $request->usage_per_customer,
                'status' => $request->status,
                'is_expired' => $request->is_expired,
                'is_apply_all' => $request->is_apply_all,
                'is_first_order'=> $request->is_first_order,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'store_id' => $store_id,
            ]);

            if (isset($request['products'])){
                $coupon->products()->attach($request['products']);
                $coupon->products;
            }

            if (isset($request['exclude_products'])){
                $coupon->exclude_products()->attach($request['exclude_products']);
                $coupon->exclude_products;
            }

            DB::commit();
            return $coupon;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {

            $coupon = $this->model->findOrFail($id);

            $data = is_object($request) ? $request->all() : $request;
            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $data['store_id'] = Helpers::getCurrentVendorStoreId() ?: ($coupon->store_id ?: null);
            } elseif (array_key_exists('store_id', $data)) {
                $data['store_id'] = ($data['store_id'] !== '' && $data['store_id'] !== null)
                    ? (int) $data['store_id']
                    : null;
            }

            $coupon->update($data);

            if (!$request['is_apply_all']) {
                $coupon->exclude_products()->sync([]);
                if (isset($request['products'])){
                    $coupon->products()->sync($request['products']);
                    $coupon->products;
                }
            }

            if ($request['is_apply_all']) {
                $coupon->products()->sync([]);
                if (isset($request['exclude_products'])){
                    $coupon->exclude_products()->sync($request['exclude_products']);
                    $coupon->exclude_products;
                }
            }

            DB::commit();
            $coupon = $coupon->fresh();

            return $coupon;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function destroy($id)
    {
        try {

            return $this->model->findOrFail($id)->destroy($id);

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function status($id, $status)
    {
        try {

            $coupon = $this->model->findOrFail($id);
            if (!Helpers::canManageRecord($coupon)) {
                throw new Exception('You are not allowed to manage records of another store.', 403);
            }

            $coupon->update(['status' => $status]);

            return $coupon;

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function deleteAll($ids)
    {
        try {

            $coupons = $this->model->whereIn('id', $ids);

            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $storeId = Helpers::getCurrentVendorStoreId();
                if (!$storeId) {
                    return 0;
                }

                $coupons = $coupons->where(function ($query) use ($storeId) {
                    $query->where('store_id', $storeId)
                        ->orWhere(function ($query) {
                            $query->whereNull('store_id')
                                ->where('created_by_id', Helpers::getCurrentUserId());
                        });
                });
            }

            return $coupons->delete();

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
