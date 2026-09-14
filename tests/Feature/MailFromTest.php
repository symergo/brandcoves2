<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every email has somewhere to be from.
 *
 * Symfony refuses to build a message without a From header, and the failure
 * lands wherever the send happens — which was the sign-in form, as a 500, for a
 * reason no error message mentioned.
 *
 * That the sign-in form actually sends its link is `AuthTest::requesting_a_link_sends_one`.
 */
class MailFromTest extends TestCase
{
    #[Test]
    public function a_from_address_is_always_configured(): void
    {
        $this->assertNotEmpty(config('mail.from.address'));
        $this->assertNotEmpty(config('mail.from.name'));
    }
}
