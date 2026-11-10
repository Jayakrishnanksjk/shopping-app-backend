<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\Address;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class AddressRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'title' => 'like',
        'street' => 'like',
        'state.name' => 'like',
        'country.name' => 'like'
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
        return Address::class;
    }

    public function show($id)
    {
        try {

            return $this->model->findOrFail($id);

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function store($request)
    {
        DB::beginTransaction();
        try {

            $tableColumns = Schema::getColumnListing('addresses');

            $lat = $request->latitude ?? $request->lat ?? $request->location_latitude ?? null;
            $lng = $request->longitude ?? $request->lng ?? $request->location_longitude ?? null;
            if ((!$lat || !$lng) && !empty($request->screen_geo_coordinates)) {
                $parts = explode(',', $request->screen_geo_coordinates);
                if (count($parts) === 2) {
                    $lat = trim($parts[0]);
                    $lng = trim($parts[1]);
                }
            }
            $coordPair = ($lat && $lng) ? "{$lat},{$lng}" : null;
            $mapUrl = $coordPair ? "https://www.google.com/maps?q={$coordPair}" : null;

            $insertData = [
                'title' => $request->title,
                'street' => $request->street,
                'city' => $request->city,
                'country_code' => $request->country_code,
                'phone' => (string) $request->phone,
                'pincode' => $request->pincode,
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'user_id' => $request->user_id ?? Helpers::getCurrentUserId(),
            ];

            if ($lat && in_array('latitude', $tableColumns)) {
                $insertData['latitude'] = (string) $lat;
            }
            if ($lng && in_array('longitude', $tableColumns)) {
                $insertData['longitude'] = (string) $lng;
            }
            if ($coordPair && in_array('screen_geo_coordinates', $tableColumns)) {
                $insertData['screen_geo_coordinates'] = $coordPair;
            }
            if ($mapUrl && in_array('map_url', $tableColumns)) {
                $insertData['map_url'] = $mapUrl;
            }

            $address = $this->model->create($insertData);

            $address->country;
            $address->state;

            DB::commit();
            return $address;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {

            $tableColumns = Schema::getColumnListing('addresses');

            $data = is_array($request) ? $request : $request->all();
            $lat = $data['latitude'] ?? $data['lat'] ?? $data['location_latitude'] ?? null;
            $lng = $data['longitude'] ?? $data['lng'] ?? $data['location_longitude'] ?? null;
            if ((!$lat || !$lng) && !empty($data['screen_geo_coordinates'])) {
                $parts = explode(',', $data['screen_geo_coordinates']);
                if (count($parts) === 2) {
                    $lat = trim($parts[0]);
                    $lng = trim($parts[1]);
                }
            }
            if ($lat && $lng) {
                if (in_array('latitude', $tableColumns)) {
                    $data['latitude'] = (string) $lat;
                }
                if (in_array('longitude', $tableColumns)) {
                    $data['longitude'] = (string) $lng;
                }
                if (in_array('screen_geo_coordinates', $tableColumns)) {
                    $data['screen_geo_coordinates'] = "{$lat},{$lng}";
                }
                if (in_array('map_url', $tableColumns)) {
                    $data['map_url'] = "https://www.google.com/maps?q={$lat},{$lng}";
                }
            }

            // Strictly filter out any attributes that do not exist as physical columns in the addresses table
            $safeData = array_intersect_key($data, array_flip($tableColumns));

            $address = $this->model->findOrFail($id);
            $address->update($safeData);

            DB::commit();
            return $address;

        } catch (Exception $e){

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
}






