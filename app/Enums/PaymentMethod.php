<?php

namespace App\Enums;

enum PaymentMethod {
  const COD = 'cod';
  const PAYPAL = 'paypal';
  const STRIPE = 'stripe';
  const MOLLIE = 'mollie';
  const RAZORPAY = 'razorpay';
  const PHONEPE = 'phonepe';
  const INSTAMOJO = 'instamojo';
  const CCAVENUE = 'ccavenue';
  const WALLET = 'wallet';
  const ALL_PAYMENT_METHODS = [
    'cod', 'paypal', 'stripe', 'mollie', 'ccavenue', 'phonepe', 'instamojo','razorpay', 'wallet'
  ];
}
