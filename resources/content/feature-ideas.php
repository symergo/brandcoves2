<?php

declare(strict_types=1);

/**
 * The ideas the contribute page's voting board starts with (owner, 2026-09-27:
 * "populate the feature ideas already"). See docs/features/contribute.md.
 *
 * Published by `php artisan bc:seed-feature-ideas`, and on a deployed database
 * by the migration `2026_09_28_000910_the_feature_ideas_move_in`. Keyed by a
 * stable name, which becomes `feature_ideas.seed_key`: re-running the command
 * refreshes an idea from this file only while nobody has edited it in the
 * admin (`source` still `seed`). An idea changed in the admin is kept as it
 * is. To take a shipped idea off the board, reject it in the admin rather
 * than deleting it: a deleted row is recreated by the next seed.
 *
 * ## What may be written here
 *
 * - **No dates and no promises.** "Planned" is a status, not a deadline.
 * - **Only ideas that are truly new** (owner, 2026-09-27: "remove the
 *   implemented ones or the ones that we are implementing. Truly new is eg a
 *   browser plugin"). The first seed also carried three things already built
 *   (search that reads what you mean, the fuller product page, publishing a
 *   list as a Community Cove) as `done`, and five that were our own plans or
 *   open decisions (friends saved as people, a shorter interest list, price
 *   watch on every list, Spanish gift pages, the Secret Friend draw on a
 *   profile). All eight were taken off before the board went live. The
 *   browser button stays: the extension in `extension/` is for editors
 *   importing shelves, not for visitors adding to their own lists.
 * - **Nothing about Amazon.**
 * - Plain words, in all four languages. `sort` orders ideas that share a
 *   status before the votes do (FeatureBoard).
 *
 * @return array<string, array{status: string, sort: int, nl: array{title: string, body: string}, en: array{title: string, body: string}, fr: array{title: string, body: string}, es: array{title: string, body: string}}>
 */
return [
    'follow-a-cove' => [
        'status' => 'considering',
        'sort' => 10,
        'nl' => [
            'title' => 'Een Cove volgen',
            'body' => 'Volg een Cove, van ons of van iemand anders, en krijg een melding als er iets bijkomt. Nu kan dat alleen voor de Cove van de dag, per e-mail.',
        ],
        'en' => [
            'title' => 'Follow a Cove',
            'body' => 'Follow a Cove, ours or somebody else\'s, and get a notification when something is added. Today that only exists for the Daily Cove, by email.',
        ],
        'fr' => [
            'title' => 'Suivre une Cove',
            'body' => "Suivez une Cove, la nôtre ou celle de quelqu'un d'autre, et recevez une notification quand quelque chose s'y ajoute. Aujourd'hui, cela n'existe que pour la Cove Quotidienne, par e-mail.",
        ],
        'es' => [
            'title' => 'Seguir una Cove',
            'body' => 'Sigue una Cove, nuestra o de otra persona, y recibe un aviso cuando se añada algo. Hoy eso solo existe para la Cove Diaria, por correo.',
        ],
    ],


    'browser-button' => [
        'status' => 'considering',
        'sort' => 30,
        'nl' => [
            'title' => 'Een knop in je browser',
            'body' => 'Zet vanuit elke webshop iets op je lijst met één klik op een knop in je browser, zonder de link te kopiëren en te plakken.',
        ],
        'en' => [
            'title' => 'A button in your browser',
            'body' => 'Put something from any web shop on your list with one click on a button in your browser, without copying and pasting the link.',
        ],
        'fr' => [
            'title' => 'Un bouton dans votre navigateur',
            'body' => "Ajoutez un article de n'importe quelle boutique en ligne à votre liste d'un clic sur un bouton dans votre navigateur, sans copier-coller le lien.",
        ],
        'es' => [
            'title' => 'Un botón en tu navegador',
            'body' => 'Añade algo de cualquier tienda online a tu lista con un clic en un botón de tu navegador, sin copiar y pegar el enlace.',
        ],
    ],

    'calendar-subscription' => [
        'status' => 'considering',
        'sort' => 40,
        'nl' => [
            'title' => 'Verjaardagen in je eigen agenda',
            'body' => 'Abonneer je agenda (Google, Apple, Outlook) op de verjaardagen en gelegenheden van je mensen. Wat je op GiftCoves aanpast, verschijnt vanzelf in je agenda.',
        ],
        'en' => [
            'title' => 'Birthdays in your own calendar',
            'body' => 'Subscribe your calendar (Google, Apple, Outlook) to the birthdays and occasions of your people. What you change on GiftCoves shows up in your calendar by itself.',
        ],
        'fr' => [
            'title' => 'Les anniversaires dans votre agenda',
            'body' => 'Abonnez votre agenda (Google, Apple, Outlook) aux anniversaires et aux occasions de vos proches. Ce que vous modifiez sur GiftCoves apparaît tout seul dans votre agenda.',
        ],
        'es' => [
            'title' => 'Los cumpleaños en tu propio calendario',
            'body' => 'Suscribe tu calendario (Google, Apple, Outlook) a los cumpleaños y ocasiones de tu gente. Lo que cambies en GiftCoves aparece solo en tu calendario.',
        ],
    ],







];
