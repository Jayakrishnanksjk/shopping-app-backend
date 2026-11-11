<?php

namespace App\Services\Rewards;

use App\Models\RewardSetting;
use Illuminate\Support\Facades\Cache;

class RewardSettingsService
{
    const CACHE_KEY = 'grabzo_reward_settings';

    public function getSettings(): RewardSetting
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            return RewardSetting::current();
        });
    }

    public function updateSettings(array $data): RewardSetting
    {
        $settings = RewardSetting::current();

        $updateData = [];
        if (isset($data['rewards_enabled'])) {
            $updateData['rewards_enabled'] = (bool) $data['rewards_enabled'];
        }
        if (isset($data['purchase_threshold'])) {
            $threshold = (float) $data['purchase_threshold'];
            if ($threshold > 0) {
                $updateData['purchase_threshold'] = $threshold;
            }
        }
        if (isset($data['points_per_threshold'])) {
            $points = (int) $data['points_per_threshold'];
            if ($points > 0) {
                $updateData['points_per_threshold'] = $points;
            }
        }
        if (isset($data['point_conversion_value'])) {
            $conversion = (float) $data['point_conversion_value'];
            if ($conversion > 0) {
                $updateData['point_conversion_value'] = $conversion;
            }
        }

        $settings->update($updateData);
        Cache::forget(self::CACHE_KEY);

        return $settings->fresh();
    }

    public function isRewardsEnabled(): bool
    {
        return (bool) $this->getSettings()->rewards_enabled;
    }

    public function getThreshold(): float
    {
        return (float) ($this->getSettings()->purchase_threshold ?? 100.00);
    }

    public function getPointsPerThreshold(): int
    {
        return (int) ($this->getSettings()->points_per_threshold ?? 1);
    }

    public function getConversionRate(): float
    {
        return (float) ($this->getSettings()->point_conversion_value ?? 0.0085);
    }
}

