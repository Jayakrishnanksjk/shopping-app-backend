<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardSetting extends Model
{
    use HasFactory;

    protected $table = 'reward_settings';

    protected $fillable = [
        'rewards_enabled',
        'purchase_threshold',
        'points_per_threshold',
        'point_conversion_value',
    ];

    protected $casts = [
        'rewards_enabled' => 'boolean',
        'purchase_threshold' => 'decimal:2',
        'points_per_threshold' => 'integer',
        'point_conversion_value' => 'decimal:4',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'rewards_enabled' => true,
                'purchase_threshold' => 100.00,
                'points_per_threshold' => 1,
                'point_conversion_value' => 0.0085,
            ]
        );
    }
}
