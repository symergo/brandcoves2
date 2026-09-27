<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AskAudience;
use App\Enums\Interest;
use App\Enums\ModerationStatus;
use App\Enums\Vibe;
use App\Jobs\TriageCommunityPost;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Services\Community\AskPrefill;
use App\Services\Community\PeopleQuestions;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ask others — the board where people ask what to buy and other people answer.
 *
 * The gap it fills: every other way into this site assumes you can describe
 * what you want. Search needs a noun, Find a gift needs six answers about a
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
 *
 * ## Or only your people (2026-09-27)
 *
 * The one exception, and it publishes nothing: a question asked of your people
 * only (`PeopleQuestions`) is never on the board. It opens by its link code at
 * `/ask/p/{token}`, for your friends and whoever you send the link to, the
 * way a shared list does, and so it is not read first.
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

        $user = $request->user();
        $people = app(PeopleQuestions::class);

        /*
         * Your own questions: the ones still being looked at, the ones on the
         * board, and the ones you asked your people.
         *
         * Held ones because without them the feature looks broken in the exact
         * moment somebody first uses it: they press "Ask", the board reloads,
         * and their question is not on it. Their own held post is not a
         * disclosure, it is their own writing. Published ones (since
         * 2026-09-27) because this is where the asker finds a question's link
         * again to send it on; a people question is on no other list at all.
         */
        $mine = $user === null ? collect() : CommunityQuestion::query()
            ->forMarket($current->get())
            ->where('user_id', $user->id)
            ->with('author')
            ->latest()
            ->limit(10)
            ->get();

        $questions = CommunityQuestion::query()
            ->forMarket($current->get())
            ->published()
            // Already above, under "Jouw vragen", with a share button.
            ->whereNotIn('id', $mine->pluck('id'))
            ->with('author')
            ->orderByDesc('published_at')
            ->limit(self::PER_PAGE)
            ->get();

        return Inertia::render('Ask/Index', [
            'questions' => $questions->map(fn (CommunityQuestion $q) => $this->summarise($q, $current))->all(),

            'mine' => $mine->map(fn (CommunityQuestion $q) => $this->summarise($q, $current, withShare: true))->all(),

            /*
             * Your friends' questions for their people. Reached otherwise only
             * through the notification, which is dismissed, or not sent at all
             * past the one-a-day limit. Your own page, never a public one.
             */
            'fromPeople' => $user === null ? [] : $people->fromFriends($user, $current->get())
                ->map(fn (CommunityQuestion $q) => $this->summarise($q, $current))
                ->all(),

            // For the "Your people" choice: with nobody yet, it says the link
            // is the way to reach them.
            'friendCount' => $user === null ? 0 : $people->friendCount($user),

            'canAsk' => $user !== null,

            // The same vocabulary Find a gift offers, so a question and a
            // brief describe a person the same way.
            'options' => $this->options(),

            /*
             * The form filled in from Find a gift or a list page, which carry
             * only who it is about (`?relationship=`, `?person=`, `?list=`).
             * Null when nothing is known. Never a name or a note: see
             * AskPrefill. The form opens on it, and nothing is posted until
             * the asker presses Ask.
             */
            'prefill' => app(AskPrefill::class)->build($request, $current),

            // "Ask your question" from an invitation elsewhere (Discover):
            // open the form straight away rather than make them find it.
            'open' => $request->boolean('new'),

            // Whether a published question reaches this asker's people, so
            // the form can say so (and where to change it).
            'sendsToPeople' => $user !== null && $user->ask_people_off_at === null,
        ]);
    }

    /**
     * What the optional half of the form offers.
     *
     * Deliberately Find a gift's list rather than one of this feature's
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
             * Optional structure, in Find a gift's own vocabulary.
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
            // The list it was asked from; checked against the owner below.
            'list_id' => ['nullable', 'string'],
            // Who it is for: the board (the default, and what every question
            // was before 2026-09-27) or only the asker's people.
            'audience' => ['nullable', 'string', 'in:'.implode(',', array_column(AskAudience::cases(), 'value'))],
        ]);

        /*
         * Kept only when it is the asker's own gift or group list. The id
         * arrives from the client, so it is looked up again rather than
         * trusted; anything else is dropped without a word; the question
         * itself is still asked.
         */
        $list = app(AskPrefill::class)->list($request, Owner::fromRequest($request));

        $attributes = [
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
            'wishlist_id' => $list?->id,
        ];

        /*
         * Only your people: visible at once to them and to whoever holds the
         * link, never on the board, not read first (PeopleQuestions says why).
         * The asker lands on the question with its link open to send.
         */
        if (($validated['audience'] ?? null) === AskAudience::People->value) {
            $question = app(PeopleQuestions::class)->ask($attributes);

            return redirect()
                ->to($current->url($question->path()))
                ->with('askShare', true)
                ->with('status', __('site.ask.people_only.asked'));
        }

        $question = CommunityQuestion::create([
            ...$attributes,
            // Stated rather than inherited from the column default: `create()`
            // hands back the instance it built, and a value only Postgres knows
            // about is null on it.
            'audience' => AskAudience::Public,
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

        /*
         * A people question is never opened by its id, not even by its asker:
         * ids are sequential, and an address anybody can count to is not
         * "only your people". It has one address, its link (`showPeople`).
         */
        if ($found === null || $found->isForPeople() || ! $found->isVisibleTo($request->user())) {
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
            return redirect()->to($current->url("ask/{$found->id}/{$found->slug()}"), 301);
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

        return $this->page($request, $current, $found);
    }

    /**
     * A question for the asker's people, opened by its link.
     *
     * The code is the permission, as on a shared list: anybody holding it may
     * read, and anybody signed in may answer. A wrong code, one from another
     * market, or a question an admin refused is a 404 (the refused one still
     * opens for its asker). `noindex, nofollow` and no canonical other than
     * itself: it is a private page that happens to have an address.
     */
    public function showPeople(Request $request, CurrentMarket $current, string $market, string $token): Response
    {
        $people = app(PeopleQuestions::class);
        $found = $people->find($current->get(), $token);

        if ($found === null || ! $people->mayOpen($found, $request->user())) {
            throw new NotFoundHttpException;
        }

        app(PageMeta::class)->set(
            title: $found->title,
            description: __('site.ask.people_only.description'),
            canonical: url($current->url($found->path())),
            robots: 'noindex, nofollow',
        );

        return $this->page($request, $current, $found);
    }

    /** The question page, for either audience. */
    private function page(Request $request, CurrentMarket $current, CommunityQuestion $found): Response
    {
        $viewer = $request->user();
        $asker = $viewer !== null && $viewer->id === $found->user_id;

        $answers = $found->allAnswers()
            ->with(['author', 'groups'])
            ->oldest('created_at')
            ->get()
            ->filter(fn (CommunityAnswer $a) => $a->isVisibleTo($viewer))
            ->values();

        return Inertia::render('Ask/Show', [
            'question' => [
                // The link to send, for the asker alone: on a people question
                // it is the permission itself, and on a board question it is
                // the asker's to pass around.
                ...$this->summarise($found, $current, withShare: $asker),
                'body' => $found->body,
                // Only its author ever reads this, and only in the general
                // form the copy allows.
                'note' => $asker ? $found->moderation_note : null,
                'answerUrl' => $current->url($found->isForPeople()
                    ? $found->path().'/answers'
                    : "ask/{$found->id}/answers"),
            ],

            /*
             * Straight after asking your people: the page opens with the link
             * to send (the owner's "provide a share link after posting").
             * Flashed by `store()`, so a reload does not open it again.
             */
            'openShare' => $asker && (bool) $request->session()->get('askShare', false),

            'answers' => $answers->map(fn (CommunityAnswer $a) => [
                'id' => $a->id,
                'body' => $a->body,
                'author' => $a->author?->displayName(),
                'mine' => $a->user_id === $viewer?->id,
                'status' => $a->status->value,
                'answeredAt' => ($a->published_at ?? $a->created_at)->toIso8601String(),
                'picks' => $a->groups->map(fn (ProductGroup $g) => [
                    'id' => $g->id,
                    'title' => $g->displayTitle(),
                    'image' => $g->image_url,
                    'price' => $g->min_price,
                    'inStock' => $g->in_stock,
                    'url' => $current->url("p/{$g->id}/{$g->slug}"),
                ])->all(),
            ])->all(),

            /*
             * The list the asker asked from, for the asker alone: every pick in
             * an answer gets "save to <list>" as its first choice, so an idea
             * from somebody else is one press from the list it was asked for.
             * Null for everybody else, and when the list was deleted or is no
             * longer theirs.
             */
            'into' => $this->askersList($found, $request),

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

        return $this->storeAnswer($request, $current, $found);
    }

    /**
     * Answer a question for somebody's people, by its link.
     *
     * Signed in, like every answer (the route says so), and holding the link,
     * which is the permission: a friend told by a notification and somebody
     * the asker sent it to are the same here. A refused question takes no
     * answers, from anybody.
     */
    public function answerPeople(Request $request, CurrentMarket $current, string $market, string $token): RedirectResponse
    {
        abort_if($request->user() === null, 403);

        $found = app(PeopleQuestions::class)->find($current->get(), $token);

        if ($found === null || ! $found->status->isPublished()) {
            throw new NotFoundHttpException;
        }

        return $this->storeAnswer($request, $current, $found);
    }

    private function storeAnswer(Request $request, CurrentMarket $current, CommunityQuestion $found): RedirectResponse
    {
        $user = $request->user();

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

        // Read first on either audience: see PeopleQuestions for why an
        // answer on a people question is not exempt.
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
            'title' => $g->displayTitle(),
            'image' => $g->image_url,
            'price' => $g->min_price,
        ], array_slice($results, 0, 8));
    }

    /** @return array{id: string, title: string, kind: string}|null */
    private function askersList(CommunityQuestion $question, Request $request): ?array
    {
        $viewer = $request->user();

        if ($viewer === null || $question->user_id !== $viewer->id || $question->wishlist_id === null) {
            return null;
        }

        $list = Wishlist::query()->find($question->wishlist_id);

        if ($list === null || ! $list->isOwnedBy(Owner::fromRequest($request))) {
            return null;
        }

        return ['id' => $list->id, 'title' => $list->displayTitle(), 'kind' => $list->kind->value];
    }

    /**
     * One question as a card or a page header.
     *
     * `withShare` adds the absolute link to send, for the asker's own eyes
     * only (their list, their question page), and only once there is
     * something to send: a held board question's link is a 404 to everybody
     * else, so offering it would hand people a dead link.
     *
     * @return array<string, mixed>
     */
    private function summarise(CommunityQuestion $question, CurrentMarket $current, bool $withShare = false): array
    {
        $url = $current->url($question->path());

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
            'url' => $url,
            'audience' => ($question->audience ?? AskAudience::Public)->value,
            'shareUrl' => $withShare && $question->status->isPublished() ? url($url) : null,
        ];
    }
}
