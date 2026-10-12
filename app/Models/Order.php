<?php

namespace App\Models;

use App\Helpers\Helpers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'total',
        'status',
        'amount',
        'store_id',
        'tax_total',
        'coupon_id',
        'parent_id',
        'invoice_url',
        'consumer_id',
        'order_number',
        'delivered_at',
        'points_amount',
        'created_by_id',
        'payment_status',
        "wallet_balance",
        'shipping_total',
        'payment_method',
        'order_status_id',
        'delivery_interval',
        'billing_address_id',
        'shipping_address_id',
        'delivery_description',
        'coupon_total_discount',
    ];

    protected $with = [
        'consumer:id,name,email,country_code,phone',
        'order_status:id,name,sequence,slug',
    ];

    protected $hidden = [
        'store',
        'deleted_at',
        'updated_at',
        'delivered_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'shipping_total' => 'float',
        'tax_total' => 'float',
        'total' => 'float',
        'consumer_id' => 'integer',
        'order_number' => 'integer',
        'store_id' => 'integer',
        'coupon_id' => 'integer',
        'order_status_id' => 'integer',
        'shipping_address_id' => 'integer',
        'billing_address_id' => 'integer',
        'points_amount' => 'float',
        'wallet_balance' => 'float',
        'coupon_total_discount' => 'float',
        'status' => 'integer',
        'created_by_id' => 'integer',
    ];

    public static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            $model->created_by_id = Helpers::getCurrentUserId();
        });
    }

    /**
     * @return Int
     */
    public function getId($request)
    {
        return ($request->id) ? $request->id : $request->route('order')->id;
    }

    /**
     * @return HasMany
     */
    public function sub_orders(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_id');
    }

    /**
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_id');
    }

    /**
     * @return BelongsTo
     */
    public function orderStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status');
    }

    /**
     * @return HasMany
     */
    public function order_transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class, 'order_id');
    }

    /**
     * @return BelongsTo
     */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    /**
     * @return BelongsTo
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    /**
     * @return BelongsTo
     */
    public function created_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'order_products')->using(OrderProductPivot::class)->withPivot(config('enums.order.pivot'));
    }

    /**
     * @return HasOne
     */
    public function shipping_address(): HasOne
    {
        return $this->hasOne(Address::class, 'id', 'shipping_address_id');
    }

    /**
     * @return HasOne
     */
    public function billing_address(): HasOne
    {
        return $this->hasOne(Address::class, 'id', 'billing_address_id');
    }

    /**
     * @return HasMany
     */
    public function commission_history(): HasMany
    {
        return $this->hasMany(CommissionHistory::class, 'order_id');
    }

    /**
     * @return HasOne
     */
    public function order_status(): HasOne
    {
        return $this->hasOne(OrderStatus::class, 'id', 'order_status_id');
    }

    /**
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'order_id');
    }

    /**
     * @return HasOne
     */
    public function cancellation(): HasOne
    {
        return $this->hasOne(Cancellation::class, 'order_id');
    }

    /**
     * @return HasOne
     */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class, 'order_id');
    }

}
