<?php

return [
    'order' => [
        'pivot' => [
            'product_id',
            'variation_id',
            'quantity',
            'single_price',
            'shipping_cost',
            'tax',
            'refund_status',
            'subtotal'
        ],
        'with' => [
            'products:id,name,product_thumbnail_id,tax_id',
            'sub_orders',
            'billing_address',
            'shipping_address',
        ]
    ],
    'seeders' => [
        'RoleSeeder',
        'ThemeSeeder',
        'DefaultImagesSeeder',
        'HomePageSeeder',
        'CountriesSeeder',
        'SettingSeeder',
        'ThemeOptionSeeder',
        'OrderStatusSeeder',
        'StateSeeder'
    ],
    'user' => [
        'with' => [
            'point',
            'wallet',
            'address',
            'vendor_wallet',
            'profile_image',
            'payment_account'
        ]
    ],
    'store' => [
        'with' => [
            'store_logo',
            'store_cover',
            'vendor',
            'country',
            'state'
        ]
    ],
    'product' => [
        'with' => [
            'product_galleries',
            'size_chart_image',
            'store:id,store_name,slug,description,store_logo_id,hide_vendor_email,hide_vendor_phone,vendor_id',
            'attributes',
            'categories',
            'variations',
        ],
        'visible' => [
            'description',
            'cross_products',
            'meta_description',
        ],
        'appends' => [
            'user_review',
            'can_review',
            'rating_count',
            'order_amount',
            'review_ratings',
            'related_products',
            'cross_sell_products',
        'badge',
        ]
    ]
];
