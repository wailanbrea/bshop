<?php

return [
    'enabled' => env('WHATSAPP_ENABLED', false),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'template' => env('WHATSAPP_INVOICE_TEMPLATE'),
    'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es'),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
    'body_fields' => ['customer_name', 'invoice_number', 'total'],
];
