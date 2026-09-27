<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who a question in Ask others is for (owner, 2026-09-27: "ask the GiftCoves
 * community, ask your people on GiftCoves").
 *
 * Stored as `community_questions.audience`, a string with a CHECK rather than
 * a native Postgres enum, per the convention: adding a value to a PG enum
 * cannot run inside a transaction. See docs/features/ask-others.md, "Ask the
 * community or ask your people".
 */
enum AskAudience: string
{
    /**
     * The public board: read first (TriageCommunityPost), then indexable,
     * in the sitemap, and sent to the asker's people once published.
     */
    case Public = 'public';

    /**
     * The asker's friends on GiftCoves and whoever holds the link. Never on
     * the board, never in a listing a stranger can reach, `noindex`, opened
     * by an unguessable code rather than the id. Not read first: it goes only
     * to people the asker chose, as a shared list does.
     */
    case People = 'people';
}
