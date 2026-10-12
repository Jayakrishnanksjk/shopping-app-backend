<?php

namespace App\Http\Controllers;

use Exception;
use App\Enums\OrderEnum;
use App\Enums\PaymentStatus;
use App\Enums\PaymentMethod;
use App\Http\Requests\CancelOrderRequest;
use App\Repositories\Eloquents\OrderRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CancelOrderController extends Controller
{
    protected $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function cancel(CancelOrderRequest $request)
    {
        return $this->orderRepository->cancelOrder(
            $request->input('order_number'), 
            $request
        );
    }
}