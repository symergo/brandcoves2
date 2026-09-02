<?php

declare(strict_types=1);

/** Dutch, serves both the be-nl and nl-nl markets. */
return [
    'nav' => [
        'search' => 'Zoek',
        'feedback' => 'Feedback',
        'organise' => 'Organiseer',
        'discover' => 'Ontdek',
        'submenu' => 'Wat zit er in :section',
        'gift' => 'Cadeauzoeker',
        'daily' => 'Cove van de dag',
        'guides' => 'Koopgidsen',
        'surprise' => 'Verrassingscove',
        'lists' => 'Mijn lijsten',
        'shared_lists' => 'Gedeelde lijsten',
        'group_lists' => 'Groepslijsten',
        'notifications' => 'Meldingen',
        'sign_in' => 'Inloggen',
        'sign_out' => 'Uitloggen',
        'admin' => 'Beheer',
        'main' => 'Hoofdmenu',
        'account' => 'Account',
        'skip' => 'Naar de inhoud',
        'choose_market' => 'Kies je regio',
        'choose_language' => 'Kies je taal',
        'countries' => [
            'be' => 'België',
            'nl' => 'Nederland',
            'int' => 'Internationaal',
            'es' => 'Spanje',
        ],
        /*
         * De Cove-soorten, zoals het Ontdek-menu ze toont. Drie vormen van
         * hetzelfde: een editie die elke ochtend verandert, een plank rond een
         * persoon, en een lang verhaal rond een onderwerp.
         *
         * Het bijvoeglijk naamwoord vertaalt, de naam niet — zie
         * localisation.md.
         */
        'gift_coves' => 'Cadeau Coves',
        'all_coves' => 'Alle Coves',
        'brand_coves' => 'Merk Coves',

        /*
         * Deze staat bewust NIET in de rij hierboven, en heeft geen "Cove"
         * in zijn naam.
         *
         * De andere planken zijn een vorm — een cadeau, een merk, een winkel
         * — en "Cove" is het woord voor wat wij daarvan maken. Deze plank is
         * geen vorm maar een belofte: hier leer je beter kopen. "Inspiratie
         * Coves" zei dat niet, en een bezoeker die koopadvies zoekt klikt
         * niet op inspiratie.
         *
         * Vertaalt dus volledig, anders dan de Cove-namen: Slim kopen / Shop
         * Smarter / Acheter malin / Comprar mejor. De sleutel heet `smart`
         * omdat dat het enige woord is dat in alle vier terugkomt.
         */
        'smart' => 'Slim kopen',

        'hint_daily' => 'Elke ochtend nieuw',
        'hint_surprise' => 'Iets zeldzaams, niet iets populairs',
        'hint_smart' => 'Koopadvies en gidsen per onderwerp',
        'hint_gift_coves' => 'Ideeën rond één persoon',
        'hint_all_coves' => 'Alles wat we gepubliceerd hebben',
        'hint_ask' => 'Laat anderen iets voorstellen',

        'santa' => 'Geheime Vriend',

        // Namen, geen woorden: de Coves heten in elke taal hetzelfde, net als
        // GiftCoves zelf. Een vertaalde naam is een tweede naam.
        'cove' => 'Gift Cove',
        'discover_cove' => 'Discover Cove',
    ],

    'home' => [
        // Zie de toelichting bij deze sleutels in lang/en/site.php.
        'seo_description' => 'Doorzoek bol, Amazon en honderden winkels tegelijk. Hou verlanglijstjes bij, deel ze, leg samen in voor één cadeau en organiseer een Geheime Vriend.',
        'title' => 'GiftCoves verlanglijstjes: cadeaus geven en ontvangen aan de beste prijs',
        'headline_1' => 'Iets wat het geven waard is.',
        'headline_2' => 'Ook aan jezelf.',
        'search_placeholder' => 'Zoek een cadeau of scan een streepjescode',
        'recent_heading' => 'Recent gezocht',
        'cta_gift' => 'Vind een cadeau',
        'today_badge' => 'Cove van vandaag',
        'today_cta' => 'Bekijk de vondsten van vandaag',
        /*
         * The persona band, worded as the shelf at /gift-ideas words
         * itself. Two headings for one thing that read differently is
         * how a visitor ends up unsure whether they are the same page.
         */
        'personas_heading' => 'Cadeau-ideeën, per type',
        'personas_intro' => 'Cadeaus gekozen rond een persoon in plaats van een datum: de kruidenliefhebber, de vader die alles al heeft, de vriend die leest.',
        'personas_all' => 'Alle cadeau-ideeën',

        'coves_heading' => 'Coves',
        'coves_intro' => 'Lange verhalen rond één thema, waarbij elk merk en elk product doorlinkt naar een live zoekopdracht.',
        'coves_all' => 'Alle Coves',
        'coves_volume' => ':count zoekopdrachten per maand',
        'organise_intro' => 'Eén plek voor wat jij wilt, wat je voor anderen zoekt, en wat jullie samen kopen.',
        'organise_group_hint' => 'Eén cadeau, meerdere mensen, en niemand hoeft achter het geld aan.',
        'organise_occasion' => 'Gelegenheid',
        'organise_occasion_hint' => 'Zet een datum op een lijst — een verjaardag, een huwelijk, Kerst — en iedereen met de link weet waarvoor hij is.',
        'organise_registry_on' => ':occasion op :date',
        'gifting_lists' => 'Lijstjes',
        'gifting_lists_count' => ':count lijstjes onderweg',
        'gifting_santa' => 'Geheime Vriend',
        'gifting_santa_hint' => 'Een groep, een trekking, niemand weet wie wie heeft.',
        'gifting_santa_count' => ':count groepen die jij regelt',
    ],

    'search' => [
        'title' => 'Zoeken',
        'placeholder' => 'Zoek een cadeau of scan een streepjescode',
        'pasted_searched' => 'Dat is een Amazon-link. We lezen er :terms in en zochten daarnaar bij de winkels die we volgen.',
        'pasted_unreadable' => 'Dat is een Amazon-link, maar er staat geen productnaam in die we kunnen lezen, alleen de Amazon-code. Kopieer de langere link met de producttitel erin, of zoek het product op naam.',
        'pasted_shortlink' => 'Dat is een verkorte Amazon-link, en we openen geen links om te zien waar ze heen gaan. Open hem zelf en plak het volledige adres, of zoek het product op naam.',
        'submit' => 'Zoeken',
        'searching' => 'Bezig met zoeken…',
        'results_for' => 'Resultaten voor ":term"',
        'browse' => 'Blader door de catalogus',
        'empty' => 'Niets gevonden voor ":term".',
        'empty_filters' => 'Geen producten voldoen aan deze filters.',
        'clear_filters' => 'Alle filters wissen',
        'sort' => 'Sorteren',
        'sort_relevance' => 'Meest relevant',
        'sort_price_asc' => 'Goedkoopste eerst',
        'sort_price_desc' => 'Duurste eerst',
        'sort_discount' => 'Grootste korting',
        'sort_newest' => 'Nieuwste',
        'view_grid' => 'Raster',
        'view_store' => 'Per winkel',
        'filters' => 'Filters',
        'brand' => 'Merk',
        'shop' => 'Winkel',
        'all_shops' => 'Alle winkels',
        'only_shop' => 'Alleen :shop tonen',
        'hide_shop' => ':shop niet meer tonen',
        'in_stock_only' => 'Alleen op voorraad',
        'discounted_only' => 'Alleen met korting',
        // Zie de toelichting bij deze sleutels in lang/en/site.php.
        'amazon_search' => 'Zoek :term ook op Amazon',
        'amazon_search_any' => 'Probeer eens bij Amazon te zoeken',
        'amazon_search_host' => 'Opent :host',
        'previous' => 'Vorige',
        'next' => 'Volgende',
        'page_of' => 'Pagina :current van :last',
        'seo_term' => 'Zoek :term op bol, Amazon en honderden winkels, en zie wat elke winkel ervoor vraagt.',

        /*
         * De woordenschat van de resultaten, boven het raster. Verving vier
         * alinea's met statistiek: die getallen klopten, maar telden op wat al
         * op het scherm stond. De woorden zijn hier het bruikbare deel, en als
         * link zijn ze meteen navigatie.
         */
        'terms_heading' => 'Komt vaak voor in deze resultaten',
        'seo_default' => 'Ontdek producten en merken op bol, Amazon en honderden winkels tegelijk, met een link naar elke winkel die ze verkoopt.',
    ],

    /*
     * Merkpagina's. Elke regel wordt alleen getoond als het bijbehorende getal
     * bestaat, zie App\Services\Seo\BrandCopy.
     */
    'brand' => [
        'title' => ':brand',
        'heading' => ':brand',
        'seo_description' => 'Alles van :brand dat we gevonden hebben, met de prijs bij elke winkel die we volgen.',
        'crumb' => 'Merken',
        'index_title' => 'Merken',
        'index_seo_title' => 'Elk merk in de catalogus, en de winkels die het verkopen',
        'index_seo_description' => 'Elk merk in de catalogus, met actuele prijzen van bol, Amazon en honderden winkels die het verkopen.',
        'index_intro' => 'Alle merken in de catalogus, met actuele prijzen van de winkels die ze verkopen.',
        'index_empty' => 'Nog geen merken in deze regio.',
        'products_heading' => 'Producten van :brand',
        'coves_heading' => 'Coves waarin :brand voorkomt',
        'related_heading' => 'Andere merken die mensen bekijken',
        'empty' => 'Er is momenteel niets van :brand op voorraad.',
        'and' => 'en',
        // Aanbiedingen van een bron die we wel mogen tonen maar niet bewaren.
        'narrowed_to' => 'Beperkt tot',
        'live_heading' => 'Meer van :brand, zojuist opgehaald',
        'live_note' => 'Live opgehaald bij een winkel waarvan we de prijzen niet mogen bewaren, dus dit zijn losse aanbiedingen en geen volledige productpagina.',
    ],
    /*
     * Lange tekst onder een resultatenraster.
     *
     * Elke regel is óf een feit dat van de pagina zelf is afgelezen, óf een
     * kloppende uitleg van hoe de site werkt. Geen van beide laat zich met het
     * zoekwoord opvullen, zie App\Services\Seo\PageNarrative.
     */

    /*
     * Hetzelfde idee op een merkpagina. Andere invalshoek: deze lezer heeft het
     * merk al gekozen en kiest tussen producten en winkels.
     */

    'product' => [
        'from' => 'vanaf',
        'one_offer' => '1 aanbod',
        'offers' => ':count aanbiedingen',
        'across_shops' => 'bij :count winkels',
        'one_shop' => 'bij 1 winkel',
        'off' => ':percent% korting',
        'out_of_stock' => 'Niet op voorraad',
        'in_stock' => 'Op voorraad',
        'compare' => 'Vergelijk :count aanbiedingen',
        'all_offers' => 'Alle aanbiedingen',
        'go_to_shop' => 'Naar de winkel',
        'typical_price' => 'Gebruikelijke prijs :price',
        'barcode' => 'Streepjescode',
        // Zie de toelichting bij deze sleutels in lang/en/site.php.
        'description_heading' => 'Over dit product',
        'description_source' => 'Omschrijving aangeleverd door :shop.',
        'amazon_search' => 'Zoek dit product ook op Amazon',
        'amazon_search_barcode' => 'Op streepjescode :ean',
        'price_as_of' => 'Prijs en beschikbaarheid gelden op het getoonde moment en kunnen wijzigen.',
        'disclosure' => 'We verdienen mogelijk commissie als je via deze link koopt. Wat jij betaalt verandert niet.',
        'unavailable' => 'Dit product is nu bij geen enkele winkel die we volgen verkrijgbaar.',
        'seo_compare' => ':title vanaf :price, met het aanbod van elk van :count winkels.',
        'seo_single' => ':title vanaf :price, met de prijsgeschiedenis voordat je koopt.',
    ],

    /*
     * Cove-abonnementen. Elk antwoord op het formulier is identiek, wat er ook
     * gebeurd is, anders kun je ermee achterhalen wie deze site leest.
     */
    'discover_cove' => [
        'seo_title' => 'Cadeau-ideeën en vondsten, elke dag nieuw',
        'seo_description' => 'Drie manieren om iets te vinden waar je niet naar zocht: elke dag een nieuwe editie, een verrassing gekozen op zeldzaamheid, en lange verhalen per thema.',
        'title' => 'Ontdek',
        'intro' => 'Manieren om iets te vinden waar je niet naar zocht. Eén verandert elke dag, één is met opzet onvoorspelbaar, één gaat over een persoon in plaats van een ding, en de rest is om rustig te lezen.',
        'daily_what' => 'Elke dag een nieuwe editie: een thema, een handvol vondsten en een prijsraadsel. Elke oude editie houdt zijn eigen pagina.',
        'surprise_what' => 'Iets waarvan je niet wist dat het bestond, gekozen op hoe zeldzaam het is en niet op hoe goed het verkoopt.',
        'idea_what' => 'Koopadvies en koopgidsen rond één onderwerp: waar je op let en wat het verschil maakt, met elk merk en product direct doorgelinkt naar een live zoekopdracht.',
        'persona_what' => 'Cadeaus gekozen rond een persoon in plaats van een datum: de koffiefanaat, wie alles al heeft.',
        'persona_all' => 'Alle cadeau-ideeën',
    ],

    'shops' => [
        'seo_title' => 'De webshops waar we naar doorlinken',
        'seo_description' => 'Elke winkel waarvan hier aanbiedingen staan, met de nieuwste eruit gelicht. Geen totalen, gewoon de lijst.',
        'title' => 'Winkel Coves',
        'intro' => 'Bij elk aanbod op deze site staat de winkel erbij. Dit zijn die winkels — de winkels die in deze regio leveren.',
        'empty' => 'Nog geen winkels aangesloten voor deze regio.',
        'coves_heading' => 'Geschreven over deze winkels',
        'coves_what' => 'Hoe het is om bij een winkel te kopen — de helft van de beslissing waar een prijs niets over zegt.',
        'new_heading' => 'Nieuw hier',
        'new_what' => 'Afgelopen maand aangesloten. Ze staan hieronder ook in de lijst — dit is een uitlichting, geen filter.',
        'new_badge' => 'Nieuw',
        'all_heading' => 'Alle winkels',
    ],

    'coves' => [
        'seo_title' => 'Alle Coves: dagelijkse edities, cadeau-ideeën per persoon en lange verhalen',
        'seo_description' => 'De hele plank. Elke ochtend een nieuwe editie, cadeau-ideeën rond één persoon, en lange verhalen rond één onderwerp met live prijzen erin.',
        'title' => 'Alle Coves',
        'intro' => 'Alles wat we hier geschreven hebben, op vorm gesorteerd. De ene komt elke ochtend, de andere is rond een persoon gebouwd, de derde rond een onderwerp.',
        'empty' => 'Nog niets gepubliceerd in deze regio. De eerste Coves komen eraan.',
        'daily_heading' => 'Cove van de dag',
        'daily_what' => 'Elke ochtend één editie: een thema, een handvol vondsten en een prijspuzzel. Elke oude editie houdt zijn eigen pagina.',
        'daily_all' => 'Lees de editie van vandaag',
        'gift_heading' => 'Cadeau Coves',
        'gift_what' => 'Gebouwd rond een persoon in plaats van een datum — de kruidenvrouw, de vader die alles al heeft, de vriend die leest.',
        'gift_all' => 'Alle Cadeau Coves',
        'smart_heading' => 'Slim kopen',
        'smart_what' => 'Koopadvies en koopgidsen: waar je op let, wat het verschil maakt en wat het mag kosten — lange verhalen rond één onderwerp.',
        'smart_all' => 'Al het koopadvies',
        'brand_heading' => 'Merk Coves',
        'brand_what' => 'Eén pagina per merk: alles van hen dat we hier voeren, met per product de prijs van elke winkel.',
        'brand_all' => 'Alle Merk Coves',
        'shop_heading' => 'Winkel Coves',
        'shop_what' => 'De webshops die in deze regio leveren, nieuwste eerst.',
        'shop_all' => 'Alle Winkel Coves',
        'rail_products' => 'Meer in deze categorieën',
    ],

    'cove' => [
        'subscribe_heading' => 'De Cove, elke ochtend',
        'subscribe_intro' => 'Eén korte mail per dag: het thema, een paar vondsten en waarom ze de moeite waard zijn. Geen productspam, en met één klik weg.',
        'subscribe_placeholder' => 'jij@voorbeeld.be',
        'subscribe_button' => 'Stuur maar',
        'subscribe_thanks' => 'Kijk in je inbox, als dat adres nieuw voor ons is, is er een bevestigingslink onderweg.',
        'subscribe_privacy' => 'We gebruiken je adres alleen voor deze mail.',
        'confirm_done' => 'Je staat op de lijst. De volgende Cove komt morgenochtend.',
        'confirm_invalid' => 'Die link is verlopen of al gebruikt. Schrijf je opnieuw in voor een nieuwe.',
        'unsubscribed' => 'Je bent uitgeschreven. Geen harde gevoelens.',
    ],
    'suggestions' => [
        'added' => 'Toegevoegd aan de lijst.',
        'add_invite' => 'Voeg iets toe aan deze lijst',
        'add_invite_hint' => 'Wat je toevoegt komt er meteen op te staan, zodat iedereen het ziet en kan reserveren.',
        'add_action' => 'Aan de lijst toevoegen',
        'heading' => 'Voorgesteld voor jou',
        'from' => 'Van :name',
        'from_anonymous' => 'Van iemand met jouw link',
        'waiting' => ':count wachten',
        'one_waiting' => '1 wacht',
        'note_label' => 'Voeg een notitie toe',
        'accept' => 'Zet erop',
        'dismiss' => 'Liever niet',
        'sent' => 'Verstuurd. Zij beslissen of het op de lijst komt.',
        'accepted' => 'Op je lijst gezet.',
        'dismissed' => 'Afgewezen.',
        'suggest' => 'Stel iets voor',
        'invite' => 'Weet jij iets dat ze leuk zouden vinden?',
        'invite_hint' => 'Draag het aan, zij beslissen. Het komt alleen op de lijst als ze het accepteren.',
        'search_placeholder' => 'Zoek iets dat ze leuk zouden vinden',
        'none_found' => 'Daar kwam niets uit. Probeer een ander woord.',
        'already_on_list' => 'Die staat al op de lijst.',
        'manual_hint' => 'Niet te vinden bij de winkels die we volgen? Draag het toch aan, zij beslissen nog steeds.',
    ],

    'registry' => [
        'hint' => 'Zeg waarvoor deze lijst is, en wanneer. Iedereen met wie je de link deelt, ziet het.',
        'occasion' => 'Gelegenheid',
        'none' => 'Geen gelegenheid',
        'date' => 'Datum',
        'address' => 'Bezorgadres',
        'address_hint' => 'Versleuteld opgeslagen, en alleen zichtbaar voor wie iets geclaimd heeft.',
        'send_to' => 'Waar je het naartoe stuurt',
        'address_locked' => 'Claim iets en het bezorgadres verschijnt hier.',
        'occasion_on' => ':occasion op :date',
        'types' => [
            'birthday' => 'Verjaardag',
            'christmas' => 'Kerst',
            'wedding' => 'Huwelijk',
            'anniversary' => 'Jubileum',
            'baby' => 'Geboorte',
            'housewarming' => 'Nieuwe woning',
            'graduation' => 'Diploma',
            'retirement' => 'Pensioen',
            'farewell' => 'Afscheid',
            'valentines' => 'Valentijn',
            'mothers_day' => 'Moederdag',
            'fathers_day' => 'Vaderdag',
            'thank_you' => 'Bedankje',
            'other' => 'Iets anders',
        ],
        'badge' => 'Speciale gelegenheid',
    ],

    'handover' => [
        'hint' => 'Geef de lijst aan :name. Het wordt hun eigen wenslijst, die ze met anderen kunnen delen.',
        'action' => 'Geef hem door',
        'confirm' => 'Deze lijst aan :name geven? Hij is dan niet meer van jou.',
        'done' => 'Doorgegeven aan :name.',
        'already' => 'Deze lijst is al doorgegeven.',
        'only_gift_lists' => 'Alleen een lijst voor iemand anders kan worden doorgegeven.',
        'no_account' => 'Er is nog geen account met dat e-mailadres. Stuur ze eerst de link om hun voorkeuren in te vullen.',
        'badge' => 'Doorgeven',
    ],

    'votes' => [
        'vote' => 'Stem hierop',
        'voted' => 'Gestemd',
        'none' => 'Nog geen stemmen',
        'count' => ':count stemmen',
        'heading' => 'Stem op wat we moeten kopen',
    ],

    /*
     * The discussion beside a shared list.
     *
     * `hint` states who is reading, because that is the one thing somebody
     * typing here needs to know and cannot see: the people with the link, and
     * — on a wish list — not the person it is for. See
     * App\Services\Wishlist\Board.
     */
    'board' => [
        'title' => 'Overleg',
        'hint' => 'Iedereen met de link leest dit mee. Degene voor wie de lijst is niet.',
        'empty' => 'Nog niets gezegd. Begin maar.',
        'placeholder' => 'Zullen we samen de jas doen?',
        'your_name' => 'Je naam',
        'post' => 'Plaatsen',
        'remove' => 'Verwijderen',
        'posted' => 'Geplaatst.',
        'removed' => 'Verwijderd.',
    ],

    'pledges' => [
        'hint' => 'Zeg wat jij bijdraagt. Iemand koopt het, de rest rekent onderling af.',
        'amount' => 'Jouw deel',
        'your_name' => 'Je naam',
        'added' => 'Je doet mee.',
        'removed' => 'Weer uitgestapt.',
        'pledged' => ':total bijgedragen van :price',
        'join' => 'Ik doe mee',
        'leave' => 'Toch niet',

        'count' => ':count personen doen mee',
        'one_in' => 'Één persoon doet mee',
        'standard_share' => 'Je doet mee voor :amount.',
        'none' => 'Nog niemand heeft iets bijgedragen.',
        'your_share_is' => 'Jij droeg :amount bij',
        'organiser_note' => 'Jij ziet wie wat bijdraagt. De rest ziet het totaal en hun eigen deel.',
    ],

    'cove_mail' => [
        'confirm_subject' => 'Bevestig je abonnement op de Dagelijkse Cove',
        'confirm_heading' => 'Eén klik en je bent binnen',
        'confirm_body' => 'Klik hieronder om te bevestigen dat je de Dagelijkse Cove wilt. Tot dan sturen we je niets anders.',
        'confirm_button' => 'Bevestig mijn abonnement',
        'confirm_expiry' => 'De link werkt 48 uur.',
        'confirm_requested_from' => 'Aangevraagd vanaf :ip',
        'confirm_ignore' => 'Was jij dit niet? Negeer deze mail, zonder klik gebeurt er niets en schrijven we je niet nog eens.',

        'digest_subject' => 'De Cove van vandaag: :theme',
        'digest_button' => 'Open de Cove van vandaag',
        'across_shops' => 'bij :count winkels',
        'more_on_page' => 'Op de pagina staan nog :count vondsten, waaronder een paar die we alleen daar kunnen tonen.',
        'why_receiving' => 'Je krijgt deze mail omdat je een abonnement op de Dagelijkse Cove hebt bevestigd.',
        'unsubscribe' => 'Uitschrijven',
    ],
    'legal' => [
        'about' => 'Over ons',
        'privacy' => 'Privacy',
        'terms' => 'Voorwaarden',
        'cookies' => 'Cookies',
        'updated' => 'Laatst bijgewerkt op :date',
        'untranslated' => 'Deze pagina is nog niet vertaald, dus je leest de Engelse versie. De Engelse tekst is de geldende.',
    ],

    /*
     * De cookiebanner. Eén vraag, één keer gesteld.
     */
    'cookies' => [
        'title' => 'Cookies',
        'body' => 'We tellen bezoeken graag met Google Analytics, en dat zet een cookie. Niets op deze site heeft het nodig, dus jij beslist.',
        'accept' => 'Toestaan',
        'decline' => 'Liever niet',
        'more' => 'Wat we verzamelen',
    ],

    'footer' => [
        'affiliate' => 'We verdienen mogelijk commissie op aankopen via onze links, dat verandert nooit wat jij betaalt.',
        'explore' => 'Ontdekken',
    ],

    'auth' => [
        'title' => 'Inloggen',
        'intro' => 'Vul je e-mailadres in en we sturen je een link. Geen wachtwoord om te onthouden.',
        'email' => 'E-mailadres',
        'send' => 'Stuur me een link',
        'link_sent' => 'Kijk in je inbox, als er een account bij dat adres hoort, is de inloglink onderweg.',
        'link_invalid' => 'Die link is verlopen of al gebruikt. Vraag een nieuwe aan.',
        'too_many' => 'Te veel aanvragen. Probeer het over :seconds seconden opnieuw.',
        'or' => 'of',
        'google' => 'Doorgaan met Google',
        'mail_subject' => 'Je inloglink voor GiftCoves',
        'mail_heading' => 'Inloggen bij GiftCoves',
        'mail_body' => 'Tik op de knop hieronder om in te loggen. De link werkt één keer en alleen vanuit deze e-mail.',
        'mail_button' => 'Inloggen',
        'mail_expiry' => 'De link verloopt over 15 minuten.',
        'mail_requested_from' => 'Aangevraagd vanaf :ip',
        'mail_ignore' => 'Heb je dit niet aangevraagd? Dan kun je deze e-mail gerust negeren, zonder de link kan niemand inloggen.',
        'mail_fallback' => 'Werkt de knop niet? Plak dit in je browser:',
        'name' => 'Je naam (optioneel)',
        'mail_failed' => 'We konden de e-mail nu niet versturen. Probeer het zo nog eens.',
    ],

    'lists' => [
        'title' => 'Mijn lijstjes',
        'subtitle' => 'Alles wat jij bewaart, en alles wat anderen met jou gedeeld hebben.',
        'shared_subtitle' => 'Lijsten die anderen met je gedeeld hebben. Zo koop je iets voor hen.',
        'shared_empty' => 'Nog niemand heeft een lijst met je gedeeld. Zodra dat gebeurt, staat hij hier — met wat ze graag willen.',
        'shop_for' => 'Reserveer iets voor :name',
        'shared_with_me' => 'Met mij gedeeld',
        'owned_by' => 'Van :name',
        'group_subtitle' => 'Eén cadeau, samen gekozen. Iedereen stemt, en wat je bijdraagt blijft tussen jou en de organisator.',
        'default_title' => 'Mijn wenslijst',
        'default_badge' => 'Standaard',
        'shared_short' => 'Gedeeld',
        'private_short' => 'Privé',
        'tool_on' => 'aan',
        'find_things' => 'Zoek iets om toe te voegen',
        'manual_add' => 'Zet er zelf iets op',
        'manual_title' => 'Wat is het?',
        'manual_url' => 'Link (optioneel)',
        'manual_price' => 'Prijs (optioneel)',
        'manual_save' => 'Zet erop',
        'manual_no_preview' => 'We openen de link niet, dus er komt precies te staan wat jij hier typt.',
        'manual_url_invalid' => 'Een link moet met https:// beginnen',
        'added_to' => 'Bewaard in :list',
        'view_list' => 'Bekijk lijst',
        'undo' => 'Ongedaan maken',
        'save_failed' => 'Dat is niet bewaard. Nog eens proberen?',
        'adding_to' => 'Toevoegen aan :list',
        'added_count' => ':count toegevoegd',
        'done_adding' => 'Klaar',
        'add_to_this' => 'Aan :list toevoegen',
        'add_product' => 'Product toevoegen',
        'add_search_placeholder' => 'Zoek een product...',
        'search_failed' => 'Zoeken lukte niet. Nog eens proberen?',
        'add_nothing_found' => 'Niets gevonden voor ":term".',
        'add_own_intro' => 'Staat het niet in de winkels die wij volgen?',
        'add_own_cta' => 'Zet het er zelf op',
        'add_description' => 'Omschrijving',
        'add_note_placeholder' => 'maat M, in het blauw',
        'add_live_title_note' => 'De titel en de prijs komen rechtstreeks van :shop, dus die kun je hier niet aanpassen.',
        'back' => 'Terug',
        'new_list' => 'Nieuw lijstje',
        'make_new' => 'Maak een nieuw lijstje',
        'list_name' => 'Naam van het lijstje',
        'create' => 'Lijstje maken',
        'for_someone' => 'Dit lijstje is voor iemand anders',
        'for_whom' => 'Voor wie is het?',
        'empty' => 'Nog niets bewaard.',
        'empty_hint' => 'Zoek een product en druk op Bewaren.',
        'empty_list' => 'Dit lijstje is leeg.',
        'empty_mine_step1' => 'Voeg dingen toe die je graag wilt. De bladwijzer op elk product zet het hier neer.',
        'empty_mine_step2' => 'Klik op Delen wanneer je zover bent. Niet eerder — tot dan is hij van jou alleen.',
        'empty_mine_step3' => 'Anderen geven aan wat zij kopen, of je stuurt de lijst als quiz.',
        'empty_for_someone_step1' => 'Voeg ideeën toe wanneer je ze tegenkomt. Niemand anders ziet dit nog.',
        'empty_for_someone_step2' => 'Helpen anderen mee? Klik op Delen en voeg ze toe met hun e-mailadres.',
        'empty_for_someone_step3' => 'Iedereen geeft aan wat hij koopt, zodat niemand hetzelfde koopt.',
        'empty_group_step1' => 'Voeg een paar kandidaten toe. Jullie kiezen er samen een uit.',
        'empty_group_step2' => 'Klik op Delen en nodig de anderen uit.',
        'empty_group_step3' => 'Zij stemmen op wat het wordt en zeggen wat ze kunnen bijdragen.',
        'items' => ':count items',
        'one_item' => '1 item',
        'added' => 'Bewaard in je lijstje.',
        'removed' => 'Verwijderd.',
        'remove' => 'Verwijderen',
        'save' => 'Bewaren',
        'saved' => 'Bewaard',
        'save_to_list' => 'Bewaar in een lijstje',
        'save_to' => 'Bewaar in :list',
        'move_to' => 'Verplaats naar :list',
        'remove_from' => 'Haal uit :list',
        'delete_list' => 'Dit lijstje verwijderen',
        'delete_confirm' => 'Dit lijstje en alles erin verwijderen?',
        'share' => 'Delen',
        'sharing_off' => 'Alleen jij ziet dit lijstje.',
        'sharing_on' => 'Iedereen met de link kan dit lijstje zien.',
        'share_hint' => 'Deze lijst is privé. Deel hem en iedereen met de link kan hem zien.',
        'disable_sharing' => 'Stop met delen',
        'anyone_can_add' => 'Iedereen kan cadeaus toevoegen',
        'pledgers_visible' => 'Iedereen ziet wie bijdraagt',
        'pledgers_visible_hint' => 'Alleen de namen. Wie hoeveel bijdraagt zie alleen jij.',
        'voting_enabled' => 'Deelnemers kunnen stemmen op de cadeaus',
        'voting_enabled_hint' => 'De lijst sorteert zich op het aantal stemmen. Zet het uit als het cadeau al vaststaat.',
        'pledge_mode' => 'Hoe iedereen bijdraagt',
        'pledge_mode_each' => 'Iedereen geeft zelf op hoeveel hij of zij bijdraagt',
        'pledge_mode_fixed' => 'Iedereen draagt hetzelfde bij',
        'pledge_mode_each_person' => 'per persoon',
        'copy_link' => 'Link kopiëren',
        'copied' => 'Link gekopieerd',
        'claim' => 'Ik koop dit',
        'claimed' => 'Ik koop dit',
        'claimed_by_someone' => 'Iemand koopt dit al',
        'unclaim' => 'Toch niet',
        'unclaimed' => 'Je koopt dit niet meer.',
        'already_claimed' => 'Iemand anders was je net voor.',
        'cannot_unclaim' => 'Je kunt alleen je eigen keuze terugdraaien, en alleen binnen een dag.',
        'shared_intro' => 'Tik op een item om aan te geven dat jij het koopt. :name ziet niet wie wat koopt.',
        'recipient_added' => 'Persoon toegevoegd.',
        'recipient_removed' => 'Persoon verwijderd.',
        'add_person' => 'Iemand toevoegen',
        'person_name' => 'Hun naam',
        'someone_new' => 'Iemand nieuw',
        'copy_to' => 'Kopieer naar een ander lijstje',
        'copy_to_which' => 'Naar welk lijstje?',
        'copied_to' => 'Gekopieerd naar :list.',
        'add_to_my_list' => 'Zet op mijn lijstje',
        'birthday_optional' => 'Hun verjaardag (optioneel)',
        'birthday_why' => 'Alleen dag en maand. We gebruiken het om je op tijd te herinneren, nooit om hun leeftijd uit te rekenen.',
        'birthday_day' => 'Dag',
        'birthday_month' => 'Maand',
        'price_now' => 'Nu :price',
        'sign_in_to_keep' => 'Log in om je lijstjes te bewaren',
        'sign_in_hint' => 'Log in en alles wat je bewaart hoort bij je account, op elk toestel dat je gebruikt.',
        'marked_sent' => 'Gemarkeerd als gekocht.',
        'cannot_mark_sent' => 'Dat kan alleen voor iets dat jij hebt geclaimd.',
        'mark_sent' => 'Ik heb het gekocht',
        'sent' => 'Gekocht',
        'progress' => ':claimed van :total geclaimd',
        'asked_none' => ':name heeft nog niets op een lijstje gezet.',
        'ask_tab' => 'Vraag :name',
        'collaborator_removed' => 'Verwijderd.',
        'who_sees_what' => 'Wie ziet wat',
        'share_link' => 'De link naar deze lijst',
        'copy_message' => 'Kopieer bericht en link',
        'copy_manual' => 'Je browser liet ons niet kopiëren. De link staat geselecteerd — druk op Ctrl+C.',
        'invited_before' => 'Uitgenodigd voordat delen een link werd',
        'role_viewer' => 'Kan bekijken',
        'role_editor' => 'Kan toevoegen en verwijderen',
        'share_email' => 'E-mail',
        'friends' => 'Lijstjes van vrienden',
        'friends_empty' => 'Niemand die je volgt heeft al een openbaar lijstje.',
        'follow' => 'Volgen',
        'unfollow' => 'Ontvolgen',
        'followed' => 'Je volgt ze nu.',
        'shared_intro_anon' => 'Tik op iets om aan te geven dat jij het koopt. Wie dit lijstje maakte, ziet niet wie wat geclaimd heeft.',
        'shared_intro_gift' => 'Jullie kopen met meerdere mensen iets voor deze persoon. Geef aan wat jij koopt, dan koopt niemand hetzelfde.',
        'shared_intro_group' => 'Jullie kopen samen één cadeau. Stem op wat het wordt en zeg wat je kunt bijdragen.',
        'progress_gift' => ':claimed van :total al bezet',
        /*
         * What kind of list this is, and what that means you can do with it.
         *
         * The badge names the kind and never changes. The sentence reads the
         * kind AND whether anybody else is on the list: most lists are private,
         * and a private list offers none of the mechanisms — so it says what
         * the list is now, then what sharing would do. That second half is the
         * only place these features are ever taught.
         *
         * See resources/js/Components/ListKindBadge.tsx.
         */
        'claimed_by' => ':name koopt dit',
        'claim_anonymous_note' => 'Niemand ziet dat jij het was — ook niet degene die deze lijst beheert.',
        'claim_named_note' => 'Je naam is zichtbaar voor de anderen op deze lijst, zodat ze weten wie wat koopt.',
        'claim_names_visible' => 'Namen van wie wat koopt zijn zichtbaar (behalve voor de ontvanger)',
        'claim_mine_show_hint_mine' => 'Standaard uit: een verlanglijst werkt juist doordat je niet weet wat eraan komt. Zet het aan als je het liever wel ziet.',
        'claim_mine_show' => 'Laat mij zien wat er gereserveerd is',
        'claim_mine' => 'Wat jij ziet',
        'kind_mine' => 'Verlanglijst',
        'kind_for_someone' => 'Cadeaulijst',
        'kind_group' => 'Groepscadeau',
        'about_mine_private' => 'Dingen die je bewaart. Alleen jij ziet dit — deel het en anderen kunnen iets reserveren, zonder dat jij ooit ziet wat.',
        'about_mine_shared' => 'Anderen kunnen hiervan iets reserveren. Jij ziet nooit wat.',
        'about_for_someone_private' => 'Een lijst over hen, en alleen jij ziet hem. Deel hem als jullie met meerdere mensen kopen.',
        'about_for_someone_shared' => 'Jullie kopen ieder iets anders. Reserveer er een, dan koopt niemand hetzelfde.',
        'about_group_private' => 'Er kan nog niemand meedoen. Klik op Delen om ze uit te nodigen.',
        'about_group_shared' => 'Jullie kopen samen één cadeau. Stem erop en zeg wat je kunt bijdragen.',
        'quiz_unlocks' => 'Deel hem en je kunt er een quiz van maken: vier producten, één echt van jou. Kijk wie je het best kent.',
        'new_mine_body' => 'Dingen die je graag wilt. Hou hem voor jezelf, of deel hem en laat anderen iets reserveren.',
        'new_for_someone_body' => 'Een lijst over hen. Hou hem voor jezelf, of deel hem en verdeel het kopen.',
        'new_group_body' => 'Met meerdere mensen één cadeau kopen en delen. Iedereen stemt en draagt bij.',

        'for_me' => 'Voor mezelf',
        'for_someone_else' => 'Voor iemand anders',
        'for_group' => 'Samen, voor iemand',
        'group_gift' => 'Samen cadeau',
        'start_group_gift' => 'Samen een cadeau kopen',
        'for_person' => 'Voor :name',
        'cancel' => 'Annuleren',
        'share_text' => 'Dit is mijn lijstje: :title',
        'someones_wishlist' => 'Wenslijst van :name',
        'gift_list_for' => 'Cadeaulijst voor :name',
        'shared_by' => ':name deelde dit lijstje',
        'note_add' => 'Voeg een tekst toe',
        'note_edit' => 'Bewerk',
        'note_placeholder' => 'Wat de mensen die dit openen moeten weten.',
        'share_native' => 'Meer apps…',
        'share_instagram' => 'Instagram kan geen links uit een browser aannemen — kopieer hem en plak hem daar.',
        'shared_badge' => 'Gedeeld — iedereen met de link ziet het',
        'private_badge' => 'Alleen voor jou',
        'owner_view_note' => 'Dit is je eigen lijstje, dus je ziet niet wat er gekocht is, dat is de bedoeling.',
    ],

    'recipients' => [
        'step_birthday' => 'Wanneer ben je jarig?',
        'birthday_why' => 'Alleen dag en maand, zodat ze op tijd herinnerd worden. Naar het jaar vragen we nooit.',
        'self_title' => 'Vertel ze wat je echt zou willen',
        'self_intro' => 'Iemand zoekt een cadeau voor je, :name. Vul in wat je wilt, en zet erbij wat je echt leuk zou vinden.',
        'saved' => 'Opgeslagen. Ze zien het de volgende keer.',
        'linked' => 'Dit ben jij nu.',
        'claim_this_is_me' => 'Dit ben ik',
        'claim_is_you' => 'Dit is de link die jij hun stuurt. Jij hebt deze lijst gemaakt, dus hij kan niet van jou zijn — zij klikken aan hun kant op "dit ben ik".',
        'claim_sign_in' => 'Meld je aan om te zeggen dat jij dit bent. Dan wordt dit je eigen lijst, die je met iedereen kunt delen.',
        'claim_hint' => 'Koppel dit aan je account, dan verschijnen je eigen lijstjes wanneer ze voor je zoeken.',
        'my_list' => 'Wat :name leuk zou vinden',
        'about_you' => 'Over jou',
        'step_interests' => 'Waar hou je van?',
        'step_vibe' => 'Hoe mag het voelen?',
        'step_values' => 'Waar hecht je waarde aan?',
        'your_list' => 'Dingen die je leuk zou vinden',
        'add_something' => 'Iets toevoegen',
        'search_placeholder' => 'Zoek iets dat je wilt',
        'suggest' => 'Laat me ideeen zien',
        'nothing_yet' => 'Nog niets. Voeg het eerste toe.',
        'ask_them' => 'Vraag het hen zelf',
        'ask_them_hint' => 'Stuur deze link. Zij vullen hun eigen voorkeuren in en zien nooit wat jij hebt uitgekozen.',
    ],
    'santa' => [
        'title' => 'Geheime Vriend',
        'subtitle' => 'Een groep, een trekking, niemand weet wie wie heeft.',
        'create' => 'Start een groep',
        'group_name' => 'Hoe heet deze groep?',
        'budget' => 'Budget',
        'budget_hint' => 'Ongeveer wat iedereen uitgeeft.',
        'exchange_date' => 'Wanneer geven jullie de cadeaus?',
        'theme' => 'Thema (optioneel)',
        'invite' => 'Uitnodigingslink',
        'invite_hint' => 'Stuur dit naar iedereen. Ze doen mee met een naam en een e-mailadres.',
        'join' => 'Doe mee met deze groep',
        'your_name' => 'Je naam',
        'your_email' => 'Je e-mailadres',
        'exclusions' => 'Wie mag je niet trekken?',
        'exclusions_hint' => 'Namen of e-mailadressen, gescheiden door komma’s. Partners, of wie je vorig jaar had.',
        'joined' => 'Je doet mee. We mailen je zodra er getrokken is.',
        'members' => 'Wie er meedoen',
        'draw' => 'Trekken',
        'drawn' => 'Getrokken. Iedereen heeft een mail gekregen.',
        'redraw' => 'Opnieuw trekken voor deze persoon',
        'remove_member' => 'Verwijderen',
        'member_removed' => 'Verwijderd. Degene die hen had, weet nu voor wie die koopt.',
        'remove_confirm' => ':name uit deze groep verwijderen?',
        'remove_confirm_drawn' => ':name verwijderen? Er is al getrokken, dus een ander krijgt een nieuwe naam gemaild. Dat kun je niet terugdraaien.',
        'redraw_confirm' => 'Opnieuw trekken voor :name? Twee mensen krijgen een nieuwe naam gemaild, en dat kun je niet terugdraaien.',
        'email_changed_subject' => 'Je Geheime Vriend is gewijzigd: je hebt nu :name',
        'email_changed_intro' => 'Er is iets veranderd in de groep, dus dit vervangt de naam die we je eerder stuurden.',
        'redrawn' => 'Opnieuw getrokken. Beiden hebben een mail gekregen.',
        'you_have' => 'Jij koopt voor :name',
        'their_list' => 'Wat :name heeft gevraagd',
        'no_list' => ':name heeft geen lijstje gemaakt. Je staat er alleen voor, maar we helpen.',
        'build_yours' => 'Maak eerst je eigen lijstje',
        'build_yours_hint' => 'Wie jou getrokken heeft, heeft nog niets om op af te gaan.',
        'mark_done' => 'Ik heb de mijne gekocht',
        'marked_done' => 'Mooi. Jij bent klaar.',
        'done_count' => ':done van :total zijn klaar met kopen',
        'too_few' => 'Je hebt minstens twee mensen nodig om te trekken.',
        'impossible' => 'Met die uitsluitingen kan niemand gekoppeld worden.',
        'already_drawn' => 'Er is al getrokken in deze groep.',
        'not_drawn' => 'Er is nog niet getrokken.',
        'organiser_only' => 'Alleen de organisator kan dat doen.',
        'email_subject' => 'Je hebt :name getrokken',
        'email_intro' => 'Er is getrokken. Jij koopt voor :name.',
        'email_budget' => 'Het budget is ongeveer :budget.',
        'email_date' => 'Jullie geven de cadeaus op :date.',
        'email_list' => 'Ze hebben een lijstje gemaakt. Kijk maar:',
        'email_no_list' => 'Ze hebben nog geen lijstje gemaakt, dus je gaat blind. Daar helpen we bij:',
        'attach_hint' => 'Koppel een groep aan dit lijstje, zodat wie jou getrokken heeft iets heeft om op af te gaan.',
        'attach_list' => 'Gebruik dit lijstje',
        'list_attached' => 'Die groep ziet dit lijstje nu.',
        'list_attached_short' => 'In gebruik',
        'invite_text' => 'Doe mee met onze Geheime Vriend: :title',
        'delete' => 'Verwijder deze groep',
        'delete_confirm' => ':title verwijderen? Iedereen die meedeed raakt hem kwijt.',
        'delete_confirm_drawn' => ':title verwijderen? Er is al getrokken, dus iedereen verliest voor wie hij koopt — en niemand krijgt bericht. Zeg het hen eerst zelf.',
        'deleted' => 'Groep verwijderd.',
        'email_hint' => 'Zodat degene die jou trekt te horen krijgt wie hij heeft. Niemand anders ziet het.',
    ],
    'quiz' => [
        'title' => 'Hoe goed ken je ze?',
        'intro' => 'Vier dingen. Een ervan staat echt op het lijstje van :name. Kies welke.',
        'create' => 'Maak een quiz van dit lijstje',
        'created' => 'Quiz klaar. Stuur hem naar wie je wilt.',
        'too_short' => 'Je hebt minstens :count dingen op het lijstje nodig voordat een quiz leuk is.',
        'share_first' => 'Deel het lijstje eerst. Een quiz laat zien wat erop staat.',
        'round' => 'Ronde :current van :total',
        'score' => 'Je had er :score van de :total goed',
        'share' => 'Deel je score',
        'played' => ':count mensen hebben gespeeld',
        'average' => 'Gemiddelde score :score',
        'owner_note' => 'Dit is je eigen lijstje, dus je mag niet meedoen. Dat zou valsspelen zijn.',
        'missed' => 'Wat je miste',
        'missed_hint' => 'Elk hiervan willen ze echt hebben.',
        'play_again' => 'Je hebt al gespeeld. Een keer per persoon, anders zegt de score niets.',
        'intro_own' => 'Deel dit lijstje als een quiz: vier dingen, één ervan staat echt op je lijst.',
        'share_text' => 'Hoe goed ken je mij?',
        'own_title' => 'Kom te weten hoe goed je vrienden je kennen!',
        'open' => 'Open de quiz',
        'intro_anon' => 'Vier dingen. Een ervan staat echt op hun lijstje. Kies welke.',
        'badge' => 'Quiz',
    ],
    'preview' => [
        'badge' => 'Voorbeeld',
        'note' => 'Dit is nog niet gepubliceerd. Niemand anders ziet het, en zoekmachines wordt gevraagd het te negeren.',
    ],

    'gift_cove' => [
        'seo_title' => 'Verlanglijstjes, cadeaulijsten en Geheime Vriend',
        'seo_description' => 'Negen cadeautools op één plek: verlanglijstjes, een lijst voor iemand anders, samen bijdragen, Geheime Vriend en een quiz. Niemand ziet wie wat kocht.',
        'title' => 'De Geschenk Cove',
        'rail_hint' => 'Alles wat je nodig hebt om voor iemand anders te kopen, op één plek.',
        'rail_cta' => 'Open de Geschenk Cove',
        'intro' => 'Alles om voor anderen te kopen, en om te vertellen wat je zelf leuk zou vinden. Niemand ziet ooit wie wat gekocht heeft.',
        'tools' => 'Wat je hier kunt doen',
        'items_count' => ':count dingen bewaard',
        'open_list' => 'Open mijn wenslijst',
        'start_list' => 'Begin mijn wenslijst',
        'my_wishlists' => 'Mijn wenslijsten',
        'another_list' => 'Nog een wenslijst',
        'lists_count' => ':count wenslijsten',
        'privacy' => 'Een regel loopt overal doorheen: degene voor wie een lijst is, komt nooit te weten wat er geclaimd is. Niet wie, niet hoeveel, en niet dat er iets is.',

        'manual' => 'Hoe alles werkt',
        'manual_link' => 'Hoe alles werkt',
        'manual_intro' => 'Negen hulpmiddelen, met de stappen erbij. Elke knop die hieronder genoemd wordt, staat op de pagina waar je terechtkomt.',
        'manual_back' => 'Terug naar de Cadeau Cove',

        'wishlist_title' => 'Mijn wenslijst',
        'wishlist_body' => 'Dingen die je echt leuk zou vinden. Deel hem en mensen kunnen aangeven wat zij kopen, zonder dat jij ooit ziet wie wat nam.',
        'wishlist_step1' => 'Zoek iets dat je leuk vindt en druk op de bladwijzer erop. De keuzelijst vraagt naar welk lijstje het moet; kies je eigen.',
        'wishlist_step2' => 'Open het lijstje en druk op Delen. Daarmee gaat de link aan en zie je hem staan, klaar om te sturen naar wie ernaar vraagt.',
        'wishlist_step3' => 'Zij openen de link en geven aan wat zij kopen. Jij krijgt nooit te zien dat er iets is aangegeven.',

        'giftlist_title' => 'Een lijst voor iemand anders',
        'giftlist_body' => 'Een plek om ideeen te verzamelen voor een persoon. Prive voor jou, en nooit claimbaar, want het is voorwerk en geen verlanglijst.',
        'giftlist_step1' => 'Druk op Nieuw lijstje, kies "Voor iemand anders" en geef de persoon een naam. Deze kaart opent dat formulier meteen op die stand.',
        'giftlist_step2' => 'Zet er dingen op zodra je ze tegenkomt, net als bij elk ander lijstje.',
        'giftlist_step3' => 'Hou hem voor jezelf, of klik op Delen: dan zien de anderen hem en kunnen ze aangeven wat zij kopen, zodat niemand hetzelfde koopt.',

        'collab_title' => 'Samen kopen',
        'collab_body' => 'Nodig anderen uit op een cadeaulijst zodat jullie samen kunnen kiezen, of leg samen in voor een groter cadeau dat een van jullie koopt.',
        'collab_step1' => 'Druk op Nieuwe lijst, kies "Samen, voor iemand" en noem de persoon voor wie het is.',
        'collab_step2' => 'Druk op Delen en stuur de link naar elke mede-gever. Iedereen met de link kan kijken en reserveren; jij bepaalt of ze ook dingen mogen toevoegen.',
        'collab_step3' => 'Kies samen, en geef aan wat jij koopt zodat niemand hetzelfde koopt. Onder Delen bepaal je ook of namen zichtbaar zijn.',

        'handover_title' => 'Geef een lijst door',
        'handover_body' => 'Een lijst begonnen voor iemand die er nog niet was? Geef hem door zodra ze meedoen, dan wordt het hun eigen wenslijst.',
        'handover_step1' => 'Open het lijstje en stuur hen de link "Vraag het hen zelf", zodat er een account is om het aan te geven.',
        'handover_step2' => 'Zodra ze die gebruikt hebben, druk je op Doorgeven en vul je het e-mailadres in waarmee ze zich aanmeldden.',
        'handover_step3' => 'Bevestig, en het lijstje is van hen: zij kunnen het delen en anderen kunnen eruit claimen.',

        'santa_title' => 'Geheime Vriend',
        'santa_body' => 'Een groep, een trekking, niemand weet wie wie heeft. Iedereen kan zijn eigen wenslijst koppelen, zodat degene die hen trekt niet hoeft te gokken.',
        'santa_step1' => 'Druk op Start een groep en geef hem een naam, ongeveer wat iedereen uitgeeft, en de datum waarop jullie uitwisselen.',
        'santa_step2' => 'Stuur de uitnodigingslink naar iedereen. Ze doen mee met een naam en een e-mailadres, zonder account, en kunnen zeggen wie ze niet mogen trekken.',
        'santa_step3' => 'Zit iedereen erin, druk dan op Trekken. Iedereen krijgt een mail met daarin een naam: alleen die van henzelf.',

        'registry_title' => 'Een geschenkenlijst',
        'registry_body' => 'Een wenslijst met een gelegenheid en een datum, voor een huwelijk, een baby of een nieuwe woning. Zet er een adres bij: alleen wie iets geclaimd heeft, ziet het.',
        'registry_step1' => 'Open een van je verlanglijsten en klik op Gelegenheid.',
        'registry_step2' => 'Kies de gelegenheid en de datum, en zet er een adres bij als mensen dingen moeten opsturen.',
        'registry_step3' => 'Deel hem zoals elk ander lijstje. Hij gedraagt zich ook zo: mensen claimen, en jou wordt nooit verteld wat.',

        'quiz_title' => 'Hoe goed kennen ze je?',
        'quiz_body' => 'Maak van je wenslijst een quiz: vier dingen, een ervan staat er echt op. Je deelt de score, niet de antwoorden.',
        'quiz_step1' => 'Open je lijstje en druk op Delen.',
        'quiz_step2' => 'Druk op Quiz en dan op "Maak een quiz van dit lijstje".',
        'quiz_step3' => 'Stuur de quizlink rond. Vijf rondes van vier dingen, een poging per persoon.',

        'suggestions_title' => 'Suggesties',
        'suggestions_body' => 'Mensen die je kennen kunnen dingen voorstellen voor je lijst. Er komt niets op te staan tot jij ja zegt.',
        'suggestions_step1' => 'Deel je wenslijst. Een suggestie kan alleen komen van iemand die de link heeft.',
        'suggestions_step2' => 'Komt er een binnen, dan wacht hij bovenaan het lijstje, met de naam van wie hem stuurde.',
        'suggestions_step3' => 'Druk op "Zet erop" en hij komt op het lijstje, of op "Liever niet" en hij verdwijnt. Er komt niets op te staan voordat jij beslist.',

        'whisperer_title' => 'Gift Whisperer',
        'whisperer_body' => 'Beschrijf iemand en krijg vier ideeen, elk met de reden waarom. Voor als je weet voor wie het is, maar niet wat.',
        'whisperer_step1' => 'Beantwoord zes korte vragen over die persoon: wie het is, waar diegene van houdt, wat je wilt uitgeven, en wat vooral niet.',
        'whisperer_step2' => 'Je krijgt vier ideeen terug, elk met de reden waarom. Vraag om iets anders en wat je wegstuurde komt nooit meer terug.',
        'whisperer_step3' => 'Zet de goede meteen op een lijstje voor die persoon.',
    ],

    'reminders' => [
        'birthday_title' => 'De verjaardag van :name komt eraan',
        'exchange_title' => ':title komt eraan',
        'list_title' => ':occasion van :name komt eraan',
        'list_title_mine' => 'Jouw :occasion komt eraan',
        'lead' => 'Nog :days dagen. Genoeg tijd om iets te regelen voor :name.',
        'list_lead_mine' => 'Nog :days dagen. Een goed moment om te kijken of je lijstje zegt wat je wilt.',
        'mail_button' => 'Bekijk het',
        'mail_why' => 'Je krijgt dit omdat je deze datum hebt opgeslagen. Je kunt herinneringen uitzetten in je account.',
    ],

    'alerts' => [
        'watch_price' => 'Laat het weten als de prijs zakt',
        'watch_restock' => 'Laat het weten als hij er weer is',
        'watching_price' => 'We houden de prijs in de gaten',
        'watching_restock' => 'We wachten tot hij weer op voorraad is',
        'stop' => 'Niet meer volgen',
        'target_label' => 'Waarschuw me onder',
        'confirm' => 'Volgen',
        'any_drop_hint' => 'Laat het leeg en we melden elke daling.',
        // Named, not summarised. If one shop is not watched, saying so is the
        // difference between a promise kept and a promise quietly narrowed.
        'excluded' => ':shops kunnen we niet volgen, dus een daling daar krijg je niet te zien.',
        'created' => 'We laten het je weten.',
        'removed' => 'Niet meer gevolgd.',
        'not_available' => 'Deze kunnen we niet volgen.',
    ],

    'notifications' => [
        'title' => 'Meldingen',
        'recent' => 'Recent',
        'empty' => 'Nog niets. We melden het zodra er iets verandert aan wat je volgt.',
        'dropped_to' => 'Nu :price, was :was',
        'back_in_stock' => 'Weer op voorraad',
        'watching' => 'Wat je volgt',
        'watching_empty' => 'Je volgt nog niets.',
        'await_restock' => 'Wachten op voorraad',
        'until' => 'Onder :price',
        'any_drop' => 'Elke daling',
        'now' => 'nu :price',
    ],

    'gift' => [
        'title' => 'Cadeauzoeker',
        'subtitle' => 'Vertel ons over die persoon. Wij zoeken vier cadeaus die kloppen.',
        'seo_description' => 'Beschrijf voor wie je zoekt en krijg vier cadeau-ideeën, elk met de reden erbij en waar je het koopt.',

        'step_who' => 'Voor wie is het?',
        'step_interests' => 'Waar houdt die persoon van?',
        'step_vibe' => 'Hoe moet het voelen?',
        'step_budget' => 'Wat wil je uitgeven?',
        'step_avoid' => 'Iets vermijden?',
        'step_values' => 'Waar hecht je waarde aan?',

        'interests' => [
            'cooking' => 'Koken', 'coffee' => 'Koffie', 'photography' => 'Fotografie',
            'music' => 'Muziek', 'gaming' => 'Gamen', 'reading' => 'Lezen',
            'fitness' => 'Sporten', 'outdoors' => 'Buiten zijn', 'travel' => 'Reizen',
            'gardening' => 'Tuinieren', 'diy' => 'Klussen', 'beauty' => 'Verzorging',
            'fashion' => 'Mode', 'tech' => 'Techniek', 'home' => 'Hun huis',
            'craft' => 'Zelf maken', 'film' => 'Films en series', 'pets' => 'Hun huisdier',
            'wellness' => 'Ontspannen', 'kids' => 'Kinderen',
        ],

        'vibes' => [
            'practical' => 'Handig',
            'playful' => 'Leuk',
            'beautiful' => 'Mooi',
        ],

        'values' => [
            'sustainable' => 'Duurzaam',
            'local' => 'Uit de buurt',
            'handmade' => 'Handgemaakt',
        ],

        'find' => 'Zoek cadeaus',
        'again' => 'Opnieuw proberen',
        'swap' => 'Iets anders',
        'start_over' => 'Opnieuw beginnen',
        'results_title' => 'Vier ideeën',
        'no_results' => 'Hier paste niets bij. Probeer een ruimer budget of een andere interesse.',
        'budget_any' => 'Geen limiet',
        'budget_up_to' => 'Tot',
        'avoid_placeholder' => 'bv. alcohol, wol',
        'avoid_hint' => 'Wat op deze woorden lijkt, laten we weg.',
        'avoid_add' => 'Toevoegen',
        'recipient_use' => 'Gebruik wat we over :name weten',
        'recipient_none' => 'Iemand nieuw',
        'step' => 'Stap :current van :total',
        'back' => 'Terug',
        'next' => 'Volgende',

        // The card shows one reason, not a breakdown: three reasons read as a
        // machine justifying itself.
        'reasons' => [
            'interest_fit' => 'Past bij :match',
            'budget_fit' => 'Past binnen je budget',
            'surprise' => 'Niet de voor de hand liggende keuze',
            'vibe' => 'Past bij het gevoel dat je zocht',
            'values' => 'Sluit aan bij wat jij belangrijk vindt',
        ],
    ],

    'ask' => [
        'seo_title' => 'Vraag het aan anderen',
        'seo_description' => 'Geen idee wat je moet kopen? Beschrijf voor wie het is en laat anderen iets voorstellen. Elk antwoord komt met echte producten en een prijs.',
        'seo_question' => 'Cadeau-ideeën voor: :title. Echte tips van andere mensen, met de producten en prijzen erbij.',

        'title' => 'Vraag het aan anderen',
        'intro' => 'Geen inspiratie? Beschrijf voor wie je iets zoekt en laat de GiftCoves-community iets voorstellen. Antwoorden komen met echte producten, niet alleen met advies.',
        'nav_hint' => 'Beschrijf voor wie het is en laat anderen iets voorstellen.',

        'all' => 'Alle vragen',
        'more_about_them' => 'Vertel wat meer over die persoon (optioneel)',
        'more_hint' => 'Niets hiervan is verplicht. Het levert alleen meestal betere antwoorden op.',
        'occasion_label' => 'Gelegenheid',
        'occasion_placeholder' => 'Verjaardag, pensioen…',
        'age_label' => 'Ongeveer hoe oud',
        'age_placeholder' => 'Dertigers',
        'ask_cta' => 'Stel een vraag',
        'ask_heading' => 'Waar loop je op vast?',
        'question_label' => 'Je vraag',
        'question_placeholder' => 'Mijn zus wordt 30 en heeft eigenlijk alles al',
        'detail_label' => 'Alles wat verder helpt',
        'detail_placeholder' => 'Wat ze leuk vindt, wat je al hebt afgestreept, hoe goed je haar kent.',
        'budget_label' => 'Tot',
        'budget_hint' => 'Optioneel. Met een bedrag erbij zijn de antwoorden meestal beter.',
        'submit' => 'Vragen',
        'cancel' => 'Annuleren',

        'sign_in_to_ask' => 'Log in om een vraag te stellen of er een te beantwoorden.',

        'submitted' => 'Bedankt — we lezen elke vraag voordat hij online komt. De jouwe verschijnt zo.',
        'answer_submitted' => 'Bedankt — we lezen elk antwoord voordat het online komt.',

        'mine_heading' => 'Jouw vragen',
        'pending_notice' => 'We lezen deze nog. Hij staat nog niet op het bord.',
        'rejected_notice' => 'Deze konden we niet op het bord zetten.',

        'empty' => 'Nog niemand heeft iets gevraagd.',
        'empty_hint' => 'Wees de eerste. Iemand weet het meestal wel.',

        'answers' => ':count antwoorden',
        'one_answer' => '1 antwoord',
        'no_answers' => 'Nog geen antwoorden',
        'asked_by' => 'Gevraagd door :name',
        'budget_up_to' => 'Tot :amount',

        'answer_heading' => 'Geef antwoord',
        'answer_placeholder' => 'Wat zou jij kopen, en waarom?',
        'answer_submit' => 'Mijn antwoord plaatsen',
        'answers_heading' => 'Antwoorden',
        'be_first' => 'Nog geen antwoorden. Dat van jou zou het eerste zijn.',

        'picks_heading' => 'Stel iets concreets voor',
        'picks_hint' => 'Voeg tot :count dingen toe uit de winkels die we volgen. Een antwoord met een product erin is er tien zonder waard.',
        'picks_search' => 'Zoek een product',
        'picks_add' => 'Toevoegen',
        'picks_added' => 'Toegevoegd',
        'picks_full' => 'Meer past er niet in één antwoord.',
        'picks_none_found' => 'Daar kwam niets uit.',

        'status' => [
            'pending' => 'Wordt gelezen',
            'published' => 'Op het bord',
            'rejected' => 'Niet geplaatst',
        ],
    ],

    'invitations' => [
        'mail_subject' => ':name wil je hulp bij het kiezen van een cadeau',
        'mail_heading' => 'Help een cadeau kiezen',
        'mail_intro' => ':name vraagt je mee te kiezen, op een lijst met de naam ":list".',
        'mail_intro_for' => ':name vraagt je mee te kiezen voor :person.',
        'mail_what' => 'Open de link hieronder en log in met dit adres. Je ziet dan de lijst en kunt er ideeën aan toevoegen.',
        'mail_button' => 'Bekijk de lijst',
        'mail_expiry' => 'De link werkt twee weken.',
        'sign_in_first' => 'Log in met het adres waar de uitnodiging naartoe is gestuurd, dan staat de lijst klaar.',
    ],

    // Zie de toelichting bij deze sleutels in lang/en/site.php.
    'feedback' => [
        'seo_title' => 'Vertel ons wat beter kan of geef een pluimpje',
        'seo_description' => 'Zeg wat beter kan — een prijs die niet klopt, een link die nergens heen gaat — of laat weten wat je wel bevalt. Je hebt geen account nodig.',
        'title' => 'Vertel ons wat beter kan of geef een pluimpje',
        'message_label' => 'Je bericht',
        'message_placeholder' => 'Wat loopt er mis, wat ontbreekt, wat zou je anders doen? Of laat ons gewoon weten wat je goed vindt aan GiftCoves :D',
        'path_label' => 'Welke pagina?',
        'path_placeholder' => '/be-nl/p/1234/…',
        'email_label' => 'Je e-mailadres (optioneel)',
        'email_placeholder' => 'jij@voorbeeld.be',
        'email_hint' => 'Alleen om hierop te antwoorden. Er wordt nooit iets anders naartoe gestuurd.',
        'submit' => 'Versturen',
        'sending' => 'Bezig met versturen…',
        'thanks' => 'Bedankt — dit is aangekomen en iemand leest het.',
    ],

    'search_help' => [
        'seo_title' => 'Zo zoek je: woorden, streepjescodes en Amazon-links',
        'seo_description' => 'Wat het zoekvak begrijpt — productnamen, merken, streepjescodes en geplakte Amazon-links — en hoe je resultaten terugbrengt tot de juiste aanbieding.',
        'title' => 'Zoeken en scannen',
        'intro' => 'Wat het zoekveld begrijpt, hoe je een resultatenlijst versmalt, en wat er gebeurt als je een camera op een barcode richt.',
        'link' => 'Waarop kun je hier zoeken?',

        'searching_heading' => 'Waarop je kunt zoeken',
        'searching_intro' => 'Eén veld, vier soorten invoer. Het ziet eruit als elk ander zoekveld en het neemt heel wat meer aan.',
        'what_words_term' => 'Woorden',
        'what_words' => 'Productnamen, merken, categorieën en woorden uit de omschrijving van de winkel zelf. Een woord in de titel weegt zwaarder dan hetzelfde woord in een omschrijving, dus de dichtstbijzijnde namen staan vooraan.',
        'what_typos_term' => 'Typefouten',
        'what_typos' => '"blutooth koptelefon" vindt gewoon bluetooth-koptelefoons. Er wordt woord voor woord vergeleken, dus één verkeerde letter kost je de rest van de zoekopdracht niet.',
        'what_accents_term' => 'Accenten',
        'what_accents' => 'In beide richtingen optioneel. "creme" vindt "crème" en andersom, in elke taal die we voeren.',
        'what_language_term' => 'Taal',
        'what_language' => 'Elke markt zoekt in zijn eigen taal, meervouden en woorduitgangen inbegrepen. Zoeken in de taal van de winkels waar je naar kijkt geeft het beste resultaat.',
        'what_barcode_term' => 'Een barcode',
        'what_barcode' => 'Typ of plak de cijfers onder de strepen — 8 tot 14 stuks — en je komt op het product zelf uit in plaats van op een lijst. Het is dezelfde opzoeking die de camera doet.',
        'what_amazon_term' => 'Een Amazon-link',
        'what_amazon' => 'Plak het adres van een Amazon-productpagina: we lezen de productnaam uit de link zelf en zoeken daarop hier. We openen de link nooit, en in een verkort amzn.to-adres staat niets te lezen — plak het volledige.',

        'narrowing_heading' => 'Een resultatenlijst versmallen',
        'narrowing_intro' => 'De filters staan boven de resultaten. Alles wat je instelt blijft in het adres staan, dus een gefilterde zoekopdracht is een link die je kunt versturen of bewaren.',
        'narrow_price_term' => 'Prijs',
        'narrow_price' => 'Een ondergrens, een bovengrens, of allebei. Prijzen zijn altijd die van jouw markt.',
        'narrow_brand_term' => 'Merk en winkel',
        'narrow_brand' => 'Kies er één of meerdere van allebei. Merken worden op identiteit vergeleken en niet op spelling, dus "Audio-Technica" en "Audio Technica" zijn één merk en geen twee.',
        'narrow_stock_term' => 'Op voorraad',
        'narrow_stock' => 'Staat standaard aan. Zet het uit om ook te zien wat vandaag door geen enkele winkel verstuurd kan worden.',
        'narrow_sort_term' => 'Sorteren',
        'narrow_sort' => 'Beste match, prijs omhoog of omlaag, grootste korting, of nieuwste. Er is ook een weergave per winkel, die dezelfde resultaten groepeert onder de winkels die ze verkopen.',
        'narrow_terms_term' => 'De woorden boven de resultaten',
        'narrow_terms' => 'Woorden die zijn afgelezen van de producten die je voor je hebt. Elk woord voegt zich toe aan wat je typte in plaats van het te vervangen, zodat de zoekopdracht versmalt en niet doodloopt.',

        'scanning_heading' => 'Een barcode scannen',
        'scanning_intro' => 'Richt je telefoon op de barcode op een doos in de winkel en zie of het elders goedkoper is, terwijl je er nog staat.',
        'scan_where_term' => 'Waar je begint',
        'scan_where' => 'De cameraknop in het zoekveld, op de zoekpagina. Er is ook een eigen pagina, als je daar liever een snelkoppeling naar bewaart.',
        'scan_privacy_term' => 'Het camerabeeld verlaat je telefoon niet',
        'scan_privacy' => 'De barcode wordt op het toestel zelf gelezen en alleen de cijfers gaan naar ons. Er wordt geen beeld verstuurd, bewaard of gelogd. De camera vraagt een beveiligde verbinding en jouw toestemming, en blijft uit tot je op de knop drukt.',
        'scan_devices_term' => 'Welke telefoons het kunnen',
        'scan_devices' => 'Chrome op Android leest barcodes zelf. Op de iPhone, en in Safari en Firefox, wordt er een lezer gedownload zodra je de scanner opent — de eerste scan duurt daardoor iets langer en de rest niet.',
        'scan_misses_term' => 'Niets vinden is normaal',
        'scan_misses' => 'Alleen producten die aan hun barcode te herkennen zijn kunnen gevonden worden, en niet elke winkel geeft er een op. We kijken eerst in onze eigen catalogus en vragen het daarna rechtstreeks aan bol. Niets gevonden betekent dat we dat artikel nog niet hebben — niet dat het niet bestaat.',
        'scan_misread_term' => 'Verkeerd gelezen codes',
        'scan_misread' => 'Elke barcode heeft een controlecijfer, en een code die daar niet op klopt wordt weggegooid in plaats van opgezocht: één verkeerd cijfer is een ander bestaand product, geen bijna-treffer. Gebeurt er niets, houd de camera er dan gewoon op.',
        'scan_manual_term' => 'Als de camera het niet leest',
        'scan_manual' => 'Gebogen, gekreukte en in plastic gewikkelde barcodes zijn echt lastig, en winkelverlichting helpt niet mee. Typ de cijfers dan in — dat werkt altijd.',

        'go_search' => 'Naar zoeken',
        'go_scan' => 'Open de scanner',
    ],

    'scan' => [
        'title' => 'Scan een barcode',
        'subtitle' => 'Sta je in de winkel? Scan het en zie wat het elders kost.',
        'seo_description' => 'Scan een barcode en zie wat elke winkel die het product heeft ervoor vraagt.',
        'start' => 'Camera openen',
        'stop' => 'Stoppen',
        'manual_placeholder' => 'Of tik de barcode in',
        'look_up' => 'Opzoeken',
        'close' => 'Sluiten',
        'shops' => 'bij :count winkels',
        'preparing' => 'Even klaarzetten…',
        'unsupported' => 'De scanner kon niet starten. Tik het nummer hieronder in, het staat onder de streepjes.',
        'no_camera' => 'Geen camera beschikbaar, of toestemming geweigerd. Tik het nummer hieronder in.',
        'invalid' => 'Dat is geen geldige barcode. Controleer de cijfers onder de streepjes.',
        'not_found' => 'Die hebben we nog niet.',
        'search_instead' => 'Toch zoeken',
    ],

    'surprise' => [
        'title' => 'Dingen waarvan je niet wist dat ze bestonden',
        'subtitle' => 'Zeldzaam, op voorraad, en door bijna niemand verkocht.',
        'seo_description' => 'Ongewone producten die op geen enkele bestsellerlijst staan, beoordeeld op hoe zeldzaam ze zijn en gecontroleerd of ze het bekijken waard zijn.',
        'reroll' => 'Laat meer zien',
        'empty' => 'Nog niets beoordeeld. Kom terug na de volgende catalogusronde.',

        'by_brand' => 'Van :brand',
    ],

    'gift_ideas' => [

        /*
         * The placeholder title a drafted persona wears.
         *
         * `PlanDrafter` writes one per interest the gift wizard knows about,
         * so a market's persona shelf can be filled with shortlists to react
         * to rather than a blank table. It is deliberately dull: the interest
         * leads so that a label like "DIY" or "The outdoors" keeps its own
         * capitalisation, and no adjective has to agree with a noun in four
         * languages. A person renames it before approving.
         */
        'draft_title' => ':interest — cadeau-ideeën',

        'title' => 'Cadeau-ideeën, per type',
        'description' => 'Cadeaus gekozen rond een persoon in plaats van een datum: de kruidenliefhebber, de vader die alles al heeft, de vriend die leest.',
        'empty' => 'Nog niets. Ze worden een voor een geschreven; de eerste komt eraan.',
        'finds_title' => 'Wat je voor ze koopt',
        'find_count' => ':count ideeën',
    ],

    'daily' => [
        'title' => 'De Dagelijkse Cove',
        'seo_description' => 'Een handvol dingen waarvan je niet wist dat ze bestonden, en een koopgids gebouwd op wat mensen hier echt zochten.',
        'see_offers' => 'Bekijk de aanbiedingen',
        'finds_title' => 'De vondsten van vandaag',
        'guide_title' => 'De gids van vandaag',
        'guide_why' => 'Geschreven omdat :count zoekopdrachten hier erom vroegen.',

        // The no-AI theme rotation, indexed by day of year modulo 7. Dated
        // rather than random so a rebuild of the same day is identical.
        // Days worth building an edition around. A blurb is optional; a missing
        // one renders as no blurb rather than as a dotted key.
        'observances' => [
            // Januari.
            'new_year' => ['title' => 'Het begin van iets', 'blurb' => 'De eerste dag van het jaar, en de spullen die mensen kopen als ze het menen.'],
            'sleep' => ['title' => 'Festival of Sleep Day', 'blurb' => 'Een echte dag, bedacht voor precies dit moment in januari.'],
            'three_kings' => ['title' => 'Driekoningen', 'blurb' => 'De avond waarop de cadeaus echt komen.'],
            'houseplants' => ['title' => 'Kamerplantendag', 'blurb' => 'De boom is weg; er moet iets in die hoek.'],
            'pet_style' => ['title' => 'Verkleed-je-huisdierdag', 'blurb' => 'Ze vergeven het je. Waarschijnlijk.'],
            'hats' => ['title' => 'Nationale Hoedendag', 'blurb' => 'Half januari, en dat is het enige argument dat deze dag nodig heeft.'],
            'hot_tea' => ['title' => 'Dag van de hete thee', 'blurb' => 'Alles tussen de waterkoker en het kopje.'],
            'popcorn' => ['title' => 'Popcorndag', 'blurb' => 'En de rest van de uitrusting voor binnenblijven.'],
            'cheese' => ['title' => 'Dag van de kaasliefhebber', 'blurb' => 'De plankjes, de messen, de licht overdreven fonduepan.'],
            'hugs' => ['title' => 'Dag van de zachte dingen', 'blurb' => 'Het is Internationale Knuffeldag, dus vandaag gaat het over spullen die je wilt aanraken.'],
            'pie' => ['title' => 'Taartdag', 'blurb' => 'Vormen, schalen en dat ene apparaat dat echt helpt.'],
            'burns_night' => ['title' => 'Burns Night', 'blurb' => 'Vooral glaswerk.'],
            'lego_day' => ['title' => 'Internationale LEGO-dag', 'blurb' => 'Het patent werd vandaag in 1958 aangevraagd. Al het andere is decoratie.'],
            'puzzles' => ['title' => 'Puzzeldag', 'blurb' => 'De juiste week van het jaar voor duizend stukjes.'],
            'blue_monday' => ['title' => 'Blue Monday', 'blurb' => 'Zogenaamd de somberste dag van het jaar. De wetenschap klopt niet; de lampen werken wel.'],

            // Februari.
            'pizza' => ['title' => 'Pizza, serieus', 'blurb' => 'Wereldpizzadag. Het gerei is interessanter dan je denkt.'],
            'science' => ['title' => 'Dag van vrouwen en meisjes in de wetenschap', 'blurb' => 'Spullen om beter mee te kijken.'],
            'radio' => ['title' => 'Wereldradiodag', 'blurb' => 'Alles wat geluid maakt zonder dat je erom vroeg.'],
            'valentines' => ['title' => 'Valentijnsdag', 'blurb' => 'Met een hogere lat dan een boeket van het tankstation.'],
            'kindness' => ['title' => 'Dag van de willekeurige vriendelijkheid', 'blurb' => 'Kleine dingen, opgestuurd naar iemand die het niet verwacht.'],
            'wine' => ['title' => 'Wijndag', 'blurb' => 'Het glaswerk en het gerei, niet de fles.'],
            'love_your_pet' => ['title' => 'Verwen-je-huisdierdag', 'blurb' => 'Ze nemen het eerbetoon in ontvangst.'],
            'cocktails' => ['title' => 'Margaritadag', 'blurb' => 'De spullen gaan het hele jaar mee.'],
            'pokemon' => ['title' => 'Pokémondag', 'blurb' => 'Dertig jaar en nog altijd bezig.'],

            // Maart.
            'wildlife' => ['title' => 'Om dieren te bekijken', 'blurb' => 'Wereld Natuurdag, spullen om dieren van dichtbij te bekijken die daar niet om vroegen.'],
            'womens_day' => ['title' => 'Internationale Vrouwendag', 'blurb' => 'Gereedschap, geen snuisterijen.'],
            'mario_day' => ['title' => 'MAR10-dag', 'blurb' => 'Opgeschreven zegt de datum zijn naam.'],
            'pi_day' => ['title' => 'Pi-dag', 'blurb' => '3,14, dus: ronde dingen om in te bakken.'],
            'happiness' => ['title' => 'Vrolijke voorwerpen', 'blurb' => 'Internationale Dag van het Geluk, letterlijk genomen.'],
            'poetry' => ['title' => 'Wereldpoëziedag', 'blurb' => 'Pennen, papier en ergens om het resultaat te bewaren.'],
            'water' => ['title' => 'Wereldwaterdag', 'blurb' => 'Flessen, filters en één grote ton.'],
            'tolkien' => ['title' => 'Tolkien Reading Day', 'blurb' => 'De dag dat de Ring in het vuur ging.'],
            'pencils' => ['title' => 'Potlodendag', 'blurb' => 'Het patent is van vandaag, in 1858.'],
            'backup' => ['title' => 'World Backup Day', 'blurb' => 'De dag vóór 1 april, en dat is geen toeval.'],

            // April.
            'april_fools' => ['title' => 'Eén april', 'blurb' => 'Echt nuttige spullen die op een grap lijken.'],
            'childrens_books' => ['title' => 'Internationale Kinderboekendag', 'blurb' => 'De verjaardag van Andersen, en de lamp om ze bij te lezen.'],
            'health' => ['title' => 'Stilletjes goed voor je', 'blurb' => 'Wereldgezondheidsdag, zonder het preken.'],
            'pets' => ['title' => 'Voor het dier dat je huis bestuurt', 'blurb' => 'Dag van het Huisdier. Ze vroegen er niet om, maar hier zijn we.'],
            'space' => ['title' => 'Yuri’s Night', 'blurb' => 'De eerste mens in een baan om de aarde, vandaag ruim zestig jaar geleden.'],
            'earth' => ['title' => 'Dingen die meegaan', 'blurb' => 'Dag van de Aarde, spullen die je twee keer kunt bezitten.'],
            'books' => ['title' => 'Voor lezers', 'blurb' => 'Wereldboekendag, en alles eromheen behalve de boeken.'],
            'kingsday' => ['title' => 'Koningsdag', 'blurb' => 'Oranje is optioneel. Een koelbox niet.'],
            'record_store_day' => ['title' => 'Record Store Day', 'blurb' => 'Waar je het op afspeelt, want kopen doe je toch.'],
            'dance' => ['title' => 'Internationale Dansdag', 'blurb' => 'Dingen die een kamer luid maken.'],
            'jazz' => ['title' => 'Internationale Jazzdag', 'blurb' => 'Vooral analoog.'],

            // Mei.
            'makers' => ['title' => 'Dingen die dingen maken', 'blurb' => 'Dag van de Arbeid, gelezen als excuus voor een betere boormachine.'],
            'star_wars' => ['title' => 'May the Fourth', 'blurb' => 'De woordgrap is de hele rechtvaardiging en hij houdt al decennia stand.'],
            'eat_what_you_want' => ['title' => 'Eet-wat-je-wilt-dag', 'blurb' => 'De apparaten achter dat besluit.'],
            'family' => ['title' => 'Internationale Dag van het Gezin', 'blurb' => 'Dingen waar je meer dan één persoon voor nodig hebt.'],
            'bees' => ['title' => 'Wereldbijendag', 'blurb' => 'Kleine architectuur voor zeer kleine huurders.'],
            'tea' => ['title' => 'Internationale Theedag', 'blurb' => 'Het ritueel, op de schaal die jij wilt.'],
            'geek_pride' => ['title' => 'Geek Pride Day', 'blurb' => 'Ook Towel Day, en de verjaardag van de eerste Star Wars. Een drukke 25 mei.'],
            'mountains' => ['title' => 'Everestdag', 'blurb' => 'Vandaag beklommen in 1953. Dit is de zachtere kant van hetzelfde idee.'],
            'mothers_day' => ['title' => 'Voor je moeder', 'blurb' => 'Geen mok.'],

            // Juni.
            'bicycle' => ['title' => 'Twee wielen', 'blurb' => 'Wereldfietsdag. Sommige hiervan zijn echt slim bedacht.'],
            'environment' => ['title' => 'Minder rommel', 'blurb' => 'Wereldmilieudag, dingen die iets wegwerpbaars vervangen.'],
            'oceans' => ['title' => 'Wereldoceanendag', 'blurb' => 'Om erin te gaan, of er in elk geval dichtbij.'],
            'sushi' => ['title' => 'Internationale Sushidag', 'blurb' => 'Het gaat vooral om de rijst en het mes.'],
            'music' => ['title' => 'Maak wat lawaai', 'blurb' => 'Wereldmuziekdag, instrumenten inbegrepen.'],
            'skateboarding' => ['title' => 'Go Skateboarding Day', 'blurb' => 'Inclusief de onderdelen die je heel houden.'],
            'fathers_day' => ['title' => 'Voor je vader', 'blurb' => 'Geen sokken.'],

            // Juli.
            'chocolate' => ['title' => 'Wereldchocoladedag', 'blurb' => 'De vormen, de thermometers, de fontein die niemand nodig heeft.'],
            'emoji' => ['title' => 'Wereld-emojidag', 'blurb' => 'Op de kalender-emoji staat 17 juli. Dat is de hele reden.'],
            'moon' => ['title' => 'Maandag', 'blurb' => 'Apollo 11 landde vandaag, in 1969.'],
            'belgian_national' => ['title' => 'Nationale feestdag', 'blurb' => 'In de kern: friet en een barbecue.'],
            'friendship' => ['title' => 'Internationale Vriendschapsdag', 'blurb' => 'Dingen die beter opgestuurd dan bewaard worden.'],
            'wizarding' => ['title' => 'Een tovenaarsverjaardag', 'blurb' => '31 juli. Wie het weet, weet het.'],

            // Augustus.
            'cats' => ['title' => 'Kattendag', 'blurb' => 'Internationale Kattendag. Ze weten het.'],
            'book_lovers' => ['title' => 'Dag van de boekenliefhebber', 'blurb' => 'Alles behalve de boeken.'],
            'lefthanders' => ['title' => 'Internationale Linkshandigendag', 'blurb' => 'Spullen ontworpen door mensen die opletten.'],
            'photography' => ['title' => 'Om goed te kijken', 'blurb' => 'Wereldfotografiedag, de accessoires waar niemand je over vertelt.'],
            'dogs' => ['title' => 'Internationale Hondendag', 'blurb' => 'Hij staat al bij de deur.'],
            'back_to_school' => ['title' => 'De laatste vakantiedag', 'blurb' => 'Alles wat je in juli van plan was te kopen.'],

            // September.
            'coffee' => ['title' => 'Het koffiekonijnenhol'],
            'literacy' => ['title' => 'Internationale Alfabetiseringsdag', 'blurb' => 'Spullen die lezen makkelijker maken.'],
            'programmers' => ['title' => 'Programmeursdag', 'blurb' => 'De 256e dag van het jaar, en dat is de grap.'],
            'pirates' => ['title' => 'Praat-als-een-piraatdag', 'blurb' => 'Ja, dit is een echte dag. Nee, wij weten ook niet waarom.'],
            'peace_quiet' => ['title' => 'Rust', 'blurb' => 'Internationale Dag van de Vrede, zo luid mogelijk uitgelegd: dingen die het laten ophouden.'],
            'travel' => ['title' => 'Werelddag van het toerisme', 'blurb' => 'De spullen die de luchthaven overleven.'],

            // Oktober.
            'coffee_intl' => ['title' => 'Internationale Koffiedag', 'blurb' => 'Van verstandig tot volledig overdreven.'],
            'animals' => ['title' => 'Werelddierendag', 'blurb' => 'Voor degenen die bij je wonen.'],
            'teachers' => ['title' => 'Dag van de leraar', 'blurb' => 'Spullen die een schooltas overleven.'],
            'food' => ['title' => 'Wereldvoedseldag', 'blurb' => 'Het gereedschap, niet de ingrediënten.'],
            'chefs' => ['title' => 'Internationale Kokkendag', 'blurb' => 'Eén mes, goed gekozen.'],
            'internet' => ['title' => 'Wereldinternetdag', 'blurb' => 'De saaie doosjes die de rest laten werken.'],
            'halloween' => ['title' => 'Halloween', 'blurb' => 'Vanavond al, dus dit is je laatste kans.'],

            // November.
            'singles_day' => ['title' => 'Singles Day', 'blurb' => 'De grootste koopdag ter wereld, en hier heeft bijna niemand ervan gehoord.'],
            'world_kindness' => ['title' => 'Wereldvriendelijkheidsdag', 'blurb' => 'Om te versturen, niet om te houden.'],
            'mens_health' => ['title' => 'Verzorging, zonder gedoe'],
            'television' => ['title' => 'Wereldtelevisiedag', 'blurb' => 'Vooral de spullen die eraan vastzitten.'],
            'digital_tidy' => ['title' => 'Computer Security Day', 'blurb' => 'De fysieke helft ervan: schijven, sticks, papiervernietigers.'],
            'black_friday' => ['title' => 'Echt goedkoper', 'blurb' => 'Gemeten tegen onze eigen prijsgeschiedenis, niet tegen een sticker.'],

            // December.
            'wildlife_conservation' => ['title' => 'Kijken, niet storen'],
            'sinterklaas' => ['title' => 'Pakjesavond', 'blurb' => 'Kleine dingen die het inpakken waard zijn.'],
            'saint_nicolas' => ['title' => 'Sinterklaas', 'blurb' => 'Kleine dingen die het inpakken waard zijn.'],
            'solstice' => ['title' => 'De langste nacht', 'blurb' => 'Licht en dekens, in die volgorde.'],
            'christmas_eve' => ['title' => 'Kerstavond', 'blurb' => 'Inmiddels meer het inpakken dan de cadeaus.'],
            'christmas_day' => ['title' => 'Eerste kerstdag', 'blurb' => 'Voor wie tóch kijkt: batterijen, en een spel waar een vierde speler voor nodig is.'],
            'boxing_day' => ['title' => 'Tweede kerstdag', 'blurb' => 'De lange middag.'],
            'new_years_eve' => ['title' => 'Oudejaarsavond', 'blurb' => 'Vooral glazen.'],
        ],

        /*
         * Groenblijvende thema's voor elke dag die de namenkalender niet dekt.
         *
         * De regel die telt: **suggereer nooit dat de datum een naam heeft.**
         * Deze verschijnen op gewone dinsdagen.
         */
        'day_themes' => [
            'desk_reset' => ['title' => 'Het bureau opnieuw', 'blurb' => 'Het punt waarop de kabels ophouden een dagelijks probleem te zijn.'],
            'coffee_ritual' => ['title' => 'Het ochtendritueel', 'blurb' => 'Tien minuten die je ofwel geniet ofwel doorstaat.'],
            'tea_corner' => ['title' => 'De theehoek'],
            'one_good_knife' => ['title' => 'Eén goed mes', 'blurb' => 'De enige upgrade die koken echt verandert.'],
            'slow_cooking' => ['title' => 'Dingen die uren duren', 'blurb' => 'Gietijzer en geduld.'],
            'baking' => ['title' => 'Wegen, mengen, wachten'],
            'sound' => ['title' => 'Beter klinken', 'blurb' => 'Waar je geld verder komt dan de marketing doet vermoeden.'],
            'vinyl' => ['title' => 'Platen en wat ze afspeelt'],
            'gaming_night' => ['title' => 'De opstelling', 'blurb' => 'De onderdelen die je aanraakt, want daar zit het verschil.'],
            'board_games' => ['title' => 'Rond de tafel', 'blurb' => 'Voor avonden zonder scherm.'],
            'reading_nook' => ['title' => 'Ergens om te lezen', 'blurb' => 'Een stoel is optioneel; het licht niet.'],
            'better_sleep' => ['title' => 'Beter slapen', 'blurb' => 'Een derde van je leven, achteloos ingericht.'],
            'bathroom' => ['title' => 'De kleine kamer'],
            'skincare' => ['title' => 'Het gezicht dat je hebt'],
            'hair' => ['title' => 'Haar, geregeld'],
            'shaving' => ['title' => 'Verzorging, zonder gedoe'],
            'running' => ['title' => 'Gaan hardlopen', 'blurb' => 'Eerst schoenen, dan de rest.'],
            'yoga' => ['title' => 'Werk op de vloer', 'blurb' => 'Bijna niets, en dat bijna doet ertoe.'],
            'home_gym' => ['title' => 'De sportschool op de logeerkamer', 'blurb' => 'Voor als het abonnement verlopen is.'],
            'cycling' => ['title' => 'Op twee wielen'],
            'travel_kit' => ['title' => 'Goed inpakken', 'blurb' => 'De spullen die de luchthaven overleven.'],
            'small_flying_things' => ['title' => 'Kleine vliegende dingen'],
            'smart_home' => ['title' => 'Het huis dat dingen doet', 'blurb' => 'Je begint met een lamp en eindigt met een hobby.'],
            'clean_house' => ['title' => 'Schoonmaken, gemechaniseerd'],
            'laundry' => ['title' => 'Het wasprobleem', 'blurb' => 'Niemand heeft het opgelost. Sommige hiervan helpen.'],
            'storage' => ['title' => 'Waar laat je het allemaal'],
            'plants' => ['title' => 'Planten in leven houden', 'blurb' => 'Het eerlijke antwoord is licht en water op schema.'],
            'tools' => ['title' => 'Zelf repareren'],
            'car_care' => ['title' => 'In de auto', 'blurb' => 'De helft waar niemand aan denkt tot januari.'],
            'the_dog' => ['title' => 'Voor de hond'],
            'the_cat' => ['title' => 'Voor de kat', 'blurb' => 'Voor hen gekocht, door hen beoordeeld.'],
            'kids_making' => ['title' => 'Met opzet knoeien', 'blurb' => 'Voor een regenachtige middag met kinderen erin.'],
            'bricks' => ['title' => 'Dingen bouwen'],
            'writing' => ['title' => 'Op papier', 'blurb' => 'Nog altijd de snelste interface ooit ontworpen.'],
            'drawing' => ['title' => 'Slecht tekenen, met plezier'],
            'sewing' => ['title' => 'Gemaakt in plaats van gekocht'],
            'photography_kit' => ['title' => 'De spullen rond de camera', 'blurb' => 'Daar zitten de echte verbeteringen verstopt.'],
            'the_hallway' => ['title' => 'De eerste twee meter', 'blurb' => 'Het deel van het huis dat iedereen ziet en niemand inricht.'],
            'phone_life' => ['title' => 'De telefoon in leven houden'],
            'first_flat' => ['title' => 'Het eerste appartement', 'blurb' => 'Alles waarvan je pas op de tweede avond ontdekt dat je het nodig hebt.'],

            'grilling' => ['title' => 'Buiten koken'],
            'picnic' => ['title' => 'Eten op de grond', 'blurb' => 'Een kleed, een doos, en beter weer dan verwacht.'],
            'beach' => ['title' => 'Zand en zout'],
            'keeping_cool' => ['title' => 'De hitte doorkomen', 'blurb' => 'Elk jaar in paniek gekocht. Koop het nu.'],
            'camping' => ['title' => 'Buiten slapen'],
            'garden' => ['title' => 'De tuin, of het balkon'],
            'hiking' => ['title' => 'Een lange wandeling'],
            'cosy' => ['title' => 'Binnenblijven', 'blurb' => 'Het seizoen ervoor is begonnen, of je het er nu mee eens was of niet.'],
            'hot_drinks' => ['title' => 'Iets warms'],
            'rain' => ['title' => 'De natte maanden', 'blurb' => 'De jas die je in september had moeten kopen.'],
            'indoor_air' => ['title' => 'De lucht binnen', 'blurb' => 'Dichte ramen, vijf maanden, één klein apparaat.'],
            'winter_sports' => ['title' => 'Sneeuw, ooit'],
            'dark_evenings' => ['title' => 'Om vijf uur donker', 'blurb' => 'Vooral lampen, en één die de zon nadoet.'],
            'spring_clean' => ['title' => 'De jaarlijkse opruiming'],

            'early_summer' => ['title' => 'Het eerste warme weekend', 'blurb' => 'Het komt onaangekondigd en binnen twee weken is alles uitverkocht.'],
            'pool_side' => ['title' => 'Water in de tuin'],
            'grilling_season' => ['title' => 'Barbecueseizoen', 'blurb' => 'Nu gekocht, of in juli in de rij.'],
            'holiday_packing' => ['title' => 'Voor je vertrekt', 'blurb' => 'Het lijstje dat je onderweg naar de luchthaven opstelt.'],
            'school_run_up' => ['title' => 'Voor de school begint', 'blurb' => 'Nu goedkoper dan in de laatste week van augustus.'],
            'pre_halloween' => ['title' => 'Voor Halloween', 'blurb' => 'Kostuums die deze week gekozen worden, zijn beter dan die op de dag zelf.'],
            'autumn_indoors' => ['title' => 'Naar binnen'],
            'sinterklaas_run_up' => ['title' => 'Voor pakjesavond', 'blurb' => 'Het lijstje, nu er nog voorraad is.'],
            'gift_season' => ['title' => 'Cadeauseizoen', 'blurb' => 'Ideeën voor de mensen voor wie je nooit iets weet.'],
            'new_year_reset' => ['title' => 'De januari-reset', 'blurb' => 'In week één meent iedereen het. Sommige hiervan halen maart.'],
        ],

        'themes' => [
            'Dingen die niemand anders verkoopt',
            'Stilletjes uitstekend',
            'Lost een probleem op dat je hebt',
            'Vreemd maar bruikbaar',
            'De kastruimte waard',
            'Achteraan in de catalogus gevonden',
            'Je wist niet dat je dit nodig had',
        ],
        'deals_title' => 'Grootste dalers van nu',
        'deals_hint' => 'Gemeten tegen onze eigen mediaan over 30 dagen, niet tegen een doorgestreepte winkelprijs.',
    ],

    'guides' => [
        /*
         * The heading is the section's name; the `seo_*` pair is deliberately
         * not.
         *
         * "Slim kopen" is what this section is called on this site and is what
         * the header, the footer and the front page say — but nobody searches
         * for it, because it is our phrase. The <title> and the meta
         * description still lead with "koopgidsen", which is what a person
         * actually types. Our own name in an H1 and the reader's vocabulary in
         * the <title> is the normal split, not an inconsistency.
         *
         * It replaced "Inspiratie Coves" on 2026-09-01, which named a mood
         * where this shelf gives advice — see navigation.md.
         */
        'seo_title' => 'Koopgidsen met bij elk product een actuele prijs',
        'title' => 'Slim kopen',
        'subtitle' => 'Koopadvies en koopgidsen, geschreven op basis van wat mensen hier zoeken en niet van een zoekwoordtool.',
        'seo_description' => 'Koopgidsen gebouwd op echte zoekvraag, met live prijzen van elke winkel die het product heeft.',
        'empty' => 'Nog geen koopadvies. Het wordt geschreven zodra een onderwerp genoeg vraag opbouwt.',
        'how_to_choose' => 'Hoe kies je',
        'faq' => 'Vragen',
        'updated' => 'Gecontroleerd op :date',
        'why' => 'geschreven omdat :count zoekopdrachten hier erom vroegen',
        'shops' => ':count winkels',
        'unavailable' => 'Niet op voorraad',
        'slug_prefix' => 'beste',
        'template_title' => 'De beste :topic',
        'template_intro' => ':count opties voor :topic, met de prijs van elke winkel naast elkaar.',
    ],

    'discover' => [
        'dial_label' => 'Hoeveel weet je al?',
        'dial_low' => 'Ik weet precies wat ik wil',
        'dial_high' => 'Verras me',
        'surprise_label' => 'Verrassing',
        'query_placeholder' => 'Een product, een merk, of helemaal niets',
        'go' => 'Zoek',
        'thinking' => 'Bezig met herschikken…',
        'considered' => ':shown van :considered kandidaten getoond.',
        'empty' => 'Hier is nog niets te tonen.',
        'shops' => ':count winkels',
        'not_for_me' => 'Niets voor mij',
        'goal_placeholder' => 'Wat richt je in? bv. thuiswerkplek, koffiehoek',
        'kit_total' => ':count onderdelen · :total in totaal',
        'now_showing' => 'Nu: :mode',

        // Required of every mode: the dominant scoring factor, in words.
        'why' => [
            'relevance' => 'Ligt het dichtst bij wat je vroeg',
            'unexpectedness' => 'Je hebt dit waarschijnlijk nog nooit gezien',
            'novelty' => 'Nieuw hier',
            'quality' => 'Goed verkrijgbaar en makkelijk te vergelijken',
        ],

        'modes' => [
            'search' => [
                'title' => 'Zoeken',
                'description' => 'Je weet wat je wilt. De prijs van elke winkel, één kaart per product.',
            ],
            'guides' => [
                'title' => 'Gidsen',
                'description' => 'Iemand deed het denkwerk al, shortlists op basis van wat mensen hier zoeken.',
            ],
            'compare' => [
                'title' => 'Vergelijken',
                'description' => 'De hele categorie, van goedkoop naar duur, met de lookalikes aangeduid.',
            ],
            'deals' => [
                'title' => 'Koopjes',
                'description' => 'Echte kortingen, gemeten tegen onze eigen prijsgeschiedenis en tegen de andere winkels, nooit tegen een “van”-prijs van de winkel zelf.',
            ],
            'projects' => [
                'title' => 'Projecten',
                'description' => 'Vertel ons de situatie en een budget. Wij zetten de onderdelen bij elkaar en tellen ze op.',
            ],
            'trends' => [
                'title' => 'Nieuw en in opkomst',
                'description' => 'Net binnen, of deze twee weken door meer winkels opgepikt.',
            ],
            'follow' => [
                'title' => 'De huissmaak',
                'description' => 'Een rustige stroom van alles wat we recent hebben uitgekozen.',
            ],
            'serendipity' => [
                'title' => 'Verras me',
                'description' => 'Dingen waarvan je niet wist dat ze bestonden, precies daarop gerangschikt.',
            ],
        ],
    ],

    'og' => [
        'daily' => 'De Dagelijkse Cove',
        'default_title' => 'Ontdek producten en merken',
        'default_footnote' => 'giftcoves.com',
        'product' => 'Product',
        'guide' => 'Koopgids',
        'guide_footnote' => ':count producten, uitgezocht en beschreven',
        'brand' => 'Merk',
        'brand_footnote' => ':products producten bij :shops winkels',
        'shops' => '{1} 1 winkel|[2,*] :count winkels',
        'from_price' => 'vanaf :price',
    ],
];
