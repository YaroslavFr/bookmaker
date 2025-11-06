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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
    ],

    'api_sport' => [
        'key' => env('API_SPORT_KEY'),
        // OpenAPI server: https://api.api-sport.ru ; paths start with /v1
        'base_url' => env('API_SPORT_BASE', 'https://api.api-sport.ru'),
    ],

    'sstats' => [
        'base_url' => env('SSTATS_BASE', 'https://api.sstats.net'),
        'key' => env('SSTATS_API_KEY'),
        'champions_league_id' => env('SSTATS_CL_ID', 2),
    ],

];
