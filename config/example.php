<?php
/**
 * ConnectMeet - Example configuration.
 * Copy this file to `config/database.php` (and edit it) before running.
 * `config/database.php` is gitignored; `config/example.php` documents the shape.
 *
 * All values can be overridden by environment variables where noted.
 * The file lives in /config which the .htaccess denies direct web access to,
 * so credentials are not publicly downloadable.
 */

return [
    // --------------------------------------------------
    // Database (PDO / MySQL)
    // --------------------------------------------------
    'db' => [
        'dsn'       => 'mysql:host=127.0.0.1;port=3306;dbname=connectmeet;charset=utf8mb4',
        'username'  => 'connectmeet',          // or 'root' with your local password
        'password'  => 'connectmeet',
        'options'   => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    // --------------------------------------------------
    // Application
    // --------------------------------------------------
    'app' => [
        'name'        => 'ConnectMeet',
        // Base URL of the app, no trailing slash. Used to build share links.
        // For XAMPP local: http://localhost/connectmeet
        // For cPanel:      https://yourdomain.com/connectmeet
        'base_url'    => 'http://localhost/connectmeet',
        'timezone'    => 'UTC',

        // Secret used to sign/CSRF-protect state-changing API requests.
        // Generate a long random value: openssl rand -hex 32
        'app_secret'  => 'CHANGE-ME-please-generate-a-random-64-char-hex-secret',

        // Secure flag for session cookies.
        // Set TRUE on HTTPS (cPanel). For local XAMPP over http://localhost keep FALSE
        // (localhost is a secure context for media APIs, but the cookie Secure flag
        // would reject plain-http cookies).
        'cookie_secure' => false,

        // Meeting defaults
        'meeting' => [
            'default_participant_limit' => 8,
            'max_participant_limit'     => 12,   // mesh ceiling for the prototype
            'expiry_hours'              => 168,  // days * 24
            'code_chars'               => 'abcdefghijkmnpqrstuvwxyz23456789',
            'code_lengths'             => [3, 4, 4], // abc-defg-hij
        ],
    ],

    // --------------------------------------------------
    // WebRTC / ICE
    // --------------------------------------------------
    // These are exposed to the browser (public STUN is fine).
    // For restrictive corporate networks / NAT traversal in production,
    // add a TURN server (e.g. coturn) and put its credentials here.
    'webrtc' => [
        'ice_servers' => [
            [
                'urls' => [
                    'stun:stun.l.google.com:19302',
                    'stun:stun1.l.google.com:19302',
                ],
            ],
            // Uncomment and replace with your TURN provider:
            // [
            //     'urls' => ['turn:turn.example.com:3478?transport=udp','turn:turn.example.com:3478?transport=tcp'],
            //     'username' => 'connectmeet',
            //     'credential' => 'turn-secret',
            // ],
        ],
    ],

    // --------------------------------------------------
    // CORS
    // --------------------------------------------------
    // Same-origin by default. For split domains, set allowed origins.
    'cors' => [
        'allowed_origins' => [], // e.g. ['https://yourdomain.com']
    ],
];
