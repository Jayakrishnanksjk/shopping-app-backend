<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardTransaction extends Model
{
    use HasFactory;

    protected $table = 'reward_transactions';

    const TYPE_EARN = 'EARN';
    const TYPE_REVERSAL = 'REVERSAL';

    protected $fillable = [
        'customer_id',
        'wallet_id',
        'order_id',
        'transaction_type',
        'points',
        'eligible_amount',
        'description',
        'reference_id',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'wallet_id' => 'integer',
        'order_id' => 'integer',
        'points' => 'integer',
        'eligible_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CustomerRewardWallet::class, 'wallet_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}

