<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Interest;
use App\Enums\ModerationStatus;
use App\Enums\Vibe;
use App\Jobs\TriageCommunityPost;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Models\ProductGroup;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ask others — the board where people ask what to buy and other people answer.
 *
 * The gap it fills: every other way into this site assumes you can describe
 * what you want. Search needs a noun, the Gift Finder needs six answers about a
 * person, a Cove is a theme somebody else chose. "She's turning forty, she has
 * everything, help" is not a query — it is a question for a person.
 *
 * ## Reading is open, writing needs an account
 *
 * The board is indexable and anybody may read it: a question with good answers
 * is exactly the sort of page that should rank, and requiring a login to read
 * one is how it never does. Posting needs an account, which gives every post a
 * person, an address for a reply, and something to lose — the three things that
 * make a public board moderatable at all.
 *
 * ## Nothing here publishes anything
 *
 * A post is created `pending` and `TriageCommunityPost` decides. This controller
 * cannot publish, which is deliberate: the one place that turns a stranger's
 * writing into a public page is a queued job with the model behind it, and no
 * request path should be able to do it. See docs/features/ask-others.md.
 */
class AskController extends Controller
{
    /** Questions per page. A board, not an archive — the useful ones are recent. */
    private const PER_PAGE = 20;

    /** Products one answer may attach. Enough for "one of these three". */
    private const MAX_PICKS = 3;

    public function index(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.ask.seo_title'),
            description: __('site.ask.seo_description'),
            canonical: url($current->url('ask')),
        );

        $questions = CommunityQuestion::query()
            ->forMarket($current->get())
            ->published()
            ->with('author')
            ->orderByDesc('published_at')
            ->limit(self::PER_PAGE)
            ->get();

        $user = $request->user();

