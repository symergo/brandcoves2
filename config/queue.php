<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),

            /*
             * Longer than the longest job timeout on the queues this
             * connection is used to READ, and that is the whole rule.
             *
             * `retry_after` is how long Redis holds a job reserved before it
             * decides the worker died and hands the job to somebody else. Set
             * below a job's own `$timeout` it does not abort anything — it
             * releases a job that is still running, a second worker picks up the
             * same payload, and the attempt counter climbs while the original
             * keeps working. Two releases is `$tries = 2` spent, so the job dies
             * with MaxAttemptsExceeded having never once been allowed to finish.
             *
             * At the stock 90 that is what happened every morning on production:
             * `BuildDailyEdition` for be-nl started at 06:00:01, was re-reserved
             * at 06:01:32 and again at 06:03:04, and failed there — so
             * `/be-nl/daily` 404'd while the small markets, which finish inside
             * ninety seconds, published normally. Same cause for the daily
             * IngestFeed, GroupProducts, RefreshBrandStats and ScoreSerendipity
             * failures; every job on this queue that scans the catalogue was
             * losing the same race. Diagnosed 2026-09-01.
             *
             * 3900 = IngestFeed's 3600s timeout plus five minutes of headroom.
             * Raise this whenever a job's `$timeout` goes above it.
             *
             * The cost of 3900 for everything: a visitor's job (a pasted link
             * being read) orphaned by a worker that really did die waited 65
             * minutes for its retry. So since 2026-09-28 there are TWO
             * connections onto the same Redis queues, differing only here.
             *
             * Jobs are PUSHED onto this connection with a queue name
             * (#[Queue('batch')] on the class). The Redis key, `queues:batch`,
             * is the same whichever connection reads it, and the READING
             * connection's `retry_after` is the one that applies, because it is
             * set at the moment a worker reserves the job. config/horizon.php
             * has the `default` queue read through this connection and the
             * slow queues (`mail`, `editorial`, `batch`) through `redis-long`.
             *
             * 180 = the longest job on `default` (the AI jobs, `$timeout` 120)
             * plus a minute. A long job that forgets its #[Queue] attribute
             * lands here and is re-run after three minutes while still running;
             * QueueRoutingTest fails before that can ship.
             */
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 180),
            'block_for' => null,
            'after_commit' => false,
        ],

        // The slow queues. 3900 = IngestFeed's 3600s timeout plus five
        // minutes; raise it whenever a job's `$timeout` goes above it.
        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_LONG_QUEUE_RETRY_AFTER', 3900),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
