<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon: four queues, one per kind of work (2026-09-28)
|--------------------------------------------------------------------------
|
| Until this file existed Horizon ran on the package's defaults: ONE queue
| (`default`), up to ten workers, and every job shared them. A visitor pasting a
| link into a list (ReadItemLink, a few seconds) waited behind a 300 MB feed
| ingest, a Cove build and a catalogue-wide grouping pass, and all of them had
| to live with one `retry_after` sized for the slowest (65 minutes). A visitor
| job orphaned by a dead worker then waited an hour for its retry.
|
| Now the work is split by who is waiting for it:
|
|   default    somebody is looking at a screen: a pasted link being read, a
|              question sent to people, a live shop search, a mail sent from a
|              request. Short jobs, short retry, never behind the night's work.
|   mail       the scheduled mailings: the Cove digest, the list price digest,
|              occasion reminders, price and restock alerts.
|   editorial  building Coves: the editorial automation, the Daily, due Coves,
|              a rebuild, and the AI work that feeds them.
|   batch      the catalogue: feed ingestion, grouping, classification, brand
|              statistics, charts, list signals and the other night jobs.
|              At most TWO at once, because each one reads a whole market and
|              two are already most of what the database can give while the
|              site keeps answering (the audit found the box 85% idle, and the
|              slow part was the database under these passes).
|
| Every job names its queue with a #[Queue('...')] attribute on the class, and
| QueueRoutingTest keeps that true. A job without one lands on `default`, where
| a long job would be retried after three minutes while still running (see
| config/queue.php), so a new long job MUST name its queue.
|
| The ceilings add up to ten processes, the number the package default ran.
|
| `connection` is where each supervisor READS from, and the only thing that
| differs between the two connections is `retry_after`. Jobs are pushed onto
| the default `redis` connection with a queue name; the Redis key is the same
| (`queues:batch`) whichever connection reads it, and the reading connection's
| `retry_after` is what decides how long a running job is left alone. So the
| slow queues read through `redis-long`, and `default` keeps a short one.
|
| `timeout` here is only the default for a job that does not set its own
| `$timeout`; it must stay below the reading connection's `retry_after`.
| `memory` is the worker's restart threshold in MB, checked between jobs.
*/

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    // The package default, kept exactly: changing it would orphan the keys
    // (metrics, recent and failed jobs) the running Horizon already holds.
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    /*
     * How long a queue may wait, in seconds, before Horizon calls it a long
     * wait. `batch` is expected to queue for a while at night: the chain puts
     * a market's work on it one step at a time, and a feed may wait behind
     * another market's.
     */
    'waits' => [
        'redis:default' => 30,
        'redis-long:mail' => 600,
        'redis-long:editorial' => 900,
        'redis-long:batch' => 3600,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'visitors' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 2,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            // Below `redis.retry_after` (180). The AI jobs that run here set
            // their own 120.
            'timeout' => 90,
            'nice' => 0,
        ],
        'mail' => [
            'connection' => 'redis-long',
            'queue' => ['mail'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            // A digest to every subscriber of a market takes a while over SMTP.
            'timeout' => 1800,
            'nice' => 0,
        ],
        'editorial' => [
            'connection' => 'redis-long',
            'queue' => ['editorial'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            // A Cove build holds a catalogue-wide selection in memory.
            'memory' => 512,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        'batch' => [
            'connection' => 'redis-long',
            'queue' => ['batch'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            // IngestFeed's own timeout; below `redis-long.retry_after` (3900).
            'timeout' => 3600,
            // Behind the site: the web process answers visitors, and a batch
            // worker that yields the CPU for a moment costs nobody anything.
            'nice' => 5,
        ],
    ],

    /*
     * Staging and production both run APP_ENV=production (one compose file,
     * two apps), so both get these numbers. Locally everything is smaller.
     */
    'environments' => [
        'production' => [
            'visitors' => [],
            'mail' => [],
            'editorial' => [],
            'batch' => [],
        ],

        'local' => [
            'visitors' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'mail' => ['maxProcesses' => 1],
            'editorial' => ['maxProcesses' => 1],
            'batch' => ['maxProcesses' => 1],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
