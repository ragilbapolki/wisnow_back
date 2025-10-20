<?php

return [
    /*
     * Paths yang akan menggunakan CORS
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
     * Method HTTP yang diizinkan
     */
    'allowed_methods' => ['*'],

    /*
     * Origin yang diizinkan
     */
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    /*
     * Pattern origin yang diizinkan
     */
    'allowed_origins_patterns' => [],

    /*
     * Header yang diizinkan
     */
    'allowed_headers' => ['*'],

    /*
     * Header yang di-expose
     */
    'exposed_headers' => [],

    /*
     * Cache preflight request (dalam detik)
     */
    'max_age' => 0,

    /*
     * Support credentials (cookies, authorization headers, dll)
     */
    'supports_credentials' => true,
];
