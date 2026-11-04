<?php

declare(strict_types=1);

return [
    'name'        => 'Postal',
    'description' => 'Sends through the Postal HTTP API (mailer DSN postal+api://KEY@host) and processes Postal bounce and delivery-failure webhooks (POST /mailer/callback).',
    'version'     => '0.3.0', // x-release-please-version
    'author'      => 'Kopexa',
    'parameters'  => [
        // Public key Postal signs webhooks with: either a PEM, or the JWKS URL of
        // the Postal server (https://postal.example.com/.well-known/jwks.json).
        'postal_webhook_public_key' => '',
        'postal_jwks_url'           => '',
        'postal_jwks_cache_ttl'     => 3600,
    ],
];
