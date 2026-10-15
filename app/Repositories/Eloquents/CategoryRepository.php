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

            $store_id = $request->store_id;
            if (Helpers::isUserLogin() && Helpers::getCurrentRoleName() == RoleEnum::VENDOR) {
                $store_id = Helpers::getCurrentVendorStoreId() ?? $request->store_id;
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
            $category->update($request);

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
