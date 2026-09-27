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
 * - **Nothing that already exists described as new.** Three of the ideas the
 *   owner listed were already built when this file was written (search that
 *   reads what you mean, the fuller product page, publishing a list as a
 *   Community Cove); they are here as `done`, described as they work today,
 *   so the board shows what came of earlier ideas. The owner can change any
 *   status in the admin.
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

    'friends-saved-as-people' => [
        'status' => 'considering',
        'sort' => 20,
        'nl' => [
            'title' => 'Vrienden meteen bewaren als persoon',
            'body' => 'Wie je vriend wordt op GiftCoves, bewaren we meteen als persoon in Mijn mensen. Zo hou je hun interesses, je budget en wat je gaf bij zonder die persoon nog eens toe te voegen.',
        ],
        'en' => [
            'title' => 'Save friends as people automatically',
            'body' => 'When somebody becomes your friend on GiftCoves, they are saved as a person in My people straight away. You can then keep their interests, your budget and what you gave without adding them a second time.',
        ],
        'fr' => [
            'title' => 'Garder vos amis dans Mes proches, automatiquement',
            'body' => "Quand quelqu'un devient votre ami sur GiftCoves, il est tout de suite enregistré dans Mes proches. Vous notez alors ses centres d'intérêt, votre budget et ce que vous avez offert sans l'ajouter une seconde fois.",
        ],
        'es' => [
            'title' => 'Guardar a tus amigos en Mi gente, automáticamente',
            'body' => 'Cuando alguien se convierte en tu amigo en GiftCoves, se guarda enseguida en Mi gente. Así anotas sus intereses, tu presupuesto y lo que regalaste sin añadirlo por segunda vez.',
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

    'shorter-interest-list' => [
        'status' => 'considering',
        'sort' => 50,
        'nl' => [
            'title' => 'Een kortere lijst interesses',
            'body' => 'Als je kiest waarvan iemand houdt, zie je eerst de interesses die het vaakst gekozen worden. De rest staat achter "Meer".',
        ],
        'en' => [
            'title' => 'A shorter list of interests',
            'body' => 'When you choose what somebody loves, you first see the interests people pick most often. The rest sit behind "More".',
        ],
        'fr' => [
            'title' => "Une liste de centres d'intérêt plus courte",
            'body' => "Quand vous choisissez ce que quelqu'un aime, vous voyez d'abord les centres d'intérêt les plus souvent choisis. Les autres sont derrière « Plus ».",
        ],
        'es' => [
            'title' => 'Una lista de intereses más corta',
            'body' => 'Cuando eliges lo que le gusta a alguien, ves primero los intereses que más se eligen. El resto está en «Más».',
        ],
    ],

    'price-watch-all-lists' => [
        'status' => 'considering',
        'sort' => 60,
        'nl' => [
            'title' => 'Prijzen volgen op al je lijsten tegelijk',
            'body' => 'Nu zet je prijsopvolging per lijst aan. Met één knop zou het aan staan voor al je bestaande lijsten, met één e-mail als er iets goedkoper wordt.',
        ],
        'en' => [
            'title' => 'Watch prices on all your lists at once',
            'body' => 'Today you switch price watching on list by list. One switch would turn it on for all your existing lists, with one email when something gets cheaper.',
        ],
        'fr' => [
            'title' => "Suivre les prix de toutes vos listes d'un coup",
            'body' => "Aujourd'hui, le suivi des prix s'active liste par liste. Un seul bouton l'activerait pour toutes vos listes existantes, avec un seul e-mail quand quelque chose baisse.",
        ],
        'es' => [
            'title' => 'Seguir los precios de todas tus listas a la vez',
            'body' => 'Hoy el seguimiento de precios se activa lista por lista. Un solo interruptor lo activaría para todas tus listas, con un solo correo cuando algo baje de precio.',
        ],
    ],

    'gift-pages-in-spanish' => [
        'status' => 'considering',
        'sort' => 70,
        'nl' => [
            'title' => 'Cadeau-ideeënpagina\'s ook in het Spaans',
            'body' => 'De pagina\'s met cadeau-ideeën per persoon en interesse, zoals "voor papa die graag kookt", bestaan in het Nederlands, het Frans en het Engels. Ook in het Spaans, voor wie in Spanje een cadeau zoekt.',
        ],
        'en' => [
            'title' => 'Gift idea pages in Spanish too',
            'body' => 'The pages of gift ideas per person and interest, like "for dad who loves cooking", exist in Dutch, French and English. In Spanish too, for people looking for a gift in Spain.',
        ],
        'fr' => [
            'title' => "Des pages d'idées cadeaux en espagnol aussi",
            'body' => "Les pages d'idées cadeaux par personne et par centre d'intérêt, comme « pour papa qui aime cuisiner », existent en néerlandais, en français et en anglais. En espagnol aussi, pour qui cherche un cadeau en Espagne.",
        ],
        'es' => [
            'title' => 'Páginas de ideas de regalo también en español',
            'body' => 'Las páginas de ideas de regalo por persona e interés, como «para papá al que le encanta cocinar», existen en neerlandés, francés e inglés. También en español, para quien busca un regalo en España.',
        ],
    ],

    'secret-friend-on-their-page' => [
        'status' => 'considering',
        'sort' => 80,
        'nl' => [
            'title' => 'Wie je trok bij Geheime Vriend, ook op hun pagina',
            'body' => 'Nu zie je wie je trok alleen in de Geheime Vriend zelf. Op de pagina van die persoon in Mijn mensen zou dan ook staan dat jij hen trok. Alleen jij ziet dat.',
        ],
        'en' => [
            'title' => 'Who you drew for Secret Friend, on their page too',
            'body' => 'Today you only see who you drew inside the Secret Friend itself. Their page in My people would then say that you drew them as well. Only you would see it.',
        ],
        'fr' => [
            'title' => "Qui vous avez tiré à l'Ami Secret, aussi sur sa page",
            'body' => "Aujourd'hui, vous ne voyez qui vous avez tiré que dans l'Ami Secret lui-même. La page de cette personne dans Mes proches le dirait aussi. Vous seul le verriez.",
        ],
        'es' => [
            'title' => 'A quién te tocó en el Amigo invisible, también en su página',
            'body' => 'Hoy solo ves a quién te tocó dentro del propio Amigo invisible. Su página en Mi gente también lo diría. Solo tú lo verías.',
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
