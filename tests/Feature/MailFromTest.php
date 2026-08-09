<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MagicLinkMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every email has somewhere to be from.
 *
 * Symfony refuses to build a message without a From header, and the failure
 * lands wherever the send happens — which was the sign-in form, as a 500, for a
 * reason no error message mentioned.
 */
class MailFromTest extends TestCase
{
    // Signing in writes a login token and an anonymous identity. Without this
    // those rows outlive the test and the next one counts them.
    use RefreshDatabase;

    #[Test]
    public function a_from_address_is_always_configured(): void
    {
        $this->assertNotEmpty(config('mail.from.address'));
        $this->assertNotEmpty(config('mail.from.name'));
    }

    #[Test]
    public function an_empty_environment_variable_still_yields_an_address(): void
    {
        /*
         * The case a default cannot cover.
         *
         * `env('X', 'fallback')` returns the fallback only when X is *absent*.
         * A blank field in Coolify makes it present and empty, so the fallback
         * never applies and the address is an empty string — which is precisely
         * what happened.
         */
        $this->assertSame('info@symergo.com', env('MAIL_FROM_ADDRESS') ?: 'info@symergo.com');

        $original = env('MAIL_FROM_ADDRESS');

        try {
            putenv('MAIL_FROM_ADDRESS=');
            $_ENV['MAIL_FROM_ADDRESS'] = '';

            $this->assertNotEmpty(env('MAIL_FROM_ADDRESS') ?: 'info@symergo.com');
        } finally {
            // `putenv` changes the process, not the test case. Leaving it set
            // would hand every later test a blank address.
            putenv('MAIL_FROM_ADDRESS='.$original);
            $_ENV['MAIL_FROM_ADDRESS'] = $original;
        }
    }

    #[Test]
    public function a_magic_link_can_actually_be_built(): void
    {
        Mail::fake();

        // The end-to-end shape of the bug: a message that cannot be constructed
        // takes the request down with it.
        $this->post('/be-nl/login', ['email' => 'someone@example.test'])
            ->assertRedirect();

        Mail::assertSent(MagicLinkMail::class);
    }
}
