<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Category;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Http\Requests\CreateCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Repositories\Eloquents\CategoryRepository;

class CategoryController extends Controller
{
    public $repository;

    public function __construct(CategoryRepository $repository)
    {
        $this->authorizeResource(Category::class, 'category', [
            'except' => [ 'index', 'show' ],
        ]);

        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *      path="/category",
     *      operationId="getCategories",
     *      tags={"Categories"},
     *      summary="Get list of categories",
     *      description="Returns paginated list of categories with subcategories",
     *      @OA\Parameter(name="paginate", in="query", description="Number of items per page", @OA\Schema(type="integer")),
     *      @OA\Parameter(name="type", in="query", description="Filter by category type", @OA\Schema(type="string")),
     *      @OA\Parameter(name="status", in="query", description="Filter by status", @OA\Schema(type="boolean")),
     *      @OA\Parameter(name="ids", in="query", description="Filter by IDs, comma-separated", @OA\Schema(type="string")),
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=400, description="Bad request")
     * )
     */
    public function index(Request $request)
    {
        try {

            $categories = $this->repository->whereNull('parent_id')->with('subcategories', 'parent');
            $categories = $this->filter($categories, $request);
            return $categories->latest('created_at')->paginate($request->paginate ?? $categories->count());

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
     *      path="/category",
     *      operationId="storeCategory",
     *      tags={"Categories"},
     *      summary="Create a new category",
     *      description="Create a new category (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          required={"name"},
     *          @OA\Property(property="name", type="string", example="Electronics"),
     *          @OA\Property(property="description", type="string", example="Category for electronic items"),
     *          @OA\Property(property="type", type="string", example="product"),
     *          @OA\Property(property="parent_id", type="integer", example=2),
     *          @OA\Property(property="category_image_id", type="integer", example=0),
     *          @OA\Property(property="status", type="integer", example=1)
     *      )),
     *      @OA\Response(response=201, description="Category created successfully"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(CreateCategoryRequest $request)
    {
        return $this->repository->store($request);
    }

    /**
     * @OA\Get(
     *      path="/category/{id}",
     *      operationId="getCategoryById",
     *      tags={"Categories"},
     *      summary="Get category by ID",
     *      description="Returns a single category with subcategories",
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=404, description="Category not found")
     * )
     */
    public function show(Category $category)
    {
        return $this->repository->show($category->id);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * @OA\Put(
     *      path="/category/{id}",
     *      operationId="updateCategory",
     *      tags={"Categories"},
     *      summary="Update an existing category",
     *      description="Update category details (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="name", type="string", example="Electronics"),
     *          @OA\Property(property="description", type="string", example="Category for electronic items"),
     *          @OA\Property(property="type", type="string", example="product"),
     *          @OA\Property(property="parent_id", type="integer", example=2),
     *          @OA\Property(property="category_image_id", type="integer", example=0),
     *          @OA\Property(property="status", type="integer", example=1)
     *      )),
     *      @OA\Response(response=200, description="Category updated successfully"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=404, description="Category not found")
     * )
     */
    public function update(UpdateCategoryRequest $request, Category $category)
    {
        return $this->repository->update($request->all(), $category->getId($request));
    }

    /**
     * Update Status the specified resource from storage.
     *
     * @param  int  $id
     * @param int $status
     * @return \Illuminate\Http\Response
     */
    public function status($id, $status)
    {
        return $this->repository->status($id, $status);
    }

    /**
     * @OA\Delete(
     *      path="/category/{id}",
     *      operationId="deleteCategory",
     *      tags={"Categories"},
     *      summary="Delete a category",
     *      description="Delete a category (requires authentication)",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Category deleted successfully"),
     *      @OA\Response(response=401, description="Unauthenticated"),
     *      @OA\Response(response=404, description="Category not found")
     * )
     */
    public function destroy(Request $request, Category $category)
    {
       return $this->repository->destroy($category->getId($request));
    }

    public function getCategoriesExportUrl(Request $request)
    {
        return $this->repository->getCategoriesExportUrl($request);
    }

    public function import()
    {
        return $this->repository->import();
    }

    public function export()
    {
        return $this->repository->export();
    }

    public function filter($categories, $request)
    {
        if (Helpers::isUserLogin()) {
            $roleName = Helpers::getCurrentRoleName();
            if ($roleName == RoleEnum::VENDOR) {
                $vendorStoreId = Helpers::getCurrentVendorStoreId();
                if ($vendorStoreId) {
                    $categories = $categories->where('store_id', $vendorStoreId);
                }
            }
        }

        if ($request->type) {
            $categories = $this->repository->whereNull('parent_id')
                ->where('type', 'like', $request->type)
                ->with(['subcategories']);
        }

        if ($request->ids) {
            $ids = explode(',',$request->ids);
            $categories = $categories->findWhereIn('id',$ids);
        }

        if ($request->field && $request->sort) {
            $categories = $categories->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $categories = $categories->where('status', $request->status);
        }

        if ($request->store_id) {
            $store_id = $request->store_id;
            $categories = $categories->where(function ($q) use ($store_id) {
                $q->where('store_id', $store_id)
                  ->orWhereHas('products', function (Builder $products) use ($store_id) {
                      $products->where('store_id', $store_id);
                  });
            });
        }

        if ($request->store_slug) {
            $store_slug = $request->store_slug;
            $categories = $categories->where(function ($q) use ($store_slug) {
                $q->whereHas('store', function (Builder $stores) use ($store_slug) {
                    $stores->where('slug', $store_slug);
                })->orWhereHas('products', function (Builder $products) use ($store_slug) {
                    $products->whereHas('store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    });
                });
            });
        }

        return $categories;
    }
}
