<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The interests people type that the vocabulary does not have, ranked.
 */
class InterestCandidatesApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $interests */
    private function brief(string $market, array $interests): void
    {
        Event::record('gift.suggest', ['market' => $market, 'interests' => $interests, 'vibe' => null, 'results' => 4]);
    }

    #[Test]
    public function typed_interests_are_ranked_and_the_vocabulary_is_left_out(): void
    {
        $this->brief('be-nl', ['coffee', 'padel']);
        $this->brief('be-nl', ['Padel ', 'schaken']);
        $this->brief('be-nl', ['padel']);
        // Another market's words stay in their market.
        $this->brief('nl-nl', ['padel', 'breien']);
        // A different kind of event with the same shape is not a brief.
        Event::record('gift.swap', ['market' => 'be-nl', 'interests' => ['drummen']]);

        $this->withToken(ApiToken::issue('test', [ApiToken::READ])['token'])
            ->getJson('/api/editorial/interests/candidates?market=be-nl')
            ->assertOk()
            ->assertJsonPath('market', 'be-nl')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.interest', 'padel')
            ->assertJsonPath('data.0.count', 3)
            ->assertJsonPath('data.1.interest', 'schaken')
            ->assertJsonPath('data.1.count', 1)
            ->assertJsonMissing(['interest' => 'coffee'])
            ->assertJsonMissing(['interest' => 'breien'])
            ->assertJsonMissing(['interest' => 'drummen']);
    }

    #[Test]
    public function it_needs_a_read_key(): void
    {
        $this->getJson('/api/editorial/interests/candidates?market=be-nl')->assertStatus(401);
    }
}
