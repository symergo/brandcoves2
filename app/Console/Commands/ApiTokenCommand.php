<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

/**
 * Mint, list and revoke editorial API keys.
 *
 * Unlike `bc:make-admin`, this command *emits* a secret rather than accepting
 * one, so the argv rule is inverted: nothing sensitive goes in, and the one
 * sensitive thing that comes out is printed once and never recoverable. Losing
 * it means minting a new key, which is the correct cost — a key you can look up
 * later is a key stored in plaintext somewhere.
 */
class ApiTokenCommand extends Command
{
    protected $signature = 'bc:api-token
        {name? : What the key is for, e.g. "claude editorial"}
        {--abilities=editorial.read,editorial.write : Comma-separated. Add editorial.publish to let it publish.}
        {--days= : Expire after this many days. Omitted means no expiry.}
        {--list : Show existing keys instead of minting one}
        {--revoke= : Revoke the key with this id}';

    protected $description = 'Mint, list or revoke a token for the editorial API.';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        if ($this->option('revoke') !== null) {
            return $this->revoke((int) $this->option('revoke'));
        }

        return $this->mint();
    }

    private function mint(): int
    {
        $name = (string) ($this->argument('name') ?? '');

        if (trim($name) === '') {
            $this->components->error('A name is required. It is the only thing that will tell you what this key is for later.');

            return self::FAILURE;
        }

        $requested = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('abilities')),
        )));

        $unknown = array_diff($requested, ApiToken::abilities());

        if ($unknown !== []) {
            $this->components->error('Unknown abilities: '.implode(', ', $unknown));
            $this->line('  Available: '.implode(', ', ApiToken::abilities()));

            return self::FAILURE;
        }

        $days = $this->option('days');

        ['token' => $plaintext, 'model' => $token] = ApiToken::issue(
            name: trim($name),
            abilities: $requested,
            expiresAt: $days === null ? null : now()->addDays((int) $days),
        );

        $this->newLine();
        $this->components->info("Key #{$token->id} — {$token->name}");
        $this->line('  Abilities: '.implode(', ', $token->abilities));
        $this->line('  Expires:   '.($token->expires_at?->toDateTimeString() ?? 'never'));
        $this->newLine();
        $this->line('  '.$plaintext);
        $this->newLine();
        $this->components->warn('Shown once. Only the hash is stored — there is no way to print it again.');

        if (in_array(ApiToken::PUBLISH, $token->abilities, true)) {
            $this->components->warn('This key can publish. Anything it writes can reach readers without a human seeing it first.');
        }

        return self::SUCCESS;
    }

    private function list(): int
    {
        $tokens = ApiToken::query()->orderByDesc('id')->get();

        if ($tokens->isEmpty()) {
            $this->components->info('No API keys exist.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Name', 'Abilities', 'Last used', 'Expires', 'State'],
            $tokens->map(fn (ApiToken $t) => [
                $t->id,
                $t->name,
                implode(' ', array_map(
                    fn (string $a) => str_replace('editorial.', '', $a),
                    (array) $t->abilities,
                )),
                $t->last_used_at?->diffForHumans() ?? 'never',
                $t->expires_at?->toDateString() ?? '—',
                match (true) {
                    $t->revoked_at !== null => 'revoked',
                    ! $t->isUsable() => 'expired',
                    default => 'live',
                },
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function revoke(int $id): int
    {
        $token = ApiToken::query()->find($id);

        if ($token === null) {
            $this->components->error("No key with id {$id}.");

            return self::FAILURE;
        }

        // A timestamp, not a delete: during an incident the useful question is
        // "when did this stop working", and a deleted row cannot answer it.
        $token->forceFill(['revoked_at' => now()])->save();

        $this->components->info("Key #{$id} ({$token->name}) revoked.");

        return self::SUCCESS;
    }
}
