<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\TemplatedContent;
use App\Models\MailTemplate;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The words in an email, overlaid on the shipped copy.
 *
 * Page copy has been an editor's since page templates shipped, and email copy
 * was still a developer's — the strangest split in the product: the words on a
 * screen somebody chose to open are editable, and the words that arrive
 * uninvited in their inbox are a pull request.
 *
 * This is the overlay. `lang/{language}/site.php` remains the default and is
 * never removed; a row here replaces the subject and the prose for one template
 * in one language, and an absent or disabled row means the email reads exactly
 * as it always did.
 *
 * ## What an editor may change, and what they may not
 *
 * The **prose**: the subject and the body. Not the structure — the button, its
 * destination, the fallback URL line, the layout. Those are the parts that fail
 * silently: a template that lost its button is an email nobody can act on, and
 * a URL typed into a body is wrong the moment the market changes. The editor
 * writes the sentences; {@see TemplatedContent} supplies everything
 * that has to work.
 *
 * ## Two emails are deliberately not editable
 *
 * `cove_digest` carries products — a body is not its content, a list of picks
 * is, and an editable paragraph around them would be a text box pretending to
 * be the email. `santa_assignment` carries the drawn name, which is the entire
 * point of the message and the one sentence that must not be rewritten into
 * something that fails to reveal it. Both stay in code, and the admin screen
 * says so rather than quietly omitting them.
 *
 * ## Placeholders
 *
 * Each template declares the names it can fill. A body naming something that is
 * not declared renders the token as written rather than guessing, which is the
 * failure an editor can see and fix — unlike an empty gap where a name should
 * be.
 */
class MailTemplates
{
    /**
     * The templates an editor may rewrite, and the facts each one has.
     *
     * Code, because only the mailable knows what it can supply. `subject` and
     * `body` are the lang keys the shipped version uses, so the admin screen can
     * show the current wording as the starting point rather than an empty box.
     *
     * @var array<string, array{label: string, subject: string, body: list<string>, placeholders: list<string>}>
     */
    public const KEYS = [
        'occasion_reminder' => [
            'label' => 'Occasion reminder',
            'subject' => 'site.reminders.list_title',
            'body' => ['site.reminders.lead'],
            'placeholders' => ['name', 'occasion', 'days'],
        ],
        'magic_link' => [
            'label' => 'Sign-in link',
            'subject' => 'site.auth.mail_subject',
            'body' => ['site.auth.mail_body'],
            'placeholders' => [],
        ],
        'list_invitation' => [
            'label' => 'List invitation',
            'subject' => 'site.invitations.mail_subject',
            'body' => ['site.invitations.mail_intro', 'site.invitations.mail_what'],
            'placeholders' => ['name', 'person', 'list'],
        ],
        'cove_confirm' => [
            'label' => 'Cove subscription — confirm',
            'subject' => 'site.cove_mail.confirm_subject',
            'body' => ['site.cove_mail.confirm_body'],
            'placeholders' => [],
        ],
    ];

    private const CACHE_KEY = 'bc:mail-templates';

    /**
     * The override for one template in one language, or null.
     *
     * Null is the ordinary case and means "render the shipped view". Callers
     * branch on it rather than on a flag, so there is one thing to check.
     *
     * @return array{subject: string, body: string}|null
     */
    public function for(string $key, string $language): ?array
    {
        if (! isset(self::KEYS[$key])) {
            return null;
        }

        $row = $this->all()[$key.'|'.$language] ?? null;

        if ($row === null || ! $row['enabled']) {
            return null;
        }

        return ['subject' => $row['subject'], 'body' => $row['body']];
    }

    /**
     * Fill `:name` style placeholders, the way `__()` does.
     *
     * Deliberately the same syntax as the language files: an editor who has
     * seen one has seen both, and a second convention for the same idea is a
     * second thing to explain. A token with no value is left as written — a
     * visible `:name` is a bug somebody reports, where a silent gap is one
     * nobody notices.
     *
     * @param  array<string, string|int>  $values
     */
    public static function fill(string $text, array $values): string
    {
        foreach ($values as $token => $value) {
            $text = str_replace(':'.$token, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Every override, keyed `key|language`.
     *
     * One cached read rather than a query per email: a digest run sends
     * thousands, and the overrides are a handful of rows that change by hand.
     *
     * The try wraps the cache call for the reason `AiSettingsStore` records —
     * a Docker build and a fresh `migrate` both boot the application with no
     * reachable database, and the right answer there is "no overrides".
     *
     * @return array<string, array{subject: string, body: string, enabled: bool}>
     */
    private function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 3600, function (): array {
                return MailTemplate::query()
                    ->get()
                    ->mapWithKeys(fn (MailTemplate $t): array => [
                        $t->key.'|'.$t->language => [
                            'subject' => (string) $t->subject,
                            'body' => (string) $t->body,
                            'enabled' => (bool) $t->enabled,
                        ],
                    ])
                    ->all();
            });
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The shipped wording, for the admin screen's starting point.
     *
     * Assembled from the same lang keys the views render, so "reset to the
     * default" and "what does this say now" are answered by the thing that
     * actually says it rather than by a second copy that will drift.
     *
     * @return array{subject: string, body: string}
     */
    public function shipped(string $key, string $language): array
    {
        $spec = self::KEYS[$key] ?? null;

        if ($spec === null) {
            return ['subject' => '', 'body' => ''];
        }

        return [
            'subject' => (string) __($spec['subject'], [], $language),
            'body' => collect($spec['body'])
                ->map(fn (string $line): string => (string) __($line, [], $language))
                ->implode("\n\n"),
        ];
    }
}
