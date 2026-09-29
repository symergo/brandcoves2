<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\RecipientType;
use App\Models\Recipient;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who "Find a gift" already said this is for, read from the link to one of
 * its ways (This or that, Swipe gifts), so the page does not ask again.
 *
 * `?person=<id>` is one of the visitor's own people (owner-scoped: any other
 * id is ignored, as if it were not there); `?relationship=mother` is one of
 * the closed vocabulary; `?for=me` is "Voor mezelf". None of them describes
 * the person, only who they are, so all may sit in a URL where the answers
 * may not. See docs/features/find-a-gift.md.
 */
final class CarriedWho
{
    /** @return array{person: array{id: string, name: string}|null, relationship: string|null, forMe: bool} */
    public function read(Request $request, CurrentMarket $current): array
    {
        if ($request->query('for') === 'me') {
            return ['person' => null, 'relationship' => null, 'forMe' => true];
        }

        $recipient = $this->recipient($request, (string) $request->query('person', ''));

        $relationship = $recipient !== null
            ? app(GiftResults::class)->relationshipType($recipient->relationship, $current->get())
            : RecipientType::tryFrom((string) $request->query('relationship', ''));

        return [
            'person' => $recipient === null ? null : ['id' => $recipient->id, 'name' => $recipient->name],
            'relationship' => $relationship?->value,
            'forMe' => false,
        ];
    }

    /**
     * What the choosing games start from for whoever this is (DeckSeeds):
     * one of your own people by id, a relationship by value, or yourself.
     * Anybody else's person id counts as nobody.
     */
    public function seed(Request $request, CurrentMarket $current, ?string $personId, ?string $relationship, bool $forMe): DeckSeed
    {
        return app(DeckSeeds::class)->for(
            $current->get(),
            $forMe ? null : $this->recipient($request, $personId),
            $forMe ? null : RecipientType::tryFrom((string) $relationship),
            $forMe ? $request->user() : null,
        );
    }

    /** One of the visitor's own people, or null for anybody else's id. */
    public function recipient(Request $request, ?string $id): ?Recipient
    {
        return $id !== null && Str::isUuid($id)
            ? Owner::fromRequest($request)->scope(Recipient::query())->find($id)
            : null;
    }
}
