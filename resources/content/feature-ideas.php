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
 * - **Open ideas only when they are truly new** (owner, 2026-09-27: "remove
 *   the implemented ones or the ones that we are implementing. Truly new is
 *   eg a browser plugin"). Five that were our own plans or open decisions
 *   came off before the board went live (friends saved as people, a shorter
 *   interest list, price watch on every list, Spanish gift pages, the Secret
 *   Friend draw on a profile). The browser button stays: the extension in
 *   `extension/` is for editors importing shelves, not visitors.
 * - **What is built stays, as `done`** (owner, the same day: "you can keep
 *   the already built part, also add in the future"). Every feature a
 *   visitor would notice gets an entry here with status `done` when it
 *   ships, in all four languages, described as it works; that is what "Al
 *   gebouwd" on the board is for.
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







    'search-for-what-you-mean' => [
        'status' => 'done',
        'sort' => 10,
        'nl' => [
            'title' => 'Zoeken op wat je bedoelt',
            'body' => 'Typ in het zoekvak wat je zoekt, zoals "cadeau voor mijn zus die van tuinieren houdt, €30-€50". We tonen wat we begrepen (voor wie, waarvan die houdt, je budget) en meteen ideeën die passen. Elk stukje haal je met één tik weg.',
        ],
        'en' => [
            'title' => 'Search for what you mean',
            'body' => 'Type what you are looking for, like "gift for my sister who loves gardening, €30-€50". We show what we understood (who it is for, what they love, your budget) and ideas that fit straight away. Remove any part with one tap.',
        ],
        'fr' => [
            'title' => 'Chercher ce que vous voulez dire',
            'body' => 'Tapez ce que vous cherchez, par exemple « cadeau pour ma sœur qui aime le jardinage, 30-50 € ». Nous montrons ce que nous avons compris (pour qui, ce que cette personne aime, votre budget) et tout de suite des idées qui correspondent. Chaque élément se retire en un geste.',
        ],
        'es' => [
            'title' => 'Buscar lo que quieres decir',
            'body' => 'Escribe lo que buscas, como «regalo para mi hermana a la que le encanta la jardinería, 30-50 €». Mostramos lo que entendimos (para quién es, qué le gusta, tu presupuesto) y enseguida ideas que encajan. Quita cualquier parte con un toque.',
        ],
    ],
    'fuller-product-page' => [
        'status' => 'done',
        'sort' => 20,
        'nl' => [
            'title' => 'Een productpagina met meer',
            'body' => 'De pagina van een product toont de prijs bij elke winkel, hoeveel mensen het op een lijst bewaarden en in welke Coves het staat, met verwante ideeën eronder. Alleen aantallen, nooit wie.',
        ],
        'en' => [
            'title' => 'A product page with more',
            'body' => 'A product\'s page shows the price at every shop, how many people saved it to a list and which Coves it is in, with related ideas below. Only counts, never who.',
        ],
        'fr' => [
            'title' => 'Une page produit plus complète',
            'body' => "La page d'un produit montre le prix dans chaque boutique, combien de personnes l'ont gardé sur une liste et dans quelles Coves il se trouve, avec des idées proches en dessous. Seulement des nombres, jamais qui.",
        ],
        'es' => [
            'title' => 'Una página de producto más completa',
            'body' => 'La página de un producto muestra el precio en cada tienda, cuántas personas lo guardaron en una lista y en qué Coves aparece, con ideas relacionadas debajo. Solo cifras, nunca quién.',
        ],
    ],
    'public-coves' => [
        'status' => 'done',
        'sort' => 30,
        'nl' => [
            'title' => 'Openbare Coves',
            'body' => 'Maak een van je lijsten openbaar als Community Cove, onder Delen. Iedereen kan hem dan vinden, bewaren of er een eigen lijst van maken. Namen, notities en wat al gekocht is blijven verborgen.',
        ],
        'en' => [
            'title' => 'Public Coves',
            'body' => 'Publish one of your lists as a Community Cove, under Share. Anyone can then find it, save it or make it their own list. Names, notes and what has been bought stay hidden.',
        ],
        'fr' => [
            'title' => 'Des Coves publiques',
            'body' => 'Publiez une de vos listes comme Cove de la communauté, sous Partager. Tout le monde peut alors la trouver, la garder ou en faire sa propre liste. Les noms, les notes et ce qui a déjà été acheté restent cachés.',
        ],
        'es' => [
            'title' => 'Coves públicas',
            'body' => 'Publica una de tus listas como Cove de la comunidad, en Compartir. Cualquiera puede encontrarla, guardarla o convertirla en su propia lista. Los nombres, las notas y lo que ya se compró siguen ocultos.',
        ],
    ],
];
