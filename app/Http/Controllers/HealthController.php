<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Deployment health, and the answer to "what is actually running right now".
 *
 * Coolify's healthcheck hits this, and so do you after a deploy: it reports the
 * commit that built the image and the last migration applied, which is what
 * turns "it looks fine" into a fact.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'redis' => $this->check(fn () => Redis::ping()),
        ];

        $healthy = ! in_array(false, array_map(fn (array $c) => $c['ok'], $checks), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            /*
             * Three fields, because "is my deploy live?" is three questions and
             * one field kept answering the wrong one.
             *
             * `commit` is WHICH CODE. Now that the Dockerfile takes a
             * SOURCE_COMMIT build arg this is the real SHA, and it is the field
             * to check after a deploy.
             *
             * `built` is WHEN THE IMAGE WAS MADE, and it is subtler than it
             * looks: the Dockerfile layer that writes it is cacheable, so a
             * redeploy of an unchanged commit reports the PREVIOUS build's time.
             * That is honest — an unchanged commit really is the same image —
             * but it means a stale-looking `built` does not imply a failed
             * deploy. Measured on staging: an API-triggered redeploy at 19:46 on
             * 2026-08-31 served a stamp of 19:41.
             *
             * `started` is WHEN THIS CONTAINER CAME UP, read from /proc/1 at
             * request time. It cannot be cached or baked, so it is the field
             * that actually answers "did the deploy restart anything?" — the
             * question `built` was being asked and could not reliably answer.
             */
            'commit' => $this->commit(),
            'built' => $this->buildStamp(),
            'started' => $this->startedAt(),
            'branch' => env('COOLIFY_BRANCH', 'local'),
            'migration' => $this->lastMigration(),
            'environment' => app()->environment(),
            'config' => $this->config(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Whether the settings that must travel actually arrived.
     *
     * **Booleans only, and never a value or a length.** This endpoint is
     * unauthenticated, so it may say that a key is present and nothing more —
     * `bc:check-config` gives the fuller picture to whoever already has a shell.
     *
     * The point is that "did the config carry over?" becomes a `curl` rather
     * than an SSH session, so it can be answered in the same breath as `built`
     * and `migration` after every deploy. It is deliberately not part of the
     * `status` calculation: a missing Amazon key must not take the site down,
     * and Coolify restarts a container that reports unhealthy.
     *
     * `awinAccounts` is a count rather than a flag because the failure it
     * exists to catch was *fewer accounts than expected*, not zero — the
     * catalogue still built, from one publisher instead of two, and nothing
     * anywhere said so.
     *
     * @return array<string, bool|int>
     */
    private function config(): array
    {
        return [
            'appKey' => filled(config('app.key')),
            'credentialsKey' => filled(config('giftcoves.credentials_key')),
            'claimHashSecret' => filled(config('giftcoves.wishlist.claim_hash_secret')),
            'mail' => $this->mailIsSendable(),
            'awin' => filled(config('giftcoves.connectors.awin.api_token')),
            'awinAccounts' => count((array) config('giftcoves.connectors.awin.accounts', [])),
            /*
             * A source flag means "this source could work here", so an OAuth
             * source needs BOTH halves of its pair.
             *
             * Reporting on the client id alone was actively misleading, and it
             * cost real time: production answered `ebay: true` while eBay was
             * absent from every search, so the secret was never suspected and
             * the search code was. `supports()` requires the pair, so a
             * connector with an id and no secret is never even called — the
             * one state this flag existed to make visible, and the one it hid.
             *
             * The market check `supports()` also does is deliberately not
             * mirrored: it varies per market, and this is one flag for the
             * environment.
             *
             * The path matters too. `bol` once pointed at
             * `connectors.sources.bol.client_id`, which does not exist, so it
             * read "missing" on every environment including ones where bol
             * demonstrably works. A config check that is always wrong in the
             * safe direction is worse than none: it gets ignored, or it sends
             * somebody chasing a credential that was never absent.
             */
            'bol' => filled(config('giftcoves.connectors.bol.client_id'))
                && filled(config('giftcoves.connectors.bol.client_secret')),
            'ebay' => filled(config('giftcoves.connectors.ebay.client_id'))
                && filled(config('giftcoves.connectors.ebay.client_secret')),

            /*
             * Whether an eBay click can earn anything.
             *
             * Separate from `ebay` because the two fail independently and only
             * one of them is visible: without a campaign id eBay still returns
             * results, the links still work, the visitor still buys, and the
             * commission goes to nobody. Nothing on the site reports it and it
             * surfaces months later as an empty EPN statement — so it is worth
             * a flag of its own rather than being folded into `ebay`, which
             * would report "true" for a connector earning zero.
             */
            'ebayTracking' => collect((array) config('giftcoves.connectors.ebay.campaign_id', []))
                ->contains(fn ($id): bool => filled($id)),

            // One credential, and it carries the affiliate id too, so there is
            // no tracking flag to keep beside it.
            'tradedoubler' => filled(config('giftcoves.connectors.tradedoubler.token')),
            'ai' => filled(config('giftcoves.ai.api_key')),
            'robotsAllow' => (bool) config('giftcoves.robots_allow'),
        ];
    }

    /**
     * Whether this environment could actually send a message.
     *
     * Transport-aware, because the flag used to read `services.resend.key` no
     * matter which mailer was configured — so switching production to OVH's
     * SMTP would have left it reporting `mail: false` forever while mail
     * worked perfectly, which is the same class of lie as the eBay flag that
     * checked one half of a credential pair.
     *
     * `log` and `array` count as sendable: nothing leaves the machine, but the
     * environment is doing what it was asked to, and a red flag on a
     * deliberately silent mailer is noise rather than a warning.
     */
    private function mailIsSendable(): bool
    {
        return match ((string) config('mail.default')) {
            'resend' => filled(config('services.resend.key')),
            // A host alone is not enough: OVH refuses an unauthenticated
            // session outright, so a blank username or password is a mailer
            // that connects and then cannot send.
            'smtp' => filled(config('mail.mailers.smtp.host'))
                && filled(config('mail.mailers.smtp.username'))
                && filled(config('mail.mailers.smtp.password')),
            'log', 'array' => true,
            default => false,
        };
    }

    /** @param callable():mixed $probe */
    private function check(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();

            return ['ok' => true, 'ms' => $this->elapsedMs($start)];
        } catch (Throwable $e) {
            // The message can carry a connection string with credentials, so it
            // is logged rather than returned — this endpoint is unauthenticated.
            report($e);

            return ['ok' => false, 'ms' => $this->elapsedMs($start)];
        }
    }

    private function elapsedMs(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }

    /** Written into the image at build time; absent when running locally. */
    private function buildStamp(): string
    {
        $path = base_path('BUILD_STAMP');

        return is_readable($path) ? trim((string) file_get_contents($path)) : 'dev';
    }

    /**
     * The commit that built this image, short form.
     *
     * Twelve characters rather than forty: long enough to be unambiguous in
     * this repo and short enough to eyeball against `git log --oneline`. Null
     * rather than 'unknown' when absent, so a laptop reads as "no answer" and
     * not as a deploy that lost its SHA.
     */
    private function commit(): ?string
    {
        $sha = trim((string) env('GIT_COMMIT_SHA', ''));

        return $sha === '' ? null : substr($sha, 0, 12);
    }

    /**
     * When this container started, from procfs.
     *
     * The /proc/<pid> directory carries the process start time, and in a
     * container PID 1 is the container. Read per request, so unlike anything
     * baked into the image it cannot be stale — which is the entire point of
     * having it next to `built`.
     *
     * Null off Linux (a Windows dev box has no procfs) rather than a fabricated
     * time: an absent answer is honest, a wrong one is worse than none.
     */
    private function startedAt(): ?string
    {
        $ctime = @filectime('/proc/1');

        return $ctime === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ctime);
    }

    private function lastMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return null;
        }
    }
}
