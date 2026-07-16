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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'ares' => [
        'base_url' => env('ARES_BASE_URL'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    'google' => [
        'credentials_json' => env('GOOGLE_CREDS_JSON'),
        'project_id' => env('GOOGLE_PROJECT_ID'),
        'client_email' => env('GOOGLE_CLIENT_EMAIL'),
        'private_key' => env('GOOGLE_PRIVATE_KEY'),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        'worksheet_name' => env('GOOGLE_SHEETS_WORKSHEET_NAME', 'Dashboard / Leady'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
    ],

    'hlidac_statu' => [
        'api_key' => env('HLIDAC_STATU_API_KEY'),
        'base_url' => env('HLIDAC_STATU_BASE_URL', 'https://www.hlidacstatu.cz/api/v1'),
        'enabled' => env('ENABLE_HLIDAC_STATU', true),
    ],

    'internal_ingest' => [
        'token' => env('INTERNAL_API_TOKEN'),
    ],

];
