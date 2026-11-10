<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\Category;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use App\Imports\CategoryImport;
use App\Exports\CategoriesExport;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class CategoryRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'name' => 'like',
        'subcategories.name' => 'like'
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
       return Category::class;
    }

    public function show($id)
    {
        try {

            return $this->model->with('subcategories.parent')->findOrFail($id);

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

            $category =  $this->model->create([
                'name' => $request->name,
                'description' => $request->description,
                'type' => $request->type,
                'status' => $request->status,
                'category_image_id' => $request->category_image_id,
                'category_icon_id'   => $request->category_icon_id,
                'commission_rate' => $request->commission_rate,
                'parent_id' => $request->parent_id,
                'store_id' => $store_id,
            ]);

            $category->category_image;
            $category->category_icon;

            DB::commit();
            return $category;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {

            $category = $this->model->findOrFail($id);
            $data = is_object($request) ? $request->all() : $request;

            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $data['store_id'] = Helpers::getCurrentVendorStoreId() ?: ($category->store_id ?: null);
            } elseif (array_key_exists('store_id', $data)) {
                $data['store_id'] = ($data['store_id'] !== '' && $data['store_id'] !== null) ? (int) $data['store_id'] : null;
            }

            $category->update($data);

            if (isset($request['category_image_id'])) {
                $category->category_image()->associate($request['category_image_id']);
            }

            if (isset($request['category_icon_id'])) {
                $category->category_icon()->associate($request['category_icon_id']);
            }

            $category->category_image;
            $category->category_icon;

            DB::commit();
            return $category;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function status($id, $status)
    {
        try {

            $category = $this->model->findOrFail($id);
            if (!Helpers::canManageRecord($category)) {
                throw new Exception('You are not allowed to manage records of another store.', 403);
            }

            $category->update(['status' => $status]);

            return $category;

        } catch (Exception $e) {

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

    public function import()
    {
        DB::beginTransaction();
        try {

            $categoryImport = new CategoryImport();
            Excel::import($categoryImport, request()->file('categories'));
            DB::commit();

            return $categoryImport->getImportedCategories();

        } catch (Exception $e){

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function getCategoriesExportUrl()
    {
        try {

            return route('categories.export');

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function export()
    {
        try {

            return Excel::download(new CategoriesExport, 'categories.csv');

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
