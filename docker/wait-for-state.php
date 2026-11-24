<?php

declare(strict_types=1);

/*
 * Wait until Postgres and Redis serve, then exit 0; exit 1 after five minutes.
 *
 * For the app-only compose file (docker-compose.coolify.app.yml), where Postgres and
 * Redis run as a resource of their own and so outside `depends_on`: the `migrate`
 * one-shot runs this first, as `depends_on: condition: service_healthy` did when they
 * were in the same file. Redis must be serving, not merely up: during an appendonly
 * reload PING answers while every write is refused, which once failed `migrate
 * --isolated` (it takes a Redis lock) and took production to 503; see the healthcheck
 * comment in docker-compose.state.yml. Plain PHP, no framework: the config cache is not
 * built against a database that is not there yet.
 */

$deadline = time() + 300;
$host = getenv('DB_HOST') ?: 'postgres';
$port = getenv('DB_PORT') ?: '5432';
$redisHost = getenv('REDIS_HOST') ?: 'redis';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);

while (time() < $deadline) {
    $pg = false;
    $redis = false;

    try {
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname=".(getenv('DB_DATABASE') ?: 'postgres'),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_TIMEOUT => 3],
        );
        $pg = $pdo->query('select 1')->fetchColumn() == 1;
    } catch (Throwable) {
        $pg = false;
    }

    try {
        $r = new Redis;
        $r->connect($redisHost, $redisPort, 3.0);
        if (($password = getenv('REDIS_PASSWORD')) !== false && $password !== '' && $password !== 'null') {
            $r->auth($password);
        }
        $info = $r->info('persistence');
        $redis = (int) ($info['loading'] ?? 1) === 0;
    } catch (Throwable) {
        $redis = false;
    }

    if ($pg && $redis) {
        fwrite(STDOUT, "wait-for-state: postgres and redis are serving\n");
        exit(0);
    }

    fwrite(STDOUT, 'wait-for-state: waiting for '.implode(' and ', array_keys(array_filter(['postgres' => ! $pg, 'redis' => ! $redis])))."\n");
    sleep(3);
}

fwrite(STDERR, "wait-for-state: gave up after five minutes\n");
exit(1);
