<?php
return [
    'host'        => env('REMOTE_CONFIG_HOST', ''),
    'username'  => env('REMOTE_CONFIG_USERNAME', ''),
    'password'  => env('REMOTE_CONFIG_PASSWORD', ''),
    'application' => env('REMOTE_CONFIG_APPLICATION', ''),
    'environment' => env('REMOTE_CONFIG_ENVIRONMENT', env('APP_ENV')),
    'cache-life-time' => env('REMOTE_CONFIG_CACHE_LIFE_TIME', 3600),
    'cache-directory' => env('REMOTE_CONFIG_CACHE_DIRECTORY'),
    'logger-class' => env('REMOTE_CONFIG_LOGGER_CLASS', ''),
];
