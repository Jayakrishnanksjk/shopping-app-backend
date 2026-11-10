<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Coupon;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use App\Http\Requests\CreateCouponRequest;
use App\Http\Requests\UpdateCouponRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\CouponRepository;

class CouponController extends Controller
{
    public $repository;

    public function __construct(CouponRepository $repository)
    {
        $this->authorizeResource(Coupon::class, 'coupon', [
            'except' => [ 'index', 'show' ],
        ]);

        return $this->repository = $repository;
    }

    
    public function index(Request $request)
    {
        try {

            $coupons = $this->filter($this->repository, $request);
            return $coupons->latest('created_at')->paginate($request->paginate ?? $coupons->count());

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    
    public function store(CreateCouponRequest $request)
    {
        return $this->repository->store($request);
    }

    
    public function show(Coupon $coupon)
    {
        return $this->repository->show($coupon->id);
    }

    
    public function update(UpdateCouponRequest $request, Coupon $coupon)
    {
        return $this->repository->update($request->all(), $coupon->getId($request));
    }

    
    public function destroy(Request $request, Coupon $coupon)
    {
        return $this->repository->destroy($coupon->getId($request));
    }

    
    public function status($id, $status)
    {
        return $this->repository->status($id, $status);
    }

    
    public function deleteAll(Request $request)
    {
        return $this->repository->deleteAll($request->ids);
    }

    public function filter($coupons, $request)
    {
        if (Helpers::isUserLogin()) {
            $roleName = Helpers::getCurrentRoleName();
            if ($roleName == RoleEnum::VENDOR) {
                $coupons = $coupons->where('created_by_id', Helpers::getCurrentUserId());
            }
        }

        if ($request->field && $request->sort) {
            $coupons = $coupons->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $coupons = $coupons->where('status',$request->status);
        }

        return $coupons;
    }
}
