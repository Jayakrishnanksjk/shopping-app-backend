<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Category;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
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
            'except' => [ 'index', 'show', 'reorder' ],
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

            $storeId = Helpers::getRequestedStoreId($request);

            $categories = $this->repository->whereNull('parent_id')->with([
                'subcategories' => function ($query) use ($storeId) {
                    if ($storeId) {
                        Helpers::scopeToStore($query, $storeId);
                    }
                },
                'parent',
            ]);

            $categories = $this->filter($categories, $request);
            if ($request->field && $request->sort) {
                return $categories->paginate($request->paginate ?? $categories->count());
            }

            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('categories', 'order')) {
                    $categories = $categories->orderByRaw('CASE WHEN `categories`.`order` IS NULL OR `categories`.`order` = 0 THEN 999999 ELSE `categories`.`order` END ASC, `categories`.`id` ASC');
                    return $categories->paginate($request->paginate ?? $categories->count());
                }
            } catch (\Throwable $e) {
                // Fallback gracefully if order column is not yet present in database
            }

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

    public function reorder(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Category reorder request:', $request->all());

        try {
            $vendorStoreId = (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR)
                ? Helpers::getCurrentVendorStoreId()
                : null;

            $categories = $request->input('categories');
            $categoryIds = $request->input('category_ids');

            if (!empty($categories) && is_array($categories)) {
                foreach ($categories as $index => $item) {
                    $id = is_array($item) ? ($item['id'] ?? null) : $item;
                    $order = is_array($item) ? ($item['order'] ?? ($index + 1)) : ($index + 1);
                    $updateData = ['order' => $order];
                    if (is_array($item) && array_key_exists('parent_id', $item)) {
                        $updateData['parent_id'] = $item['parent_id'];
                    }
                    if ($id) {
                        $query = Category::where('id', $id);
                        if ($vendorStoreId) {
                            $query->where('store_id', $vendorStoreId);
                        }
                        $query->update($updateData);
                    }
                }
            } elseif (!empty($categoryIds) && is_array($categoryIds)) {
                foreach ($categoryIds as $index => $id) {
                    $query = Category::where('id', $id);
                    if ($vendorStoreId) {
                        $query->where('store_id', $vendorStoreId);
                    }
                    $query->update(['order' => $index + 1]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Categories reordered successfully'
            ]);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('Category reorder error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
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
        $storeId = Helpers::getRequestedStoreId($request);
        if ($storeId) {
            $categories = Helpers::scopeToStore($categories, $storeId);
        }

        if ($request->type) {
            $categories = $categories->where('type', 'like', $request->type);
        }

        if ($request->ids) {
            $ids = explode(',', $request->ids);
            $categories = $categories->whereIn('id', $ids);
        }

        if ($request->field && $request->sort) {
            $categories = $categories->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $categories = $categories->where('status', $request->status);
        }

        return $categories;
    }
}
