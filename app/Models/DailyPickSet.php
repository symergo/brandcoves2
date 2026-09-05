<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\Market;
use App\Enums\PublishStatus;
use Database\Factories\DailyPickSetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One themed set of picks — a day's edition, or a gift persona.
 *
 * The theme is doing quiet but essential work: it gives the feature an editorial
 * voice instead of a random-product firehose, and it is the reason to come back
 * tomorrow — the way you'd check a daily column.
 *
 * A persona is the same thing without a date: built by the same builder, from
 * the same finds, with the same editorial pass, and addressed by a permanent
 * slug instead. Which means **every query that means "the daily column" now has
 * to say so** — see scopeDaily(), and read its warning before adding another
 * one.
 */
class DailyPickSet extends Model
{
    /** @use HasFactory<DailyPickSetFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'kind' => CoveKind::class,
            'status' => PublishStatus::class,
            // Nullable: null reads as CoveScene::defaultFor($this->kind).
            'scene' => CoveScene::class,
            'drop_date' => 'date',
            'published_at' => 'datetime',
            // Article kinds only. A Daily and a persona leave all of these at
            // their defaults; see App\Enums\CoveKind.
            'faq' => 'array',
            'source_queries' => 'array',
            'source_volume' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * The Cove this one points its readers at.
     *
     * A Daily has always ended with "and now read this guide". Now that a guide
     * is a Cove, that is a self-reference rather than a foreign one — which is
     * also what lets a persona or a seasonal Cove carry the same footer without
     * a second column.
     *
     * @return BelongsTo<DailyPickSet, $this>
     */
    public function featured(): BelongsTo
    {
        return $this->belongsTo(self::class, 'featured_cove_id');
    }

    /**
     * What this edition was planned to be.
     *
     * Every published Cove has one, including the ones nobody planned — those
     * carry a plan minted by the build as a record. It is what "open this and
     * re-curate it" navigates to. See `CovePlan::recordFor()`.
     *
     * @return HasOne<CovePlan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(CovePlan::class, 'edition_id');
    }

    /**
     * Every Cove gets an address when it is created, and keeps it forever.
     *
     * Assigned here rather than in the builder because the builder is not the
     * only thing that creates one — the editorial API does, the content import
     * does, and a test does — and an address is not the sort of thing three
     * callers should each remember to supply. The database agrees: `slug` is
     * NOT NULL by CHECK.
     *
     * Only on create. A rebuild refreshes the products and the prose; renaming
     * the page while doing it would break every link that already points at it,
     * which is the one thing a permanent URL exists to prevent.
     */
    protected static function booted(): void
    {
        static::creating(function (self $set): void {
            if (filled($set->slug)) {
                return;
            }

            /*
             * From the title, not from `theme_slug`.
             *
             * `theme_slug` is an internal key — an evergreen theme's is
             * `theme-board-games`, in English, in every market — and it is the
             * rotation's bookkeeping rather than the name of the page. The
             * title is what the edition is called, in the language it is read
             * in: `/be-nl/daily/rond-de-tafel`.
             */
            $set->slug = static::freeSlug(
                (string) $set->market?->value,
                (string) ($set->theme_title ?: $set->theme_slug ?: 'cove'),
            );
        });
    }

    /**
     * A slug nothing in this market has taken.
     *
     * The namespace is unique per market across every kind, and a theme recurs —
     * "moederdag" comes round every year — so a collision is the normal case
     * rather than the exception. Suffixed with a counter, which reads better in
     * a URL than a date and leaves the first year's edition on the clean
     * address.
     */
    public static function freeSlug(string $market, string $base): string
    {
        $base = Str::slug($base) ?: 'cove';

        $taken = fn (string $candidate): bool => static::query()
            ->where('market', $market)
            ->where('slug', $candidate)
            ->exists();

        if (! $taken($base)) {
            return $base;
        }

        $n = 2;

        while ($taken($base.'-'.$n)) {
            $n++;
        }

        return $base.'-'.$n;
    }

    /** @return HasMany<DailyPick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(DailyPick::class, 'set_id')->orderBy('rank');
    }

    /**
     * Is this edition live yet?
     *
     * The same three conditions as the `published` scope, asked of one row.
     * A preview banner has to know whether it is looking at a draft, and
     * re-deriving that from `status` alone would call a scheduled-but-not-yet
     * dropped edition published.
     */
    public function isPublished(): bool
    {
        return $this->status === PublishStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /** @param Builder<$this> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PublishStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** @param Builder<$this> $query */
    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /**
     * The daily column, and nothing else.
     *
     * Required on every listing that means "editions", because Postgres sorts
     * `ORDER BY drop_date DESC` **NULLS FIRST**. A persona has no drop date, so
     * the moment one exists `orderByDesc('drop_date')->first()` returns *it* as
     * today's edition — on the home page, at /daily, and at the top of the
     * archive strip. Nothing errors and nothing looks wrong; the wrong page is
     * simply served.
     *
     * Deliberately an explicit scope rather than a global one. A global default
     * would also hide personas from the gift-ideas pages, which would then need
     * `withoutGlobalScope` — an inversion that reads as a mistake and gets
     * copied as a pattern.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDaily(Builder $query): void
    {
        $query->where('kind', CoveKind::Daily->value);
    }

    /** @param Builder<$this> $query */
    public function scopePersonas(Builder $query): void
    {
        $query->where('kind', CoveKind::Persona->value);
    }

    /**
     * The `/guides` URL space: buying guides, seasonal ones, advice articles.
     *
     * The counterpart to `scopeDaily()`, and it exists for the same reason —
     * "the article half" is asked for by the index, the sitemap, the hreflang
     * pairing, the link allowlist, the freshness pass and two admin screens, and
     * a `whereIn` repeated six times is a list that will be five places out of
     * date the first time a kind is added.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeArticles(Builder $query): void
    {
        $query->whereIn('kind', array_map(
            fn (CoveKind $k) => $k->value,
            array_values(array_filter(CoveKind::cases(), fn (CoveKind $k) => $k->isArticle())),
        ));
    }

    /**
     * Shop Coves: the writing about shops.
     *
     * Its own scope rather than a widened `articles()`, because that one means
     * "the /guides URL space" and a Shop Cove is read at `/shops/{slug}`.
     * Folding it in would put it in the guides index, the guides sitemap and the
     * guides hreflang set — three places where it does not belong and none of
     * which would error.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeShops(Builder $query): void
    {
        $query->where('kind', CoveKind::Shop->value);
    }

    public function isPersona(): bool
    {
        return $this->kind === CoveKind::Persona;
    }
}
