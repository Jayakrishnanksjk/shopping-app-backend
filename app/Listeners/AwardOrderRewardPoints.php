<?php

namespace App\Listeners;

use App\Events\UpdateOrderStatusEvent;
use App\Enums\OrderEnum;
use App\Services\Rewards\RewardPointsService;
use Illuminate\Support\Facades\Log;

class AwardOrderRewardPoints
{
    protected RewardPointsService $rewardPointsService;

    public function __construct(RewardPointsService $rewardPointsService)
    {
        $this->rewardPointsService = $rewardPointsService;
    }

    /**
     * Handle the order status update event.
     */
    public function handle(UpdateOrderStatusEvent $event): void
    {
        try {
            $order = $event->order;

            if ($order && $order->order_status && $order->order_status->name === OrderEnum::DELIVERED) {
                $this->rewardPointsService->awardPointsForOrder($order);
            }
        } catch (\Throwable $e) {
            Log::error("Error in AwardOrderRewardPoints listener: " . $e->getMessage(), [
                'order_id' => $event->order->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
