<?php

namespace App\Http\Controllers;

use App\Enums\RoleEnum;
use App\Enums\StockStatus;
use App\Helpers\Helpers;
use App\Models\Product;
use Illuminate\Http\Request;
use App\GraphQL\Exceptions\ExceptionHandler;
use Exception;

class OutOfStockProductController extends Controller
{
    /**
     * @OA\Get(
     *      path="/out-of-stock-product",
     *      operationId="getOutOfStockProducts",
     *      tags={"Products"},
     *      summary="Get out of stock products",
     *      description="Returns paginated list of products where stock_status is out_of_stock OR quantity <= 0. Supports search, paginate, page, store_id, category_ids.",
     *      @OA\Parameter(name="paginate", in="query", description="Items per page", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="search", in="query", description="Search by name, sku or barcode", @OA\Schema(type="string")),
     *      @OA\Parameter(name="store_id", in="query", description="Filter by store ID", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="category_ids", in="query", description="Comma-separated category IDs", @OA\Schema(type="string")),
     *      @OA\Response(response=200, description="Paginated out of stock products")
     * )
     */
    public function index(Request $request)
    {
        try {
            $query = Product::query()->with(config('enums.product.with'));

            // Vendor scoping — vendors only see their own store's OOS products
            if (Helpers::isUserLogin()) {
                $roleName = Helpers::getCurrentRoleName();
                if ($roleName == RoleEnum::VENDOR) {
                    $query->where('store_id', Helpers::getCurrentVendorStoreId());
                }
                // Guests / storefront: only show active + approved OOS products.
                // Admins / vendors (logged in): show all OOS regardless of status
                // so they can restock drafts too.
                if ($roleName !== RoleEnum::ADMIN && $roleName !== RoleEnum::VENDOR) {
                    $query->where('status', 1)->where('is_approved', 1);
                }
            } else {
                $query->where('status', 1)->where('is_approved', 1);
            }

            // Core out-of-stock condition: explicit flag OR zero quantity.
            // Covers legacy rows where stock_status wasn't synced.
            $query->where(function ($q) {
                $q->where('stock_status', StockStatus::OUT_OF_STOCK)
                  ->orWhere('quantity', '<=', 0);
            });

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%")
                      ->orWhere('barcode', 'like', "%{$search}%");
                });
            }

            if ($request->filled('store_id')) {
                $query->where('store_id', $request->store_id);
            }

            if ($request->filled('category_ids')) {
                $categoryIds = explode(',', $request->category_ids);
                $query->whereRelation('categories', function ($categories) use ($categoryIds) {
                    $categories->whereIn('category_id', $categoryIds);
                });
            }

            if ($request->filled('field') && $request->filled('sort')) {
                $query->orderBy($request->field, $request->sort);
            } else {
                $query->latest('updated_at');
            }

            $perPage = $request->paginate ?? 15;

            return $query->paginate($perPage);
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
