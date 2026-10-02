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

    // Owner, 2026-09-27: a "Tinder style" swipe for yourself, with no end, that
    // builds a long wish list and drives what others are suggested for you.
    // Done 2026-09-29: Swipe gifts puts the yeses on your list, and swiping for
    // yourself feeds My taste, which friends' searches for you start from.
    'swipe-for-yourself' => [
        'status' => 'done',
        'sort' => 50,
        'nl' => [
            'title' => 'Swipe wat je zelf leuk vindt',
            'body' => 'Veeg naar rechts wat je wilt, naar links wat niet, zo lang je zin hebt. Alles wat je leuk vond komt op je verlanglijst, en wie een cadeau voor jou zoekt, krijgt ideeën die daarop lijken.',
        ],
        'en' => [
            'title' => 'Swipe what you like yourself',
            'body' => 'Swipe right on what you want and left on what you don\'t, for as long as you like. Everything you liked goes on your wish list, and anyone looking for a present for you gets ideas like it.',
        ],
        'fr' => [
            'title' => 'Swipez ce qui vous plaît',
            'body' => "Glissez à droite ce que vous voulez, à gauche ce que vous ne voulez pas, aussi longtemps que vous le souhaitez. Tout ce qui vous a plu va sur votre liste d'envies, et qui cherche un cadeau pour vous reçoit des idées qui y ressemblent.",
        ],
        'es' => [
            'title' => 'Desliza lo que te gusta',
            'body' => 'Desliza a la derecha lo que quieres y a la izquierda lo que no, todo el tiempo que quieras. Todo lo que te gustó va a tu lista de deseos, y quien busque un regalo para ti recibe ideas parecidas.',
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
    'thumbs-on-gift-ideas' => [
        'status' => 'done',
        'sort' => 40,
        'nl' => [
            'title' => 'Duim omhoog of omlaag bij cadeaus',
            'body' => 'Geef een idee in Cadeau vinden een duim omhoog en het blijft staan, met meer zoals dit. Een duim omlaag ruilt het voor een ander. Voor iemand uit Mijn mensen onthouden we het alleen voor die persoon: wat je afwees komt voor hen niet meer terug. De duimen van iedereen samen tellen pas mee als genoeg verschillende mensen erover stemden, en nooit zichtbaar wie.',
        ],
        'en' => [
            'title' => 'Thumbs up or down on gift ideas',
            'body' => 'Give an idea in Find a gift a thumbs up and it stays, with more like it. A thumbs down swaps it for another. For one of your people we remember it for them only: what you turned down never comes back for them. Everybody\'s thumbs together count only once enough different people voted, and never show who.',
        ],
        'fr' => [
            'title' => 'Pouce levé ou baissé sur les idées cadeaux',
            'body' => "Un pouce levé sur une idée dans Trouver un cadeau la garde, avec d'autres du même genre. Un pouce baissé la remplace par une autre. Pour une de vos personnes, nous le retenons pour elle seule : ce que vous avez refusé ne revient plus pour elle. Les pouces de tout le monde ne comptent qu'une fois que suffisamment de personnes différentes ont voté, et on ne voit jamais qui.",
        ],
        'es' => [
            'title' => 'Pulgar arriba o abajo en las ideas de regalo',
            'body' => 'Un pulgar arriba en una idea de Encontrar un regalo la mantiene, con más como ella. Un pulgar abajo la cambia por otra. Para una de tus personas lo recordamos solo para ella: lo que rechazaste no vuelve para ella. Los pulgares de todos juntos solo cuentan cuando votaron suficientes personas distintas, y nunca se ve quién.',
        ],
    ],
    'ask-your-people' => [
        'status' => 'done',
        'sort' => 50,
        'nl' => [
            'title' => 'Vraag het aan je mensen',
            'body' => 'Bij Vraag het aan anderen kies je aan wie je het vraagt: de GiftCoves-gemeenschap op het openbare bord, of alleen je mensen. Je vrienden op GiftCoves krijgen dan meteen een melding, en je krijgt een link om te sturen naar wie je wilt. Die vraag staat niet op het bord.',
        ],
        'en' => [
            'title' => 'Ask your people',
            'body' => 'In Ask others you choose who you ask: the GiftCoves community on the public board, or only your people. Your friends on GiftCoves then get a notification straight away, and you get a link to send to anyone you like. That question is not on the board.',
        ],
        'fr' => [
            'title' => 'Demandez à vos proches',
            'body' => "Dans Demandez aux autres, vous choisissez à qui vous demandez : la communauté GiftCoves sur le tableau public, ou seulement vos proches. Vos amis sur GiftCoves reçoivent alors tout de suite une notification, et vous recevez un lien à envoyer à qui vous voulez. Cette question n'est pas sur le tableau.",
        ],
        'es' => [
            'title' => 'Pregunta a tu gente',
            'body' => 'En Pregunta a los demás eliges a quién preguntas: la comunidad de GiftCoves en el tablón público, o solo tu gente. Tus amigos en GiftCoves reciben entonces un aviso enseguida, y tú recibes un enlace para enviarlo a quien quieras. Esa pregunta no está en el tablón.',
        ],
    ],
    'undo-remove-from-list' => [
        'status' => 'done',
        'sort' => 60,
        'nl' => [
            'title' => 'Ongedaan maken als je iets van je lijst haalt',
            'body' => 'Haal je iets van je lijst, dan zegt een bericht onderaan waar het vandaan kwam, met Ongedaan maken erbij. Druk erop en het staat er weer, met je notitie en alles wat anderen erbij deden.',
        ],
        'en' => [
            'title' => 'Undo when you take something off your list',
            'body' => 'Take something off your list and a message at the bottom says which list it left, with Undo beside it. Press it and it is back, with your note and everything other people did with it.',
        ],
        'fr' => [
            'title' => 'Annuler quand vous retirez quelque chose de votre liste',
            'body' => 'Retirez quelque chose de votre liste et un message en bas indique de quelle liste il est parti, avec Annuler à côté. Appuyez dessus et il revient, avec votre note et tout ce que les autres en avaient fait.',
        ],
        'es' => [
            'title' => 'Deshacer cuando quitas algo de tu lista',
            'body' => 'Quita algo de tu lista y un mensaje abajo dice de qué lista salió, con Deshacer al lado. Púlsalo y vuelve a estar, con tu nota y todo lo que los demás hicieron con él.',
        ],
    ],
    'persona-top-ten' => [
        'status' => 'done',
        'sort' => 70,
        'nl' => [
            'title' => 'Een top 10 van de week bij cadeaus per type',
            'body' => 'Onderaan een pagina met cadeaus voor een type persoon, zoals de thuiskok, staat een top 10: wat binnen dat thema nu het meest gekocht en gewenst wordt, uit de bestsellerlijsten van de winkels en hoeveel verlanglijsten het bevatten. Elke maandag opnieuw berekend, nooit met de hand.',
        ],
        'en' => [
            'title' => 'A top 10 of the week on every gift idea',
            'body' => 'At the bottom of a gift idea about a kind of person, such as the home cook, is a top 10: what is most bought and wanted in that theme right now, from the shops\' bestseller charts and how many wish lists hold it. Worked out again every Monday, never by hand.',
        ],
        'fr' => [
            'title' => 'Un top 10 de la semaine sur chaque idée cadeau',
            'body' => "En bas d'une idée cadeau sur un type de personne, comme le cuisinier du dimanche, un top 10 : ce qui se vend et se souhaite le plus dans ce thème en ce moment, d'après les meilleures ventes des boutiques et le nombre de listes de souhaits qui le contiennent. Recalculé chaque lundi, jamais à la main.",
        ],
        'es' => [
            'title' => 'Un top 10 de la semana en cada idea de regalo',
            'body' => 'Al final de una idea de regalo sobre un tipo de persona, como quien cocina en casa, hay un top 10: lo que más se compra y se desea en ese tema ahora mismo, según las listas de más vendidos de las tiendas y cuántas listas de deseos lo incluyen. Se recalcula cada lunes, nunca a mano.',
        ],
    ],
    'occasion-coves' => [
        'status' => 'done',
        'sort' => 80,
        'nl' => [
            'title' => 'Cadeaus per gelegenheid',
            'body' => 'Bij de cadeaus per type staat nu ook een rij per gelegenheid: Moederdag, een verjaardag, een housewarming, een pensioen. Elke pagina heeft gekozen producten met uitleg, tabbladen per budget en een top 10 van de week.',
        ],
        'en' => [
            'title' => 'Gifts per occasion',
            'body' => 'Beside the gifts per type there is now a row per occasion: Mother\'s Day, a birthday, a housewarming, a retirement. Each page has chosen products with a word on each, tabs per budget and a top 10 of the week.',
        ],
        'fr' => [
            'title' => 'Des cadeaux par occasion',
            'body' => 'À côté des cadeaux par type, il y a maintenant une rangée par occasion : la fête des mères, un anniversaire, une pendaison de crémaillère, un départ à la retraite. Chaque page propose des produits choisis et commentés, des onglets par budget et un top 10 de la semaine.',
        ],
        'es' => [
            'title' => 'Regalos por ocasión',
            'body' => 'Junto a los regalos por tipo hay ahora una fila por ocasión: el Día de la Madre, un cumpleaños, una inauguración de casa, una jubilación. Cada página tiene productos elegidos y comentados, pestañas por presupuesto y un top 10 de la semana.',
        ],
    ],
    // The swiping half of 'swipe-for-yourself' above; My taste is the other.
    'swipe-gifts' => [
        'status' => 'done',
        'sort' => 95,
        'nl' => [
            'title' => 'Swipe door cadeaus',
            'body' => 'In Cadeau vinden, onder het zoekvak: één product tegelijk. Naar rechts zet het op de lijst, naar links sla je over, zo lang je wilt. Wat je kiest, stuurt wat daarna komt. Met Stop ga je naar de lijst.',
        ],
        'en' => [
            'title' => 'Swipe through gifts',
            'body' => 'In Find a gift, under the search box: one product at a time. Right puts it on the list, left passes, for as long as you like. What you choose steers what comes next. Stop takes you to the list.',
        ],
        'fr' => [
            'title' => 'Balayez des cadeaux',
            'body' => 'Dans Trouver un cadeau, sous la recherche : un produit à la fois. À droite, il va sur la liste ; à gauche, vous passez, aussi longtemps que vous voulez. Vos choix orientent la suite. Arrêter vous mène à la liste.',
        ],
        'es' => [
            'title' => 'Desliza entre regalos',
            'body' => 'En Buscar un regalo, bajo el buscador: un producto cada vez. A la derecha va a la lista, a la izquierda lo pasas, todo el tiempo que quieras. Lo que eliges guía lo que viene. Parar te lleva a la lista.',
        ],
    ],
    'my-taste' => [
        'status' => 'done',
        'sort' => 96,
        'nl' => [
            'title' => 'Mijn smaak',
            'body' => 'Bewaar in je accountmenu wat je graag krijgt, of speel Dit of dat voor jezelf en bewaar het resultaat. Vrienden op GiftCoves die een cadeau voor je zoeken, beginnen ermee. Geen budget: wat zij uitgeven, kiezen ze zelf.',
        ],
        'en' => [
            'title' => 'My taste',
            'body' => 'Keep what you like to get in your account menu, or play This or that for yourself and keep the result. Friends on GiftCoves who look for a gift for you start from it. No budget: what they spend is their choice.',
        ],
        'fr' => [
            'title' => 'Mes goûts',
            'body' => "Gardez dans le menu de votre compte ce que vous aimez recevoir, ou jouez à Ceci ou cela pour vous et gardez le résultat. Vos amis sur GiftCoves qui cherchent un cadeau pour vous partent de là. Pas de budget : ce qu'ils dépensent, ils le choisissent.",
        ],
        'es' => [
            'title' => 'Mis gustos',
            'body' => 'Guarda en el menú de tu cuenta lo que te gusta recibir, o juega a Esto o aquello para ti y guarda el resultado. Tus amigos en GiftCoves que buscan un regalo para ti empiezan desde ahí. Sin presupuesto: lo que gastan lo eligen ellos.',
        ],
    ],
    'man-or-woman' => [
        'status' => 'done',
        'sort' => 97,
        'nl' => [
            'title' => 'Man of vrouw',
            'body' => 'Naast de leeftijd zeg je, als je wilt, of het een man of een vrouw is: in Cadeau vinden, bij iemand uit Mijn mensen en in Mijn smaak. Cadeaus die duidelijk voor de ander bedoeld zijn, vallen dan weg; al de rest blijft.',
        ],
        'en' => [
            'title' => 'Man or woman',
            'body' => 'Beside the age you can say, if you like, whether they are a man or a woman: in Find a gift, for one of your people and in My taste. Gifts clearly meant for the other then drop out; everything else stays.',
        ],
        'fr' => [
            'title' => 'Homme ou femme',
            'body' => "À côté de l'âge, vous pouvez dire, si vous le souhaitez, s'il s'agit d'un homme ou d'une femme : dans Trouver un cadeau, pour l'un de vos proches et dans Mes goûts. Les cadeaux clairement destinés à l'autre disparaissent alors ; tout le reste reste.",
        ],
        'es' => [
            'title' => 'Hombre o mujer',
            'body' => 'Junto a la edad puedes decir, si quieres, si es hombre o mujer: en Buscar un regalo, para una de tus personas y en Mis gustos. Los regalos claramente pensados para el otro desaparecen; todo lo demás se queda.',
        ],
    ],
    'find-a-gift-for-myself' => [
        'status' => 'done',
        'sort' => 90,
        'nl' => [
            'title' => 'Cadeau vinden voor jezelf',
            'body' => 'In Cadeau vinden kun je nu ook Voor mezelf kiezen. De vragen gaan dan over jou, Dit of dat vraagt niet meer voor wie het is, en je bewaart wat je vindt op je eigen lijst.',
        ],
        'en' => [
            'title' => 'Find a gift for yourself',
            'body' => 'In Find a gift you can now choose For myself. The questions are then about you, This or that no longer asks who it is for, and you save what you find to your own list.',
        ],
        'fr' => [
            'title' => 'Trouver un cadeau pour soi',
            'body' => "Dans Trouver un cadeau, vous pouvez maintenant choisir Pour moi. Les questions portent alors sur vous, Ceci ou cela ne demande plus pour qui c'est, et vous gardez ce que vous trouvez sur votre propre liste.",
        ],
        'es' => [
            'title' => 'Buscar un regalo para ti',
            'body' => 'En Buscar un regalo ahora puedes elegir Para mí. Las preguntas tratan entonces de ti, Esto o aquello ya no pregunta para quién es, y guardas lo que encuentras en tu propia lista.',
        ],
    ],
    'daily-cove-archive' => [
        'status' => 'done',
        'sort' => 98,
        'nl' => [
            'title' => 'Alle Coves van de dag',
            'body' => 'Bovenaan elke Cove van de dag, en onder die van vandaag op de startpagina, staat nu Alle Coves van de dag: een archief met elke Cove van de dag die verscheen, per maand en de nieuwste eerst.',
        ],
        'en' => [
            'title' => 'Every Cove of the day',
            'body' => 'At the top of each Cove of the day, and under today\'s on the home page, there is now Every Cove of the day: an archive of every Cove of the day so far, by month and newest first.',
        ],
        'fr' => [
            'title' => 'Toutes les Coves du jour',
            'body' => 'En haut de chaque Cove du jour, et sous celle d\'aujourd\'hui sur la page d\'accueil, il y a maintenant Toutes les Coves du jour : les archives de chaque Cove du jour parue, par mois et les plus récentes d\'abord.',
        ],
        'es' => [
            'title' => 'Todas las Coves del día',
            'body' => 'Arriba de cada Cove del día, y bajo la de hoy en la página de inicio, ahora está Todas las Coves del día: el archivo de cada Cove del día publicada, por mes y las más recientes primero.',
        ],
    ],
];
