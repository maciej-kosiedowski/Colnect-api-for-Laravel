<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The application ID and secret Colnect issued for your application
    | (https://colnect.com/en/capi/apply_for_key). The ID is part of every
    | request URL; the secret only ever signs requests (HMAC-SHA256) and is
    | never sent. Keep both in your .env file, never in version control.
    |
    */

    'app_id' => env('COLNECT_APP_ID'),

    'app_secret' => env('COLNECT_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | The language Colnect translates responses into: a 2-letter code,
    | optionally with a region ("en", "pl", "pt_BR"). Colnect::connector('pl')
    | reaches any other language at runtime, sharing the same rate limit.
    |
    */

    'language' => env('COLNECT_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | User agent
    |--------------------------------------------------------------------------
    |
    | Colnect asks for a user agent that clearly identifies your application,
    | at least 16 characters long, for example "MyCoolApp/1.2.3". Leave it
    | empty to send "{app.name} (slimad/colnect-api-laravel)".
    |
    */

    'user_agent' => env('COLNECT_USER_AGENT'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | "tries" is the number of attempts per request. Only HTTP 429 responses
    | are retried for every request; server and connection errors are retried
    | for GET requests only, because a POST (such as a billed image search) may
    | already have been processed. "retry_delay" is in milliseconds and doubles
    | after every attempt when "exponential_backoff" is on.
    |
    */

    'http' => [
        'timeout' => (int) env('COLNECT_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('COLNECT_HTTP_CONNECT_TIMEOUT', 10),
        'tries' => (int) env('COLNECT_HTTP_TRIES', 3),
        'retry_delay' => (int) env('COLNECT_HTTP_RETRY_DELAY', 500),
        'exponential_backoff' => (bool) env('COLNECT_HTTP_EXPONENTIAL_BACKOFF', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Colnect grants every application a request quota. The package counts
    | requests in the cache, so every web request, queue worker and Artisan
    | command sharing "cache_store" stays within the same limits. Use a store
    | all of them can reach - redis, memcached, database or dynamodb - in
    | production; "array" counts per process only.
    |
    | "limits" holds one ceiling per window. Set the ones your Colnect
    | agreement specifies; leave a window empty (or 0) to not limit it.
    |
    | When a window is full, the request waits for the next free slot - but no
    | longer than "max_wait" seconds. Past that it throws
    | Slimad\ColnectApi\Laravel\Exceptions\RateLimitExceededException without
    | sending anything; a queued job can release itself for
    | $exception->retryAfter seconds. Set "max_wait" to 0 to never wait.
    |
    | When Colnect answers HTTP 429, every process pauses for as long as its
    | Retry-After header says, or "retry_after" seconds when it says nothing.
    |
    */

    'rate_limit' => [
        'enabled' => (bool) env('COLNECT_RATE_LIMIT_ENABLED', true),
        'cache_store' => env('COLNECT_RATE_LIMIT_CACHE_STORE'),
        'prefix' => env('COLNECT_RATE_LIMIT_PREFIX', 'colnect-api'),

        'limits' => [
            'per_second' => env('COLNECT_RATE_LIMIT_PER_SECOND', 2),
            'per_minute' => env('COLNECT_RATE_LIMIT_PER_MINUTE', 60),
            'per_hour' => env('COLNECT_RATE_LIMIT_PER_HOUR'),
            'per_day' => env('COLNECT_RATE_LIMIT_PER_DAY'),
        ],

        'max_wait' => (int) env('COLNECT_RATE_LIMIT_MAX_WAIT', 30),
        'retry_after' => (int) env('COLNECT_RATE_LIMIT_RETRY_AFTER', 60),
    ],

];
