<?php

declare(strict_types=1);

return [

    // Set via API_KEY env var - generate with: openssl rand -hex 32
    'api_key' => (string) getenv('API_KEY'),

    'db' => [
        'host'     => (string) (getenv('DB_HOST') ?: 'host.docker.internal'),
        'port'     => (int)    (getenv('DB_PORT') ?: 5432),
        'name'     => (string) (getenv('DB_NAME') ?: 'fusionpbx'),
        'user'     => (string) (getenv('DB_USER') ?: 'fusionpbx'),
        'password' => (string)  getenv('DB_PASS'),
    ],

];
