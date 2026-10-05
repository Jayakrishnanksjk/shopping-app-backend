<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PearlXpProductUpdate extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED   = 'failed';

    /**
     * The PearlXpProductUpdate that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'barcode',
        'product_id',
        'old_mrp',
        'old_price',
        'old_stock',
        'new_mrp',
        'new_price',
        'new_stock',
        'status',
        'error',
        'payload',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'old_mrp'     => 'float',
        'new_mrp'     => 'float',
        'old_price'   => 'float',
        'new_price'   => 'float',
        'old_stock'   => 'integer',
        'new_stock'   => 'integer',
        'payload'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The product this update targets.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The admin who reviewed (approved/rejected) this update.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether the row is still waiting for an admin decision.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Build the products.* payload implied by the proposed values.
     *
     * MRP -> products.price, price -> products.sale_price,
     * stock -> products.quantity (stock_status is recomputed by the repository).
     *
     * @return array<string, mixed>
     */
    public function toProductAttributes(): array
    {
        $attributes = [];

        if (! is_null($this->new_mrp)) {
            $attributes['price'] = $this->new_mrp;
        }

        if (! is_null($this->new_price)) {
            $attributes['sale_price'] = $this->new_price;
        }

        if (! is_null($this->new_stock)) {
            $attributes['quantity'] = $this->new_stock;
        }

        // ProductRepository::update() reads this key unguarded, so it must be
        // present to avoid an "undefined array key" error on partial payloads.
        $attributes['is_random_related_products'] = false;

        return $attributes;
    }
}
