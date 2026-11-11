<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerRewardWallet extends Model
{
    use HasFactory;

    protected $table = 'customer_reward_wallets';

    protected $fillable = [
        'customer_id',
        'total_points',
        'lifetime_points',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'total_points' => 'integer',
        'lifetime_points' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RewardTransaction::class, 'wallet_id')->orderBy('id', 'desc');
    }
}

