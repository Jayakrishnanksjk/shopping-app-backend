<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Product;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use App\Enums\SortByEnum;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\CreateProductRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\ProductRepository;

class ProductController extends Controller
{
    public $repository;

    public function __construct(ProductRepository $repository)
    {
        $this->authorizeResource(Product::class,'product', [
            'except' => [ 'index', 'show' ],
        ]);

        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *      path="/product",
     *      operationId="getProducts",
     *      tags={"Products"},
     *      summary="Get list of products",
     *      description="Returns paginated list of products with optional filters",
     *      @OA\Parameter(name="paginate", in="query", description="Number of items per page", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="category", in="query", description="Filter by category slug(s), comma-separated", @OA\Schema(type="string")),
     *      @OA\Parameter(name="tag", in="query", description="Filter by tag slug(s), comma-separated", @OA\Schema(type="string")),
     *      @OA\Parameter(name="min", in="query", description="Minimum price filter", @OA\Schema(type="number")),
     *      @OA\Parameter(name="max", in="query", description="Maximum price filter", @OA\Schema(type="number")),
     *      @OA\Parameter(name="rating", in="query", description="Filter by rating(s), comma-separated", @OA\Schema(type="string")),
     *      @OA\Parameter(name="sortBy", in="query", description="Sort by: asc, desc, a-z, z-a, high-to-low, low-to-high", @OA\Schema(type="string")),
     *      @OA\Parameter(name="store_id", in="query", description="Filter by store ID", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="status", in="query", description="Filter by status", @OA\Schema(type="boolean")),
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=400, description="Bad request")
     * )
     */
    public function index(Request $request)
    {
        try {

            $product = $this->filter($this->repository, $request);
            return $product->latest('created_at')->paginate($request->paginate ?? $product->count());

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * @OA\Post(
     *      path="/product",
     *      operationId="storeProduct",
     *      tags={"Products"},
     *      summary="Create a new product",
     *      description="Create a new product (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"name","price","store_id"},
     *              @OA\Property(property="name", type="string", example="Product Name"),
     *              @OA\Property(property="description", type="string"),
     *              @OA\Property(property="price", type="number"),
     *              @OA\Property(property="sale_price", type="number"),
     *              @OA\Property(property="store_id", type="integer"),
     *              @OA\Property(property="category_ids", type="array", @OA\Items(type="integer")),
     *              @OA\Property(property="tag_ids", type="array", @OA\Items(type="integer")),
     *              @OA\Property(property="cost", type="number", example=15.50)
     *          )
     *      ),
     *      @OA\Response(response=201, description="Product created successfully"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(CreateProductRequest $request)
    {
        return $this->repository->store($request);
    }

    /**
     * Get product by ID (internal use, not exposed in Swagger).
     */
    public function show(Product $product)
    {
        return $this->repository->show($product->id);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update product by ID (internal use, not exposed in Swagger).
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        return $this->repository->update($request->all(), $product->getId($request));
    }

    /**
     * Delete product by ID (internal use, not exposed in Swagger).
     */
    public function destroy(Request $request, Product $product)
    {
        return $this->repository->destroy($product->getId($request));
    }

    /**
     * Update product status by ID (internal use, not exposed in Swagger).
     */
    public function status($id, $status)
    {
        return $this->repository->status($id, $status);
    }

    /**
     * Approve product by ID (internal use, not exposed in Swagger).
     */
    public function approve($id, $status)
    {
        return $this->repository->approve($id, $status);
    }


    public function import()
    {
        return $this->repository->import();
    }

    public function getProductsExportUrl(Request $request)
    {
        return $this->repository->getProductsExportUrl($request);
    }

    public function export()
    {
        return $this->repository->export();
    }




    /**
     * @OA\Get(
     *      path="/product/slug/{slug}",
     *      operationId="getProductBySlug",
     *      tags={"Products"},
     *      summary="Get product by slug",
     *      description="Returns a single product by its slug",
     *      @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=404, description="Product not found")
     * )
     */
    public function getProductBySlug($slug)
    {
        return $this->repository->getProductBySlug($slug);
    }

    public function getProductByRating($ratings, $product)
    {
        return $product->where(function($query) use ($ratings) {
            foreach ($ratings as $rating) {
                $query->orWhere(function($query) use ($rating) {
                    $query->whereHas('reviews', function($query) use ($rating) {
                        $query->select('product_id')
                            ->groupBy('product_id')
                            ->havingRaw('AVG(rating) >= ?', [$rating])
                            ->havingRaw('AVG(rating) < ?', [$rating + 1]);
                    });
                });
            }
        });
    }

    public function filter($product, $request)
    {
        if ($request->top_selling && $request->filter_by) {
            $product = Helpers::getTopSellingProducts($this->repository);
        }

        $roleName = null;
        if (Helpers::isUserLogin()) {
            $roleName = Helpers::getCurrentRoleName();
            if ($roleName == RoleEnum::VENDOR) {
                $product = $product->where('store_id', Helpers::getCurrentVendorStoreId());
            }
        }

        if ($roleName !== RoleEnum::ADMIN && $roleName !== RoleEnum::VENDOR) {
            $product = $product->where('status', 1)->where('is_approved', 1);
        }

        if ($request->rating) {
            $ratings = explode(',', $request->rating);
            $product = $this->getProductByRating($ratings, $product);
        }

        if (isset($request->trending)) {
            if ($request->trending) {
                $product = $product->whereHas('trending_product');
            } else {
                $product = $product->whereDoesntHave('trending_product');
            }
        }

        if (isset($request->is_trending)) {
            if ($request->is_trending) {
                $product = $product->whereHas('trending_product');
            } else {
                $product = $product->whereDoesntHave('trending_product');
            }
        }

        if (isset($request->is_featured)) {
            if ($request->is_featured) {
                $product = $product->whereHas('featured_product');
            } else {
                $product = $product->whereDoesntHave('featured_product');
            }
        }

        if (isset($request->bestselling)) {
            if ($request->bestselling) {
                $product = $product->whereHas('bestselling_product');
            } else {
                $product = $product->whereDoesntHave('bestselling_product');
            }
        }

        if (isset($request->out_of_stock)) {
            if (filter_var($request->out_of_stock, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $request->out_of_stock) {
                $product = $product->where(function ($q) {
                    $q->where('stock_status', \App\Enums\StockStatus::OUT_OF_STOCK)
                      ->orWhere('quantity', '<=', 0);
                });
            } else {
                $product = $product->where('stock_status', '!=', \App\Enums\StockStatus::OUT_OF_STOCK)->where('quantity', '>', 0);
            }
        }

        if ($request->filled('stock_status')) {
            $product = $product->where('stock_status', $request->stock_status);
        }

        if ($request->ids) {
            $ids = explode(',',$request->ids);
            $with_union_products = (boolean) $request->with_union_products;
            if ($with_union_products) {
                $limit = intval($request->paginate) - count($ids);
                if ($limit > 0) {
                    $unionIds = Product::whereNotIn('id', $ids)->where('status', 1)->where('is_approved', 1)->inRandomOrder()->take($limit)->pluck('id')->toArray();
                    $allIds = array_merge($ids, $unionIds);
                    $product = $product->whereIn('id', $allIds);
                } else {
                    $product = $product->whereIn('id', $ids);
                }
            } else {
                $product = $product->whereIn('id', $ids);
            }
        }

        if (isset($request->min) && isset($request->max)) {
            $product = $product->whereBetween('sale_price', [$request->min, $request->max]);
        }

        if ($request->category) {
            $slugs = explode(',', $request->category);
            $product = $product->whereHas('categories', function (Builder $categories) use($slugs) {
                $categories->WhereIn('slug', $slugs);
            });
        }

        if ($request->tag) {
            $slugs = explode(',', $request->tag);
            $product = $product->whereHas('tags', function (Builder $tags) use($slugs){
                $tags->WhereIn('slug', $slugs);
            });
        }

        if ($request->field && $request->sort) {
            $product = $product->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $product = $product->where('status',$request->status);
        }

        if ($request->sortBy) {
            if (isset($request->field) && ($request->sortBy == SortByEnum::ASC || $request->sortBy == SortByEnum::DESC)) $product->orderBy($request->field, $request->sortBy);
            if ($request->sortBy == SortByEnum::ATOZ) $product->orderBy('name');
            if ($request->sortBy == SortByEnum::ZTOA) $product->orderBy('name', SortByEnum::DESC);
            if ($request->sortBy == SortByEnum::HIGH_TO_LOW) $product->orderBy('sale_price', SortByEnum::DESC);
            if ($request->sortBy == SortByEnum::LOW_TO_HIGH) $product->orderBy('sale_price');
            if ($request->sortBy == SortByEnum::DISCOUNT_HIGH_TO_LOW) $product->orderBy('discount', SortByEnum::DESC);
        }

        if ($request->store_id) {
            $product = $product->where('store_id',$request->store_id);
        }

        if ($request->store_slug) {
            $slug = $request->store_slug;
            $product = $product->whereHas('store', function (Builder $stores) use($slug) {
                $stores->where('slug', $slug);
            });
        }

        if ($request->attribute) {
            $slugs = explode(',', $request->attribute);
            $product = $product->whereHas('variations', function (Builder $attributes) use($slugs) {
                $attributes->whereHas('attribute_values', function (Builder $attributeValues) use ($slugs) {
                    $attributeValues->WhereIn('slug', $slugs);
                });
            });
        }

        if ($request->price) {
            $ranges = explode(',', $request->price);
            foreach($ranges as $range) {
                $values = explode('-', $range);
                if (count($values) > 1) {
                    $min = head($values);
                    $max = last($values);
                    $product = $product->whereBetween('sale_price', [$min, $max]);

                } else {
                    $max = head($values);
                    $product = $product->where('sale_price', '>=', $max);
                }
            }
        }

        if ($request->store_ids) {
            $store_ids = explode(',', $request->store_ids);
            $product = $product->whereIn('store_id', $store_ids);
        }

        if ($request->category_ids) {
            $category_ids = explode(',', $request->category_ids);
            $product = $product->whereRelation('categories', function($categories) use($category_ids) {
                $categories->WhereIn('category_id', $category_ids);
            });
        }

        if ($request->tag_ids) {
            $tag_ids = explode(',', $request->tag_ids);
            $product = $product->whereRelation('tags', function($tags) use ($tag_ids) {
                $tags->WhereIn('tag_id', $tag_ids);
            });
        }

        return $product->with([
            'store:id,store_name',
            'product_thumbnail:id,name,disk,file_name,custom_properties',
            'product_galleries:id,name,disk,file_name,custom_properties',
            'attributes',
            'variations'
        ]);
    }

    /**
     * @OA\Get(
     *      path="/product/barcode/{barcode}",
     *      operationId="getProductByBarcode",
     *      tags={"Products"},
     *      summary="Get product or variation by barcode",
     *      description="Returns variation details if the barcode matches a variation, otherwise returns product details.",
     *      @OA\Parameter(
     *          name="barcode",
     *          in="path",
     *          required=true,
     *          @OA\Schema(type="string", example="1234567890123")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful lookup",
     *          @OA\JsonContent(
     *              type="object",
     *              description="Returns either a Product or Variation object"
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="No product or variation found with this barcode"
     *      )
     * )
     */
    public function showByBarcode($barcode)
    {
        return $this->repository->getProductByBarcode($barcode);
    }

    /**
     * @OA\Put(
     *      path="/product/barcode/{barcode}",
     *      operationId="updateProductByBarcode",
     *      tags={"Products"},
     *      summary="Update a product by barcode",
     *      description="Look up a product or variation by barcode and update it (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(
     *          name="barcode",
     *          in="path",
     *          required=true,
     *          @OA\Schema(type="string", example="1234567890123")
     *      ),
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="name", type="string"),
     *          @OA\Property(property="description", type="string"),
     *          @OA\Property(property="price", type="number"),
     *          @OA\Property(property="sale_price", type="number"),
     *          @OA\Property(property="cost", type="number"),
     *          @OA\Property(property="status", type="boolean")
     *      )),
     *      @OA\Response(response=200, description="Product updated successfully"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=404, description="No product or variation found with this barcode")
     * )
     */
    public function updateByBarcode(Request $request, $barcode)
    {
        try {
            $id = $this->repository->resolveProductIdByBarcode($barcode);
            return $this->repository->update($request->all(), $id);

        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @OA\Delete(
     *      path="/product/barcode/{barcode}",
     *      operationId="deleteProductByBarcode",
     *      tags={"Products"},
     *      summary="Delete a product by barcode",
     *      description="Look up a product by barcode and delete it (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="barcode", in="path", required=true, @OA\Schema(type="string", example="1234567890123")),
     *      @OA\Response(response=200, description="Deleted"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=404, description="No product or variation found with this barcode")
     * )
     */
    public function destroyByBarcode($barcode)
    {
        try {
            $id = $this->repository->resolveProductIdByBarcode($barcode);
            return $this->repository->destroy($id);

        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @OA\Put(
     *      path="/product/barcode/{barcode}/{status}",
     *      operationId="updateProductStatusByBarcode",
     *      tags={"Products"},
     *      summary="Toggle product status by barcode (Admin)",
     *      description="Look up a product by barcode and toggle its status",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="barcode", in="path", required=true, @OA\Schema(type="string", example="1234567890123")),
     *      @OA\Parameter(name="status", in="path", required=true, @OA\Schema(type="integer", enum={0, 1})),
     *      @OA\Response(response=200, description="Status updated"),
     *      @OA\Response(response=404, description="No product found with this barcode")
     * )
     */
    public function statusByBarcode($barcode, $status)
    {
        try {
            $id = $this->repository->resolveProductIdByBarcode($barcode);
            return $this->repository->status($id, $status);

        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @OA\Put(
     *      path="/product/approve/barcode/{barcode}/{status}",
     *      operationId="approveProductByBarcode",
     *      tags={"Products"},
     *      summary="Approve or reject a product by barcode (Admin)",
     *      description="Look up a product by barcode and approve or reject it",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="barcode", in="path", required=true, @OA\Schema(type="string", example="1234567890123")),
     *      @OA\Parameter(name="status", in="path", required=true, @OA\Schema(type="integer", enum={0, 1})),
     *      @OA\Response(response=200, description="Approval updated"),
     *      @OA\Response(response=404, description="No product found with this barcode")
     * )
     */
    public function approveByBarcode($barcode, $status)
    {
        try {
            $id = $this->repository->resolveProductIdByBarcode($barcode);
            return $this->repository->approve($id, $status);

        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @OA\Post(
     *      path="/product/deleteAll",
     *      operationId="deleteAllProducts",
     *      tags={"Products"},
     *      summary="Bulk delete products",
     *      description="Delete multiple products by IDs or barcodes",
     *      security={{"sanctum":{}}},
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="ids", type="array", @OA\Items(type="integer"), description="Product IDs to delete"),
     *          @OA\Property(property="barcodes", type="array", @OA\Items(type="string"), description="Product barcodes to delete")
     *      )),
     *      @OA\Response(response=200, description="Deleted"),
     *      @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function deleteAll(Request $request)
    {
        if ($request->barcodes) {
            return $this->repository->deleteAllByBarcodes($request->barcodes);
        }

        return $this->repository->deleteAll($request->ids);
    }

    /**
     * @OA\Post(
     *      path="/product/replicate",
     *      operationId="replicateProduct",
     *      tags={"Products"},
     *      summary="Duplicate product(s)",
     *      description="Replicate products by IDs or barcodes",
     *      security={{"sanctum":{}}},
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="ids", type="array", @OA\Items(type="integer"), description="Product IDs to replicate"),
     *          @OA\Property(property="barcodes", type="array", @OA\Items(type="string"), description="Product barcodes to replicate")
     *      )),
     *      @OA\Response(response=200, description="Products replicated"),
     *      @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function replicate(Request $request)
    {
        if ($request->barcodes) {
            return $this->repository->replicateByBarcodes($request->barcodes);
        }

        return $this->repository->replicate($request->ids);
    }
}
