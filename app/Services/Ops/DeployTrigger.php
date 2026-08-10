<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Redeploy this application from the admin screen.
 *
 * ## A webhook, deliberately not an API token
 *
 * Coolify issues a per-application deploy webhook: one URL that redeploys one
 * app and can do nothing else. A full API token would also work and would be a
 * far worse thing to store — it can rename domains, read every environment
 * variable of every application on the box, and delete things. The blast radius
 * of this secret leaking should be "somebody can redeploy the current commit",
 * which is annoying rather than dangerous.
 *
 * ## Why redeploying at all is worth a button
 *
 * **Today it is a convenience, not a gate.** Both Coolify applications have
 * auto-deploy on, so a push to `main` already ships to production within the
 * minute and nothing here changes that. What this gives you is a redeploy
 * without Coolify credentials — enough to pick up an environment variable change
 * or re-run a build that failed.
 *
 * It becomes the gate under the one-branch model in `docs/deployment.md`, which
 * turns auto-deploy **off** on production. That order matters: repointing
 * staging to `main` first, while production still auto-deploys, would send every
 * commit to real visitors with no staging pass at all.
 *
 * ## What it deliberately cannot do
 *
 * Choose a commit. The webhook deploys whatever the tracked branch points at, so
 * this screen cannot deploy something that has not been pushed, and cannot roll
 * back to an arbitrary commit — that stays in Coolify, where the audit trail is.
 * A button that can put any commit on production is a deploy pipeline with no
 * review step.
 */
class DeployTrigger
{
    public const SOURCE = 'ops';

    public const KEY = 'deploy_webhook';

    private const LAST_KEY = 'bc:ops:last-deploy';

    /** Stored encrypted with APP_KEY, like every other admin-editable setting. */
    public function webhook(): ?string
    {
        $row = ConnectorSetting::query()
            ->where('source', self::SOURCE)
            ->where('key', self::KEY)
            ->first();

        $value = $row?->encrypted_value;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function setWebhook(?string $url): void
    {
        if ($url === null || trim($url) === '') {
            ConnectorSetting::query()
                ->where('source', self::SOURCE)
                ->where('key', self::KEY)
                ->delete();

            return;
        }

        ConnectorSetting::query()->updateOrCreate(
            ['source' => self::SOURCE, 'key' => self::KEY],
            ['encrypted_value' => trim($url)],
        );
    }

    public function isConfigured(): bool
    {
        return $this->webhook() !== null;
    }

    /**
     * Ask Coolify to redeploy, and remember what happened.
     *
     * Returns a human-readable outcome rather than throwing: this is called from
     * a button, and an unhandled exception on an admin screen is a stack trace
     * where a sentence belongs.
     *
     * @return array{ok: bool, message: string}
     */
    public function trigger(): array
    {
        $url = $this->webhook();

        if ($url === null) {
            return ['ok' => false, 'message' => 'No deploy webhook is configured for this environment.'];
        }

        try {
            // Short timeout: Coolify queues the deployment and answers straight
            // away. Waiting longer would only ever mean the request is lost, and
            // a page that hangs invites a second click and a second deploy.
            $response = Http::timeout(15)->get($url);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not reach Coolify. The exception is in the log.'];
        }

        $ok = $response->successful();

        $this->remember($ok);

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Deployment queued. It takes a few minutes; watch the build stamp above.'
                : 'Coolify refused the webhook: HTTP '.$response->status().'.',
        ];
    }

    /**
     * The last attempt, for the screen.
     *
     * @return array{at: string, ok: bool}|null
     */
    public function last(): ?array
    {
        $value = Cache::get(self::LAST_KEY);

        return is_array($value) ? $value : null;
    }

    private function remember(bool $ok): void
    {
        // A month, so "when did anyone last deploy this" survives a quiet
        // fortnight. It is a convenience, not a record — Coolify holds the real
        // deployment history.
        Cache::put(self::LAST_KEY, [
            'at' => now()->toDateTimeString(),
            'ok' => $ok,
        ], now()->addMonth());
    }
}
