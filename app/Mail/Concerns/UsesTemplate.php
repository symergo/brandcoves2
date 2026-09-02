<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Services\Mail\MailTemplates;
use Illuminate\Mail\Mailables\Content;

/**
 * "Has an editor rewritten this one?"
 *
 * Four lines in four mailables rather than four copies of the same branch. Each
 * mail asks once for its override, falls back to the shipped view when there is
 * none — which is the ordinary case — and renders `mail.templated` when there
 * is.
 *
 * The structure never comes from the override. The button's label and its
 * destination are passed in here by the mailable, because a URL an editor typed
 * is wrong the moment the market changes, and an email whose button went missing
 * is an email nobody can act on. See {@see MailTemplates}.
 */
trait UsesTemplate
{
    /**
     * The editor's version, with its placeholders filled, or null.
     *
     * Resolved once and remembered: `envelope()` needs the subject and
     * `content()` needs the body, and asking twice would be two cache reads to
     * answer one question.
     *
     * @param  array<string, string|int>  $values
     * @return array{subject: string, body: string}|null
     */
    protected function template(string $key, string $language, array $values = []): ?array
    {
        $override = app(MailTemplates::class)->for($key, $language);

        if ($override === null) {
            return null;
        }

        return [
            'subject' => MailTemplates::fill($override['subject'], $values),
            'body' => MailTemplates::fill($override['body'], $values),
        ];
    }

    /**
     * The rendered override: the editor's prose, our button.
     *
     * @param  array{subject: string, body: string}  $template
     */
    protected function templatedContent(
        array $template,
        string $language,
        string $url,
        string $button,
    ): Content {
        return new Content(
            markdown: 'mail.templated',
            with: [
                'language' => $language,
                'body' => $template['body'],
                'url' => $url,
                'button' => $button,
            ],
        );
    }
}
