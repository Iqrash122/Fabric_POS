<?php

return [
    'driver' => env('WHATSAPP_DRIVER', 'meta'),
    'meta' => [
        'token' => env('META_WHATSAPP_ACCESS_TOKEN', ''),
        'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID', ''),
        // Copy the supported version shown in the Meta API Setup example.
        'version' => env('META_GRAPH_VERSION', ''),
        'test_number_confirmed' => env('META_WHATSAPP_TEST_NUMBER_CONFIRMED', false),
        'test_recipient' => env('META_WHATSAPP_TEST_RECIPIENT', ''),
    ],
];
