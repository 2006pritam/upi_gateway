<?php

return [
    // Minutes a payment link stays valid before the order expires.
    'order_expiry_minutes' => (int) env('GATEWAY_ORDER_EXPIRY_MINUTES', 15),

    // Minimum seconds between two provider status checks for the same order.
    'check_interval_seconds' => 3,

    // Unofficial Paytm for Business status endpoint. It may change without notice.
    'paytm_status_url' => env('PAYTM_STATUS_URL', 'https://securegw.paytm.in/merchant-status/getTxnStatus'),

    // Official PhonePe PG (Standard Checkout v2).
    // https://developer.phonepe.com/payment-gateway/website-integration/standard-checkout/api-integration/
    'phonepe_pg' => [
        'sandbox' => [
            'token_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token',
            'base_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        ],
        'production' => [
            'token_url' => 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token',
            'base_url' => 'https://api.phonepe.com/apis/pg',
        ],
    ],

    // Official Paytm Payment Gateway. https://www.paytmpayments.com/docs/
    'paytm_pg' => [
        'sandbox' => env('PAYTM_PG_SANDBOX_URL', 'https://securestage.paytmpayments.com'),
        'production' => env('PAYTM_PG_PRODUCTION_URL', 'https://secure.paytmpayments.com'),
    ],
];
