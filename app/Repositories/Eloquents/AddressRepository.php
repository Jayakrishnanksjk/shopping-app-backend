<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\Address;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;
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

            $address = $this->model->create([
                'title' => $request->title,
                'street' => $request->street,
                'city' => $request->city,
                'country_code' => $request->country_code,
                'phone' => (string) $request->phone,
                'pincode' => $request->pincode,
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'user_id' => $request->user_id ?? Helpers::getCurrentUserId(),
                'latitude' => $lat ? (string) $lat : null,
                'longitude' => $lng ? (string) $lng : null,
                'screen_geo_coordinates' => $coordPair,
                'map_url' => $mapUrl,
            ]);

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
                $data['latitude'] = (string) $lat;
                $data['longitude'] = (string) $lng;
                $data['screen_geo_coordinates'] = "{$lat},{$lng}";
                $data['map_url'] = "https://www.google.com/maps?q={$lat},{$lng}";
            }

            $address = $this->model->findOrFail($id);
            $address->update($data);

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






