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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID'),

        // The "equipments" tab of that spreadsheet is kept up to date from EMS (equipments:sync-sheet):
        // existing rows only, never added or deleted. Off until EQUIPMENT_SHEET_SYNC_ENABLED=true.
        'equipment_sheet' => [
            'enabled' => (bool) env('EQUIPMENT_SHEET_SYNC_ENABLED', false),
            'tab' => env('EQUIPMENT_SHEET_TAB', 'equipments'),
            'types_tab' => env('EQUIPMENT_SHEET_TYPES_TAB', 'asset_types'),
            'every_minutes' => max(5, min(60, (int) env('EQUIPMENT_SHEET_SYNC_MINUTES', 15))),
        ],
    ],

    // The AIMS database the assets:import-aims-* and movements:import-aims commands read from.
    'aims' => [
        'connection' => env('DB_AIMS_CONNECTION', 'sqlsrv_aims'),
    ],

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

];
