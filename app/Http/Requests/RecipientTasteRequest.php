<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\Interest;
use App\Enums\TasteSource;
use App\Enums\Vibe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields that describe a person, validated in one place.
 *
 * Three routes write them — the owner editing a recipient, the recipient
 * describing themselves at `/for/{token}`, and the wizard saving a brief back.
 * They must agree on what is acceptable, and the way that stops being true is
 * three copies of the same rules drifting apart.
 *
 * The split between `context()` and `taste()` is the important part: see
 * {@see TasteSource}. Giver context is mine, taste is theirs.
 */
class RecipientTasteRequest extends FormRequest
{
    /** Owned by the giver. The recipient must never see or write these. */
    private const CONTEXT = ['name', 'relationship', 'occasion', 'age_band', 'gender', 'birthday', 'notes'];

    /**
     * Owned by the person being described. The vibe and the values went on
     * 2026-09-29 (removed site-wide); nothing single-valued is left.
     */
    private const TASTE = [];

    private const TASTE_LISTS = ['interests', 'avoid'];

    public function authorize(): bool
    {
        // Ownership is settled by the route — owner scoping, or a capability
        // token. This request only decides what a field may contain.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'occasion' => ['nullable', 'string', 'max:40'],
            'age_band' => ['nullable', 'string', 'max:20'],
            // Man or woman, a fact the giver knows, like the age (Gender, 2026-09-29).
            'gender' => ['nullable', 'string', Rule::in(Gender::values())],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * Free text is accepted alongside the closed vocabulary on purpose.
             * Someone who typed "wielrennen" has told us exactly what to search
             * for, and second-guessing them is worse than trusting them — it
             * simply does not get a curated angle.
             */
            'interests' => ['sometimes', 'array', 'max:8'],
            'interests.*' => ['string', 'max:40'],

            'avoid' => ['sometimes', 'array', 'max:10'],
            'avoid.*' => ['string', 'max:40'],

            // Euros in the payload, cents in the column (invariant #7): the
            // form shows a slider in the currency people think in.
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * The giver's own context, plus budget.
     *
     * Budget sits here rather than in taste because it describes what *I* am
     * willing to spend, which is not a fact about them.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $attributes = $this->present(self::CONTEXT);

        foreach (['budget_min', 'budget_max'] as $key) {
            if ($this->has($key)) {
                $attributes[$key] = $this->validated($key) === null
                    ? null
                    : (int) round((float) $this->validated($key) * 100);
            }
        }

        return $attributes;
    }

    /**
     * What the person likes.
     *
     * @return array<string, mixed>
     */
    public function taste(): array
    {
        $attributes = $this->present(self::TASTE);

        foreach (self::TASTE_LISTS as $key) {
            if ($this->has($key)) {
                $attributes[$key] = array_values(array_unique(array_filter(
                    array_map(trim(...), (array) $this->validated($key)),
                    fn (string $v) => $v !== '',
                )));
            }
        }

        return $attributes;
    }

    public function describesTaste(): bool
    {
        return $this->taste() !== [];
    }

    /**
     * Only keys the request actually sent.
     *
     * A partial form must never blank a field it did not show — the wizard
     * posts interests without touching the birthday, and the recipient's own
     * page posts taste without touching the owner's notes.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function present(array $keys): array
    {
        $attributes = [];

        foreach ($keys as $key) {
            if ($this->has($key)) {
                $attributes[$key] = $this->validated($key);
            }
        }

        return $attributes;
    }

    /**
     * The closed vocabulary a form should offer.
     *
     * @return array{interests: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        return [
            'interests' => array_map(fn (Interest $i) => [
                'value' => $i->value,
                'label' => $i->label(),
            ], Interest::cases()),
        ];
    }
}
