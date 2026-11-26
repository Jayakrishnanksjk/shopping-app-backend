<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'topic_all' => env('FIREBASE_TOPIC_ALL', 'all_users'),
    ],

    'springedge' => [
        'api_key' => env('SPRING_EDGE_API_KEY') ?: env('SPRINGEDGE_API_KEY'),
        'sender' => env('SPRING_EDGE_SENDER') ?: env('SPRINGEDGE_SENDER'),
        'dlt_template_id' => env('SPRING_EDGE_DLT_TEMPLATE_ID') ?: env('SPRINGEDGE_DLT_TEMPLATE_ID'),
        'dlt_entity_id' => env('SPRING_EDGE_DLT_ENTITY_ID') ?: env('SPRINGEDGE_DLT_ENTITY_ID'),
    ],

];
