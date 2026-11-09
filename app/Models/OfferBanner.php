<?php

namespace App\Models;

use App\Helpers\Helpers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferBanner extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'banner_image_id',
        'redirect_type',
        'redirect_id',
        'store_id',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
        'banner_image_id' => 'integer',
        'redirect_id' => 'integer',
        'store_id' => 'integer',
    ];

    protected $with = ['banner_image'];

    public static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (!$model->store_id && Helpers::isUserLogin() && Helpers::getCurrentRoleName() == \App\Enums\RoleEnum::VENDOR) {
                $model->store_id = Helpers::getCurrentVendorStoreId();
            }
            if (!$model->store_id && request()) {
                $headerStoreId = request()->header('X-Store-Id') ?: request()->input('store_id');
                if ($headerStoreId) {
                    $model->store_id = (int) $headerStoreId;
                }
            }
        });
    }

    public function banner_image()
    {
        return $this->belongsTo(Attachment::class, 'banner_image_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function redirect_product()
    {
        return $this->belongsTo(Product::class, 'redirect_id');
    }

    public function redirect_category()
    {
        return $this->belongsTo(Category::class, 'redirect_id');
    }
}