        return Inertia::render('Ask/Index', [
            'questions' => $questions->map(fn (CommunityQuestion $q) => $this->summarise($q, $current))->all(),

            /*
             * Your own questions, including the ones still being looked at.
             *
             * Without this the feature looks broken in the exact moment
             * somebody first uses it: they press "Ask", the board reloads, and
             * their question is not on it. Their own held post is not a
             * disclosure — it is their own writing — and it is the only honest
             * way to say "we have it, we are reading it".
             */
            'mine' => $user === null ? [] : CommunityQuestion::query()
                ->forMarket($current->get())
                ->where('user_id', $user->id)
                ->whereNot('status', ModerationStatus::Published->value)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (CommunityQuestion $q) => $this->summarise($q, $current))
                ->all(),

            'canAsk' => $user !== null,

            // The same vocabulary the Gift Finder offers, so a question and a
            // brief describe a person the same way.
            'options' => $this->options(),
        ]);
    }

    /**
     * What the optional half of the form offers.
     *
     * Deliberately the Gift Finder's list rather than one of this feature's
     * own: two boards' worth of interests that mostly overlap is how "cooking"
     * ends up meaning two different things, and it means an answerer can seed a
     * product search from a question with no translation layer.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'interests' => array_map(fn (Interest $i) => [
                'value' => $i->value,
                'label' => $i->label(),
            ], Interest::cases()),
            'vibes' => array_map(fn (Vibe $v) => [
                'value' => $v->value,
                'label' => $v->label(),
            ], Vibe::cases()),
            'values' => ['sustainable', 'local', 'handmade'],
        ];
    }

    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:10', 'max:160'],
            'body' => ['nullable', 'string', 'max:2000'],

            /*
             * Euros in, cents stored — invariant #7, and the same unit as every
             * price on the site so an answer's picks can be compared with it
             * directly.
             */
            'budget_max' => ['nullable', 'numeric', 'min:1', 'max:100000'],

            /*
             * Optional structure, in the Gift Finder's own vocabulary.
             *
             * All of it nullable, and it stays that way: somebody who types one
             * sentence and presses Ask must get a question on the board. This
             * is an accelerator for people who want to be more specific, never
             * a form to complete.
             *
             * Constrained to the enums rather than free text, because the point
             * is that an answerer can search from a question without a
             * translation layer — and because free text here would be a third
             * moderation surface for no gain.
             */
            'interests' => ['array', 'max:8'],
            'interests.*' => ['string', 'in:'.implode(',', Interest::values())],
            'vibe' => ['nullable', 'string', 'in:'.implode(',', Vibe::values())],
            'values' => ['array', 'max:3'],
            'values.*' => ['string', 'in:sustainable,local,handmade'],
            'age_band' => ['nullable', 'string', 'max:20'],
            'occasion' => ['nullable', 'string', 'max:40'],
        ]);

        $question = CommunityQuestion::create([
            'market' => $current->get(),
            'user_id' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'budget_max' => isset($validated['budget_max'])
                ? (int) round((float) $validated['budget_max'] * 100)
                : null,

            // Empty arrays are stored as null: "they ticked nothing" and "they
            // ticked nothing yet" are the same thing here, and a `[]` renders
            // as an empty chip row on every card.
            'interests' => filled($validated['interests'] ?? null) ? array_values($validated['interests']) : null,
            'vibe' => $validated['vibe'] ?? null,
            'values' => filled($validated['values'] ?? null) ? array_values($validated['values']) : null,
            'age_band' => $validated['age_band'] ?? null,
            'occasion' => $validated['occasion'] ?? null,

            // Stated rather than inherited from the column default: `create()`
            // hands back the instance it built, and a value only Postgres knows
            // about is null on it.
            'status' => ModerationStatus::Pending,
        ]);

        dispatch(TriageCommunityPost::for($question));

        return redirect()
            ->to($current->url('ask'))
            ->with('status', __('site.ask.submitted'));
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $question, ?string $slug = null): Response|RedirectResponse
    {
        $found = CommunityQuestion::query()
            ->forMarket($current->get())
            ->with(['author'])
            ->find($question);

        if ($found === null || ! $found->isVisibleTo($request->user())) {
            // A held question is a 404 to everybody but its author: "this
            // exists but you may not see it" is itself information.
            throw new NotFoundHttpException;
        }

        /*
         * The slug is decoration and the id is identity, exactly as on a
         * product page. A retitled question keeps working from every link
         * already shared, and canonicalises itself on the way through.
         */
        if ($slug !== $found->slug()) {
            return redirect()->to($current->url("ask/{$found->id}/{$found->slug()}"));
        }

        app(PageMeta::class)->set(
            title: $found->title,
            description: __('site.ask.seo_question', ['title' => $found->title]),
            canonical: url($current->url("ask/{$found->id}/{$found->slug()}")),
            /*
             * A question with no answers on it is a thin page made of one
             * stranger's sentence, and a held one is not public at all. Neither
             * belongs in an index yet; both become indexable the moment somebody
             * answers, which is when the page is actually worth landing on.
             */
            robots: $found->status->isPublished() && $found->answers_count > 0
                ? null
                : 'noindex, follow',
        );

        $viewer = $request->user();

        $answers = $found->allAnswers()
            ->with(['author', 'groups'])
            ->oldest('created_at')
            ->get()
            ->filter(fn (CommunityAnswer $a) => $a->isVisibleTo($viewer))
            ->values();

        return Inertia::render('Ask/Show', [
            'question' => [
                ...$this->summarise($found, $current),
                'body' => $found->body,
                // Only its author ever reads this, and only in the general
                // form the copy allows.
                'note' => $found->user_id === $viewer?->id ? $found->moderation_note : null,
            ],

            'answers' => $answers->map(fn (CommunityAnswer $a) => [
                'id' => $a->id,
                'body' => $a->body,
                'author' => $a->author?->displayName(),
                'mine' => $a->user_id === $viewer?->id,
                'status' => $a->status->value,
                'answeredAt' => ($a->published_at ?? $a->created_at)->toIso8601String(),
                'picks' => $a->groups->map(fn (ProductGroup $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'image' => $g->image_url,
                    'price' => $g->min_price,
                    'inStock' => $g->in_stock,
                    'url' => $current->url("p/{$g->id}/{$g->slug}"),
                ])->all(),
            ])->all(),

            'canAnswer' => $viewer !== null && $found->status->isPublished(),
            'maxPicks' => self::MAX_PICKS,

            // The picker inside the answer form, mirroring the one on a shared
            // list: one route, one search, no second endpoint to gate.
            'results' => $this->search($request, $current),
            'searchTerm' => trim((string) $request->query('q', '')),
        ]);
    }

    public function answer(Request $request, CurrentMarket $current, string $market, string $question): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $found = CommunityQuestion::query()
            ->forMarket($current->get())
            ->published()
            ->find($question);

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'picks' => ['nullable', 'array', 'max:'.self::MAX_PICKS],
            'picks.*' => ['integer'],
        ]);

        $answer = CommunityAnswer::create([
            'question_id' => $found->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'status' => ModerationStatus::Pending,
        ]);

        /*
         * Picks are re-checked against the market rather than trusted.
         *
         * The ids arrive from the client, so a hand-built request could name a
         * product from another market — which would render a price in the wrong
         * currency, for a shop that does not deliver here, on a page that is
         * supposed to be about this catalogue. Invariant #2.
         */
        $groups = ProductGroup::query()
            ->forMarket($current->get())
            ->whereIn('id', $validated['picks'] ?? [])
            ->pluck('id')
            ->all();

        foreach (array_values($groups) as $position => $groupId) {
            $answer->picks()->create(['group_id' => $groupId, 'position' => $position]);
        }

        dispatch(TriageCommunityPost::for($answer));

        return back()->with('status', __('site.ask.answer_submitted'));
    }

    /**
     * The product search inside the answer form.
     *
     * A GET back to this same page carrying `?q=`, exactly as the suggestion
     * picker on a shared list does — one route and one token-free search rather
     * than a second endpoint with its own gate.
     *
     * @return list<array<string, mixed>>|null
     */
    private function search(Request $request, CurrentMarket $current): ?array
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '' || $request->user() === null) {
            return null;
        }

        $results = app(SearchService::class)->search(new SearchQuery(
            market: $current->get(),
            term: $term,
            discountedOnly: false,
            /*
             * Not public demand. `search_log` feeds the related-search chips and
             * the guide-topic queue, and a term typed while answering one
             * person's question about their mother is not a market signal.
             */
            logged: false,
        ))->groups->items();

        return array_map(fn (ProductGroup $g) => [
            'id' => $g->id,
            'title' => $g->title,
            'image' => $g->image_url,
            'price' => $g->min_price,
        ], array_slice($results, 0, 8));
    }

    /** @return array<string, mixed> */
    private function summarise(CommunityQuestion $question, CurrentMarket $current): array
    {
        return [
            'id' => $question->id,
            'title' => $question->title,
            'budget' => $question->budget_max,
            // Already labels, in the reader's language, with retired enum
            // values skipped — see `CommunityQuestion::tags()`.
            'tags' => $question->tags(),
            'answers' => $question->answers_count,
            'author' => $question->author?->displayName(),
            'status' => $question->status->value,
            'askedAt' => ($question->published_at ?? $question->created_at)->toIso8601String(),
            'url' => $current->url("ask/{$question->id}/{$question->slug()}"),
        ];
    }
}
