<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\Cart;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class CartRepository extends BaseRepository
{
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
       return Cart::class;
    }

    public function index($request)
    {
        $cartItems = $this->model->where('consumer_id', Helpers::getCurrentUserId())->latest('created_at')
            ->paginate($request->paginate ?? $this->model->count());

        $cart = $this->getCartTotal($cartItems);
        return $cart;
    }

    public function getCartTotal($cartItems)
    {
        $sub_total = [];
        $cart['items'] = [];
        foreach ($cartItems as $cartItem) {
            $cart['items'][] = $cartItem;
            $sub_total[] = $cartItem->sub_total;
            $cartItem->product;
        }

        $cart['total'] = Helpers::formatDecimal(array_sum($sub_total));
        return $cart;
    }

    public function store($request)
    {
        DB::beginTransaction();
        try {

            $cartItems[] = $this->verifyCartItem($request->all());
            $cart = $this->getCartTotal($cartItems);

            DB::commit();
            return $cart;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function update($request, $id = null)
    {

        DB::beginTransaction();
        try {

            $cart = $this->verifyCartItem($request);
            if ($cart) {
                $cartItems = $this->model->where('consumer_id', Helpers::getCurrentUserId())->get();
                $cart = $this->getCartTotal($cartItems);
            }

            DB::commit();
            return $cart;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function replace($request)
    {
        DB::beginTransaction();
        try {

            if ($this->isStockAvailable($request, $request->quantity)) {
                $cart = $this->model->findOrFail($request->id);
                $singleProductPrice = Helpers::getSalePrice($request);
                $subTotal = Helpers::getSubTotal($singleProductPrice, $request->quantity);

                $cart->update([
                    'product_id' => $request->product_id,
                    'variation_id' => $request->variation_id,
                    'quantity' => $request->quantity,
                    'sub_total' => Helpers::roundNumber($subTotal),
                ]);

                DB::commit();

                $cart = $cart->fresh();
                return $cart;
            }

            throw new Exception("You cannot add more than {$request->quantity} items.", 400);

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function isStockAvailable($product, $quantity)
    {
        $item = Helpers::getProductStock($product['product_id']);
        if (isset($product['variation_id']) && !empty($product['variation_id'])) {
            $item = Helpers::getVariationStock($product['variation_id']);
        }

        // $item is null when the product/variant is out of stock or inactive.
        if ($item && $quantity <= $item->quantity) {
            return true;
        }

        return false;
    }

    public function verifyCartItem($request)
    {
        try {

            $singleProductPrice = Helpers::getSalePrice($request);
            $subTotal = Helpers::getSubTotal($singleProductPrice, $request['quantity']);
            $cart = $this->getCartData($request);

            if ($cart) {
                $quantity = $cart->quantity + $request['quantity'];
                if (!$this->isStockAvailable($request, $quantity)) {
                    throw new Exception("You cannot add more than {$cart->quantity} items.", 400);
                }

                $cart->update([
                    'quantity' => $cart->quantity + $request['quantity'],
                    'sub_total' => Helpers::formatDecimal($cart->sub_total + $subTotal),
                ]);

            } else  {
                $cart = $this->model->create([
                    'product_id' => $request['product_id'],
                    'variation_id' => $request['variation_id'] ?? null,
                    'quantity' => $request['quantity'],
                    'sub_total' => Helpers::formatDecimal($subTotal)
                ]);
            }

            $cart->product;
            $cart->variation;

            return $cart;

        } catch (Exception $e) {

            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    public function getCartData($product)
    {
        return $this->model->where([
            ['product_id', $product['product_id']],
            ['variation_id', $product['variation_id'] ?? null],
            ['consumer_id', Helpers::getCurrentUserId()]
        ])->first();
    }

    public function destroy($id)
    {
        try {

            return $this->model->findOrFail($id)->destroy($id);

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function syncCart($request)
    {
        DB::beginTransaction();
        try {

            foreach($request->all() as $cart) {
                $cartItems[] = $this->verifyCartItem($cart);
                $cart = $this->getCartTotal($cartItems);
            }

            DB::commit();
            return $cart;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
