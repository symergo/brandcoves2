<?php

declare(strict_types=1);

/** French, serves the be-fr market. */
return [
    'nav' => [
        'friends' => 'Amis',
        'search' => 'Rechercher',
        'feedback' => 'Votre avis',
        'organise' => 'Organiser',
        'discover' => 'Découvrir',
        'submenu' => 'Ce que contient :section',
        'gift' => 'Trouver un cadeau',
        'daily' => 'Cove Quotidienne',
        'guides' => "Guides d'achat",
        'surprise' => 'Cove Surprise',
        'lists' => 'Mes listes',
        'shared_lists' => 'Listes partagées',
        'group_lists' => 'Listes de groupe',
        'notifications' => 'Notifications',
        'sign_in' => 'Se connecter',
        'sign_out' => 'Se déconnecter',
        'admin' => 'Administration',
        'main' => 'Menu principal',
        'account' => 'Compte',
        'skip' => 'Aller au contenu',
        'info' => 'En savoir plus',
        'search_and_help' => 'Recherche et aide',
        'share' => 'Partager',
        'close' => 'Fermer',
        'choose_market' => 'Choisissez votre région',
        'choose_language' => 'Choisissez votre langue',
        'countries' => [
            'be' => 'Belgique',
            'nl' => 'Pays-Bas',
            'int' => 'International',
            'es' => 'Espagne',
        ],
        /*
         * Les types de Cove, tels que le menu Découvrir les liste. Trois formes
         * d'une même chose : une édition qui change chaque matin, une étagère
         * construite autour d'une personne, et un long format construit autour
         * d'un sujet.
         *
         * Le qualificatif se traduit, le nom non — voir localisation.md.
         */
        'gift_coves' => 'Coves Cadeaux',
        'all_coves' => 'Toutes les Coves',
        'brand_coves' => 'Coves Marques',

        /*
         * Volontairement hors de la rangée ci-dessus, et volontairement sans
         * « Cove » dans son nom.
         *
         * Les autres étagères sont une forme — un cadeau, une marque, une
         * boutique — et « Cove » est notre mot pour ce que nous en faisons.
         * Celle-ci n'est pas une forme mais une promesse : c'est là qu'on
         * apprend à mieux acheter. « Coves Inspiration » ne disait ni l'un ni
         * l'autre, et qui cherche des conseils d'achat ne clique pas sur de
         * l'inspiration.
         *
         * Elle se traduit donc entièrement, contrairement aux noms de Cove :
         * Acheter malin / Shop Smarter / Slim kopen / Comprar mejor. La clé
         * est `smart`, le seul mot que les quatre gardent.
         */
        'smart' => 'Acheter malin',

        'hint_daily' => 'Nouveau chaque matin',
        'hint_surprise' => 'Quelque chose de rare, pas de populaire',
        'hint_smart' => "Conseils d'achat et guides par sujet",
        'hint_gift_coves' => "Des idées construites autour d'une personne",
        'hint_all_coves' => 'Tout ce que nous avons publié',
        'hint_ask' => "Laissez d'autres proposer quelque chose",

        'santa' => 'Ami Secret',

        // Des noms, pas des mots : les Coves portent le même nom dans toutes les
        // langues, comme GiftCoves lui-même. Un nom traduit est un second nom.
        'cove' => 'Gift Cove',
        'discover_cove' => 'Discover Cove',
    ],

    'home' => [
        // Voir l'explication de ces clés dans lang/en/site.php.
        'seo_description' => "Cherchez sur bol, Amazon et des centaines de boutiques à la fois. Gardez vos listes d'envies, partagez-les, cotisez à plusieurs et organisez un Ami Secret.",
        'title' => "GiftCoves : listes d'envies, offrir et recevoir au meilleur prix",
        'headline_1' => 'De quoi faire plaisir.',
        'headline_2' => 'À vous aussi.',
        'search_placeholder' => 'Cherchez un cadeau ou scannez un code-barres',
        'recent_heading' => 'Recherché récemment',
        'recently_viewed' => 'Vous avez regardé',
        'cta_gift' => 'Trouver un cadeau',
        'today_badge' => 'La Cove du jour',
        'today_cta' => 'Voir les trouvailles du jour',
        /*
         * The persona band, worded as the shelf at /gift-ideas words
         * itself. Two headings for one thing that read differently is
         * how a visitor ends up unsure whether they are the same page.
         */

        'coves_heading' => 'Coves',
        'coves_intro' => "Des dossiers autour d'un thème, où chaque marque et chaque produit renvoie vers une recherche en direct.",
        'coves_all' => 'Toutes les Coves',
        // The shape a Cove takes, named on the front page's Coves band.
        'cove_kind_persona' => 'Idée cadeau',
        'cove_kind_guide' => "Guide d'achat",
        'cove_kind_seasonal' => 'Guide de saison',
        'cove_kind_advice' => 'Conseil',
        'cove_kind_brand' => 'Marque',
        'cove_kind_shop' => 'Boutique',
        'coves_volume' => ':count recherches par mois',
        /*
         * The card for your own lists, which used to say nothing at all: the
         * band's opening sentence covered all five cards and this one was the
         * only card left leaning on it. With that sentence gone the card has to
         * say what it is, and privacy is the part a first-time visitor is
         * actually unsure about.
         */
        'organise_mine_hint' => 'Ce que vous voulez pour vous, à un seul endroit et privé tant que vous n’envoyez le lien à personne.',
        'organise_group_hint' => 'Un cadeau, plusieurs personnes, et personne ne court après l’argent.',
        'organise_occasion' => 'Occasion',
        'organise_occasion_hint' => 'Mettez une date sur une liste — un anniversaire, un mariage, Noël — et tous ceux qui ont le lien savent à quoi elle sert.',
        'organise_registry_on' => ':occasion le :date',
        'gifting_lists' => 'Listes',
        'gifting_lists_count' => ':count listes en cours',
        'gifting_santa' => 'Ami Secret',
        'gifting_santa_hint' => 'Un groupe, un tirage, personne ne sait qui a qui.',
        'gifting_santa_count' => ':count groupes que vous organisez',
    ],

    'search' => [
        'view' => 'Affichage',
        'filters_and_sort' => 'Filtres et tri',
        'watch' => 'Prévenez-moi des nouveautés',
        'watch_hint' => 'Nous regardons une fois par jour et vous prévenons quand quelque chose de nouveau correspond à cette recherche.',
        'watch_price_label' => 'Seulement en dessous de (facultatif)',
        'watch_confirm' => 'Suivre cette recherche',
        'watching' => 'Vous suivez cette recherche',
        'watching_under' => 'Vous suivez cette recherche, en dessous de :price',
        'stop_watching' => 'Arrêter',
        'watch_sign_in' => 'Connectez-vous pour suivre une recherche',
        'watch_created' => 'Nous vous préviendrons des nouveautés pour cette recherche.',
        'watch_removed' => 'Vous ne suivez plus cette recherche.',
        'watch_needs_term' => "Tapez d'abord quelque chose à suivre.",
        'show_results' => 'Voir les résultats',
        'remove_term' => 'Retirer :term',
        'title' => 'Recherche',
        'placeholder' => 'Cherchez un cadeau ou scannez un code-barres',
        'pasted_searched' => "C'est un lien Amazon. Nous y lisons :terms et avons cherché ce produit chez les boutiques que nous suivons.",
        'pasted_unreadable' => "C'est un lien Amazon, mais il ne contient aucun nom de produit lisible, seulement le code Amazon. Copiez le lien plus long qui contient le titre du produit, ou cherchez le produit par son nom.",
        'pasted_shortlink' => "C'est un lien Amazon raccourci, et nous n'ouvrons pas les liens pour voir où ils mènent. Ouvrez-le vous-même et collez l'adresse complète, ou cherchez le produit par son nom.",
        'submit' => 'Rechercher',
        'searching' => 'Recherche en cours…',
        'results_for' => 'Résultats pour « :term »',

        /*
         * The browser tab and the search listing, which is NOT `results_for`.
         *
         * `results_for` stays where it belongs: a live region announcing a new
         * result set to a screen reader. It is a poor listing title — the first
         * twelve characters, the ones weighted hardest, spend themselves on
         * "Results for", and the quotation marks read as an exact-match
         * citation rather than as a page about the thing.
         *
         * The term leads instead, capitalised, followed by the phrase this
         * market actually shops in.
         *
         * ## This one is allowed past 60 characters, and that is the trade
         *
         * The phrase alone is 39-48 characters, so it cannot share the ~60 a
         * listing shows with a real search term — there is no wording of "at
         * the best price - offers and discounts" that leaves room for
         * "koptelefoon". Chosen deliberately on 2026-09-05: the words that earn
         * the click are all in front, and what a search engine drops off the
         * end is " · GiftCoves" and possibly the last word of the phrase.
         *
         * Every other interpolated title on the site still measures itself and
         * degrades. This one only guards the term: past 30 characters the query
         * is a sentence, already carries its own intent, and stands alone.
         */
        'seo_title_term' => ':term au meilleur prix - offres et promotions',
        'empty' => 'Aucun résultat pour « :term ».',
        'empty_filters' => 'Aucun produit ne correspond à ces filtres.',
        'clear_filters' => 'Effacer tous les filtres',
        'sort' => 'Trier',
        'sort_relevance' => 'Plus pertinents',
        'sort_price_asc' => 'Les moins chers',
        'sort_price_desc' => 'Les plus chers',
        'sort_discount' => 'Plus grosse remise',
        'sort_newest' => 'Nouveautés',
        'view_grid' => 'Grille',
        'view_store' => 'Par boutique',
        'filters' => 'Filtres',
        'brand' => 'Marque',
        'shop' => 'Boutique',
        'all_shops' => 'Toutes les boutiques',
        'only_shop' => 'Afficher uniquement :shop',
        'hide_shop' => 'Ne plus afficher :shop',
        'in_stock_only' => 'En stock uniquement',
        'discounted_only' => 'En promotion uniquement',
        // Voir l'explication de ces clés dans lang/en/site.php.
        'amazon_search' => 'Cherchez aussi :term sur Amazon',
        'amazon_search_any' => 'Essayez de chercher sur Amazon',
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'page_of' => 'Page :current sur :last',
        'seo_term' => 'Trouvez :term sur bol, Amazon et des centaines de boutiques. Une fiche par produit, avec le meilleur prix et les promotions.',

        /*
         * Le vocabulaire des résultats, au-dessus de la grille. Il a remplacé
         * quatre paragraphes de statistiques : ces chiffres étaient exacts, mais
         * ils comptaient ce qui était déjà à l'écran. Les mots sont la partie
         * utile, et en tant que liens ils servent aussi de navigation.
         */
        'terms_heading' => 'Affinez votre recherche avec',
        'seo_default' => 'Découvrez des produits et des marques sur bol, Amazon et des centaines de boutiques à la fois, avec un lien vers chaque boutique qui les vend.',
    ],

    /*
     * Pages de marque. Chaque phrase n'apparaît que si le chiffre correspondant
     * existe, voir App\Services\Seo\BrandCopy.
     */
    'brand' => [
        'title' => ':brand offres et promotions',
        'heading' => ':brand',
        'seo_description' => 'Tous les produits :brand avec le prix de chaque boutique qui les vend. Comparez les offres et voyez où :brand coûte le moins cher.',
        'crumb' => 'Marques',
        'index_title' => 'Marques',
        'index_seo_title' => 'Toutes les marques de A à Z',
        'index_seo_description' => 'Toutes les marques du catalogue, avec les prix actuels de bol, Amazon et des centaines de boutiques qui les vendent.',
        'index_intro' => 'Toutes les marques du catalogue, avec les prix actuels des boutiques qui les vendent.',
        'index_empty' => 'Encore aucune marque dans cette région.',
        'products_heading' => 'Produits :brand',
        'coves_heading' => 'Coves qui mentionnent :brand',
        'related_heading' => 'D\'autres marques que les gens regardent',
        'empty' => 'Rien de :brand n\'est en stock pour le moment.',
        'and' => 'et',
        // Offres d'une source que nous pouvons afficher mais pas conserver.
        'live_heading' => 'Plus de :brand, récupéré à l\'instant',
        'live_note' => 'Récupéré en direct chez une boutique dont nous n\'avons pas le droit de conserver les prix : ce sont donc des offres isolées et non une fiche produit complète.',
    ],
    /*
     * Texte long sous une grille de résultats. Chaque ligne est soit un fait lu
     * sur la page, soit une explication exacte du fonctionnement du site.
     */

    /*
     * La même idée sur une page de marque : ce lecteur a déjà choisi la marque.
     */

    'product' => [
        'from' => 'à partir de',
        'one_offer' => '1 offre',
        'offers' => ':count offres',
        'across_shops' => 'dans :count boutiques',
        'one_shop' => 'dans 1 boutique',
        'off' => ':percent% de remise',
        'out_of_stock' => 'En rupture de stock',
        'in_stock' => 'En stock',
        'compare' => 'Comparer :count offres',
        'all_offers' => 'Toutes les offres',
        'go_to_shop' => 'Voir la boutique',
        'typical_price' => 'Prix habituel :price',
        'barcode' => 'Code-barres',
        // Voir l'explication de ces clés dans lang/en/site.php.
        'description_heading' => 'À propos de ce produit',
        'description_source' => 'Description fournie par :shop.',
        'amazon_search' => 'Cherchez aussi ce produit sur Amazon',
        'amazon_search_barcode' => 'Par code-barres :ean',
        'price_as_of' => 'Prix et disponibilité au moment indiqué, susceptibles de changer.',
        'disclosure' => 'Nous pouvons percevoir une commission si vous achetez via ce lien. Le prix que vous payez ne change pas.',
        'unavailable' => "Ce produit n'est actuellement disponible dans aucune boutique que nous suivons.",
        'seo_compare' => 'À partir de :price chez :count boutiques. Comparez tous les vendeurs de :title et voyez où il est le moins cher.',
        'seo_single' => 'À partir de :price, avec l’historique des prix avant d’acheter. :title',
        // Nothing is priced yet, so neither of the two above will do:
        // both open with a price and would print an empty gap where it goes.
        'seo_unpriced' => ':title — les boutiques qui le vendent, avec le prix dès qu’il est connu.',

        /*
         * The shop count goes in the title; the price stays in the description.
         *
         * Both are ours to claim and only one of them is safe up there. A
         * cached snippet quoting a price we no longer offer is a trust problem,
         * and the price is the number most likely to have moved since the last
         * crawl. A merchant count barely moves. The JSON-LD AggregateOffer
         * remains the honest machine-readable copy of both.
         */
        'seo_title_multi' => ':title — chez :count boutiques',
    ],

    /*
     * Abonnements aux Coves. Toutes les réponses du formulaire sont identiques,
     * quoi qu'il se soit passé.
     */
    'discover_cove' => [
        'seo_title' => 'Idées cadeaux et trouvailles, chaque jour',
        'seo_description' => 'Trois façons de trouver ce que vous ne cherchiez pas : une nouvelle édition chaque jour, une surprise choisie pour sa rareté, et des lectures par thème.',
        'title' => 'Découvrir',
        'intro' => "Des façons de trouver ce que vous ne cherchiez pas. L'une change chaque jour, une autre est volontairement imprévisible, une autre parle d'une personne plutôt que d'un objet, et les dernières se lisent tranquillement.",
        'daily_what' => 'Une nouvelle édition chaque jour : un thème, quelques trouvailles et une énigme de prix. Chaque édition passée garde sa page.',
        'surprise_what' => "Quelque chose dont vous ignoriez l'existence, choisi pour sa rareté et non pour ses ventes.",
        'idea_what' => "Conseils d'achat et guides autour d'un seul sujet : ce à quoi regarder et ce qui fait vraiment la différence, avec chaque marque et chaque produit reliés directement à une recherche en direct.",
        'persona_what' => "Des cadeaux choisis autour d'une personne plutôt que d'une date : le fanatique de café, celui qui a déjà tout.",
        'persona_all' => 'Toutes les idées cadeaux',
    ],

    'shops' => [
        'seo_title' => 'Les boutiques en ligne partenaires',
        'seo_description' => 'Chaque boutique dont les offres apparaissent ici, les plus récentes mises en avant. Pas de totaux, juste la liste.',
        'title' => 'Coves Boutiques',
        'intro' => "Chaque offre sur ce site nomme la boutique d'où elle vient. Voici ces boutiques — celles qui desservent cette région.",
        'empty' => 'Aucune boutique raccordée pour cette région pour le moment.',
        'coves_heading' => 'Écrit sur ces boutiques',
        'coves_what' => "Ce que c'est que d'acheter chez une boutique — la moitié de la décision qu'un prix ne tranche pas.",
        'new_heading' => 'Nouvelles ici',
        'new_what' => "Raccordées le mois dernier. Elles figurent aussi dans la liste ci-dessous — c'est une mise en avant, pas un filtre.",
        'new_badge' => 'Nouveau',
        'all_heading' => 'Toutes les boutiques',
    ],

    'coves' => [
        'seo_title' => 'Idées cadeaux, guides et lectures',
        'seo_description' => 'L’étagère complète : une nouvelle édition chaque matin, des idées cadeaux autour d’une personne, et des longs formats avec les prix en direct.',
        'title' => 'Toutes les Coves',
        'intro' => "Tout ce que nous avons écrit ici, classé par forme. L'une arrive chaque matin, l'autre est construite autour d'une personne, la troisième autour d'un sujet.",
        'empty' => 'Rien de publié dans cette région pour le moment. Les premières Coves arrivent.',
        'daily_heading' => 'Cove Quotidienne',
        'daily_what' => 'Une édition chaque matin : un thème, quelques trouvailles et une énigme de prix. Chaque édition passée garde sa propre page.',
        'daily_all' => "Lire l'édition du jour",
        'gift_heading' => 'Coves Cadeaux',
        'gift_what' => "Construites autour d'une personne plutôt que d'une date — l'herboriste, le père qui a déjà tout, l'ami qui lit.",
        'gift_all' => 'Toutes les Coves Cadeaux',
        'smart_heading' => 'Acheter malin',
        'smart_what' => "Conseils d'achat et guides : ce à quoi regarder, ce qui fait la différence et ce que ça devrait coûter — des longs formats autour d'un seul sujet.",
        'smart_all' => "Tous les conseils d'achat",
        'brand_heading' => 'Coves Marques',
        'brand_what' => "Une page par marque : tout ce que nous portons d'elle ici, avec le prix de chaque boutique sur chaque produit.",
        'brand_all' => 'Toutes les Coves Marques',
        'shop_heading' => 'Coves Boutiques',
        'shop_what' => "Les boutiques en ligne qui desservent cette région, les plus récentes d'abord.",
        'shop_all' => 'Toutes les Coves Boutiques',
        'rail_products' => 'Plus dans ces catégories',
    ],

    'cove' => [
        'subscribe_heading' => 'La Cove, chaque matin',
        'subscribe_intro' => 'Un court e-mail par jour : le thème, quelques trouvailles et pourquoi elles valent le détour. Pas de spam produit, et un clic pour se désabonner.',
        'subscribe_placeholder' => 'vous@exemple.be',
        'subscribe_button' => 'Envoyez-la-moi',
        'subscribe_thanks' => 'Regardez votre boîte, si cette adresse nous est inconnue, un lien de confirmation est en route.',
        'subscribe_privacy' => "Nous utilisons votre adresse pour cet e-mail et rien d'autre.",
        'confirm_done' => 'Vous êtes inscrit. La prochaine Cove arrive demain matin.',
        'confirm_invalid' => 'Ce lien a expiré ou a déjà été utilisé. Réinscrivez-vous pour en recevoir un nouveau.',
        'unsubscribed' => 'Vous êtes désinscrit. Sans rancune.',
    ],
    'suggestions' => [
        'added' => 'Ajouté à la liste.',
        'add_invite' => 'Ajoutez quelque chose à cette liste',
        'add_invite_hint' => 'Ce que vous ajoutez apparaît tout de suite, visible et réservable par tous.',
        'add_action' => 'Ajouter à la liste',
        'heading' => 'Propositions',
        'from' => 'De :name',
        'from_anonymous' => 'De quelqu’un qui a votre lien',
        'waiting' => ':count en attente',
        'one_waiting' => '1 en attente',
        'note_label' => 'Ajouter une note',
        'accept' => 'Ajouter',
        'dismiss' => 'Non merci',
        'sent' => 'Envoyé. À eux de décider si cela rejoint la liste.',
        'accepted' => 'Ajouté à votre liste.',
        'dismissed' => 'Écarté.',
        'suggest' => 'Proposer quelque chose',
        'invite' => 'Vous savez ce qui leur ferait plaisir ?',
        'invite_hint' => 'Proposez-le !',
        'search_placeholder' => 'Cherchez quelque chose qui leur plairait',
        'none_found' => 'Rien ne correspond. Essayez un autre mot.',
        'already_on_list' => 'Celui-là est déjà sur la liste.',
        'manual_hint' => 'Introuvable dans les boutiques que nous couvrons ? Proposez-le quand même, la décision reste la leur.',
    ],

    'registry' => [
        'hint' => 'Dites à quoi sert cette liste, et quand. Tous ceux à qui vous envoyez le lien le voient.',
        'occasion' => 'Occasion',
        'none' => 'Aucune occasion',
        'date' => 'Date',
        'address' => 'Adresse de livraison',
        'address_hint' => "Stockée chiffrée, et visible uniquement par quelqu'un qui a réservé un article.",
        'send_to' => 'Où l’envoyer',
        'address_locked' => 'Réservez un article et l’adresse de livraison apparaîtra ici.',
        'occasion_on' => ':occasion le :date',
        'types' => [
            'birthday' => 'Anniversaire',
            'christmas' => 'Noël',
            'wedding' => 'Mariage',
            'anniversary' => 'Anniversaire de mariage',
            'baby' => 'Naissance',
            'housewarming' => 'Nouveau logement',
            'graduation' => 'Diplôme',
            'retirement' => 'Départ à la retraite',
            'farewell' => 'Pot de départ',
            'valentines' => 'Saint-Valentin',
            'mothers_day' => 'Fête des mères',
            'fathers_day' => 'Fête des pères',
            'thank_you' => 'Remerciement',
            'other' => 'Autre chose',
        ],
        'badge' => 'Occasion spéciale',
    ],

    'handover' => [
        'hint' => 'Donnez la liste à quelqu’un. Le destinataire en devient le propriétaire et peut la partager avec d’autres.',
        'action' => 'La transmettre',
        'confirm' => 'Donner cette liste à :name ? Elle ne sera plus la vôtre.',
        'done' => 'Transmise à :name.',
        'already' => 'Cette liste a déjà été transmise.',
        'only_gift_lists' => "Seule une liste pour quelqu'un d'autre peut être transmise.",
        'no_account' => "Personne avec cette adresse n'a encore de compte ici. Envoyez-lui d'abord le lien pour indiquer ses envies.",
        'badge' => 'Transmettre',
    ],

    'votes' => [
        'vote' => 'Voter pour',
        'voted' => 'Voté',
        'none' => 'Pas encore de votes',
        'count' => ':count votes',
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
        'title' => 'En discuter',
        'hint' => 'Toute personne ayant le lien peut lire ceci. Pas la personne à qui la liste est destinée.',
        'empty' => 'Rien encore. Lancez la conversation.',
        'placeholder' => 'On partage le manteau à deux ?',
        'your_name' => 'Votre nom',
        'post' => 'Publier',
        'remove' => 'Supprimer',
        'posted' => 'Publié.',
        'removed' => 'Supprimé.',
    ],

    'pledges' => [
        'hint' => 'Indiquez votre part. Une personne achète et vous vous arrangez entre vous.',
        'amount' => 'Votre part',
        'your_name' => 'Votre nom',
        'added' => 'Vous en êtes.',
        'removed' => 'Retiré.',
        'pledged' => ':total réunis sur :price',
        'join' => "J'en suis",
        'leave' => 'Finalement non',

        'count' => ':count personnes en sont',
        'one_in' => 'Une personne en est',
        'standard_share' => 'Vous participez pour :amount.',
        'none' => 'Personne n’a encore rien mis.',
        'your_share_is' => 'Vous avez mis :amount',
        'organiser_note' => 'Vous voyez qui a mis quoi. Les autres voient le total et leur propre part.',
    ],

    'cove_mail' => [
        'confirm_subject' => 'Confirmez votre abonnement à la Cove Quotidienne',
        'confirm_heading' => 'Un clic et c’est fait',
        'confirm_body' => "Cliquez ci-dessous pour confirmer que vous voulez la Cove Quotidienne. D'ici là, nous ne vous enverrons rien d'autre.",
        'confirm_button' => 'Confirmer mon abonnement',
        'confirm_expiry' => 'Le lien fonctionne pendant 48 heures.',
        'confirm_requested_from' => 'Demandé depuis :ip',
        'confirm_ignore' => "Si ce n'était pas vous, ignorez cet e-mail, rien ne se passe sans le clic et nous ne réécrirons pas.",

        'digest_subject' => 'La Cove du jour : :theme',
        'digest_button' => 'Ouvrir la Cove du jour',
        'across_shops' => 'dans :count boutiques',
        'more_on_page' => 'Il y a :count autres trouvailles sur la page, dont certaines que nous ne pouvons montrer que là.',
        'why_receiving' => 'Vous recevez ceci parce que vous avez confirmé un abonnement à la Cove Quotidienne.',
        'unsubscribe' => 'Se désabonner',
    ],
    'legal' => [
        'about' => 'À propos de GiftCoves',
        'privacy' => 'Confidentialité',
        'terms' => 'Conditions',
        'cookies' => 'Cookies',
        'updated' => 'Dernière mise à jour le :date',
        'untranslated' => "Cette page n'est pas encore traduite : vous lisez la version anglaise, qui fait foi.",
    ],

    /*
     * La bannière cookies. Une question, posée une fois.
     */
    'cookies' => [
        'title' => 'Cookies',
        'body' => "Nous aimerions compter les visites avec Google Analytics, ce qui dépose un cookie. Rien sur ce site n'en a besoin : c'est vous qui décidez.",
        'accept' => 'Autoriser',
        'decline' => 'Non merci',
        'more' => 'Ce que nous collectons',
    ],

    'footer' => [
        'affiliate' => 'Nous pouvons percevoir une commission sur les achats effectués via nos liens, cela ne change jamais le prix que vous payez.',
        'copyright' => '© :year GiftCoves.',
        'explore' => 'Explorer',
    ],

    'auth' => [
        'title' => 'Se connecter',
        'intro' => 'Indiquez votre adresse e-mail et nous vous enverrons un lien. Aucun mot de passe à retenir.',
        'email' => 'Adresse e-mail',
        'send' => 'Envoyez-moi un lien',
        'link_sent' => 'Consultez votre boîte de réception, si un compte existe pour cette adresse, le lien est en route.',
        'link_invalid' => 'Ce lien a expiré ou a déjà été utilisé. Demandez-en un nouveau.',
        'too_many' => 'Trop de demandes. Réessayez dans :seconds secondes.',
        'or' => 'ou',
        'google' => 'Continuer avec Google',
        'mail_subject' => 'Votre lien de connexion GiftCoves',
        'mail_heading' => 'Connexion à GiftCoves',
        'mail_body' => 'Appuyez sur le bouton ci-dessous pour vous connecter. Le lien fonctionne une seule fois et uniquement depuis cet e-mail.',
        'mail_button' => 'Se connecter',
        'mail_expiry' => 'Le lien expire dans 15 minutes.',
        'mail_requested_from' => 'Demandé depuis :ip',
        'mail_ignore' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail, personne ne peut se connecter sans le lien.',
        'mail_fallback' => 'Si le bouton ne fonctionne pas, collez ceci dans votre navigateur :',
        'name' => 'Votre nom (facultatif)',
        'mail_failed' => "Nous n'avons pas pu envoyer l'e-mail pour le moment. Réessayez dans un instant.",
    ],

    'lists' => [

        // Public, and it explains itself to a visitor with no account,
        // so it is indexable. It shipped with no title and no description
        // at all until 2026-09-05.
        'seo_title' => 'Des listes d’envies à partager',
        'seo_description' => 'Tenez une liste d’envies, partagez-la avec ceux qui vous offrent quelque chose, et laissez-les réserver un cadeau sans savoir qui a pris quoi.',
        'title' => 'Mes listes',
        'shared_subtitle' => 'Les listes qu’on a partagées avec vous. C’est ainsi que vous leur trouvez un cadeau.',
        'shared_empty' => 'Personne ne vous a encore partagé de liste. Dès que ce sera le cas, elle apparaîtra ici — avec ce qui leur ferait plaisir.',
        'shop_for' => 'Réservez quelque chose pour :name',
        'shared_with_me' => 'Partagées avec moi',
        'owned_by' => 'De :name',
        'group_subtitle' => 'Un cadeau, choisi ensemble. Chacun vote, et ce que vous mettez reste entre vous et l’organisateur.',
        'default_title' => 'Ma liste de souhaits',
        'default_badge' => 'Par défaut',
        'shared_short' => 'Partagée',
        'private_short' => 'Privée',
        'tool_on' => 'activé',
        'find_things' => 'Trouver quelque chose à ajouter',
        'manual_add' => 'Ajoutez-le vous-même',
        'manual_title' => 'De quoi s’agit-il ?',
        'edit_item' => 'Modifier',
        'save_changes' => 'Enregistrer',
        'manual_url' => 'Lien (facultatif)',
        'manual_price' => 'Prix (facultatif)',
        'manual_save' => 'Ajouter',
        'manual_url_invalid' => 'Un lien doit commencer par https://',
        'added_to' => 'Enregistré dans :list',
        'view_list' => 'Voir la liste',
        'undo' => 'Annuler',
        'save_failed' => 'Enregistrement impossible. Réessayer ?',
        'adding_to' => 'Ajout à :list',
        'added_count' => ':count ajoutés',
        'done_adding' => 'Terminé',
        'add_to_this' => 'Ajouter à :list',
        'add_product' => 'Ajouter un produit',
        'add_search_placeholder' => 'Rechercher un produit...',
        'search_failed' => 'La recherche a échoué. Réessayer ?',
        'add_nothing_found' => 'Rien trouvé pour « :term ».',
        'add_own_intro' => 'Pas dans les boutiques que nous couvrons ?',
        'add_own_cta' => 'Ajoutez-le vous-même',
        'add_description' => 'Description',
        'add_note_placeholder' => 'taille M, en bleu',
        'add_live_title_note' => 'Le titre et le prix viennent directement de :shop et ne peuvent pas être modifiés ici.',
        'back' => 'Retour',
        'new_list' => 'Nouvelle liste',
        'make_new' => 'Créer une nouvelle liste',
        'list_name' => 'Nom de la liste',
        'create' => 'Créer la liste',
        'for_someone' => 'Cette liste est pour quelqu\'un d\'autre',
        'for_whom' => 'Pour qui ?',
        'empty' => 'Rien enregistré pour le moment.',
        'empty_hint' => 'Cherchez un produit et appuyez sur Enregistrer.',
        'empty_list' => 'Cette liste est vide.',
        'empty_mine_step1' => 'Ajoutez ce qui vous ferait plaisir. Le marque-page sur un produit le met ici.',
        'empty_mine_step2' => 'Appuyez sur Partager quand vous le voulez. Pas avant : elle est à vous jusque-là.',
        'empty_mine_step3' => 'On indique ce qu’on vous offre, ou vous envoyez la liste sous forme de quiz.',
        'empty_for_someone_step1' => 'Ajoutez des idées au fil de vos trouvailles. Personne d’autre ne la voit encore.',
        'empty_for_someone_step2' => 'Si d’autres participent, appuyez sur Partager et ajoutez-les par e-mail.',
        'empty_for_someone_step3' => 'Qui achète quelque chose le réserve, pour éviter les doublons.',
        'empty_group_step1' => 'Ajoutez quelques candidats. Vous en choisirez un ensemble.',
        'empty_group_step2' => 'Appuyez sur Partager et invitez les autres.',
        'empty_group_step3' => 'Ils votent pour celui à offrir et disent ce qu’ils peuvent mettre.',
        'items' => ':count articles',
        'one_item' => '1 article',
        'added' => 'Enregistré dans votre liste.',
        'removed' => 'Retiré.',
        'remove' => 'Retirer',
        'save' => 'Enregistrer',
        'saved' => 'Enregistré',
        'save_to_list' => 'Enregistrer dans une liste',
        'save_to' => 'Enregistrer dans :list',
        'remove_from' => 'Retirer de :list',
        'delete_list' => 'Supprimer cette liste',
        'delete' => 'Supprimer',
        'settings' => 'Réglages',
        'title_label' => 'Nom de la liste',
        'recipient_label' => 'Pour qui',
        'description_label' => 'Description',
        'delete_confirm' => 'Supprimer cette liste et tout son contenu ?',
        'share' => 'Partager',
        'sharing_off' => 'Vous seul voyez cette liste.',
        'sharing_on' => 'Toute personne ayant le lien peut voir cette liste.',
        'share_hint' => 'Cette liste est privée. Partagez-la et toute personne ayant le lien pourra la voir.',
        'disable_sharing' => 'Arrêter le partage',
        'disable_sharing_confirm' => 'Arrêter le partage ? Tous les liens que vous avez envoyés cesseront de fonctionner, et partager à nouveau en créera un nouveau.',
        'remove_confirm' => 'Retirer :title de cette liste ?',
        'anyone_can_add' => 'Tout le monde peut ajouter des cadeaux',
        'anyone_can_add_hint' => 'Proposer des cadeaux reste toujours possible.',
        'pledgers_visible' => 'Tout le monde voit qui participe',
        'pledgers_visible_hint' => 'Les noms seulement. Qui a mis combien reste pour vous seul.',
        'voting_enabled' => 'Tout le monde peut voter pour les cadeaux',
        'voting_enabled_hint' => 'La liste se trie selon les votes. Désactivez-le si le cadeau est déjà choisi.',
        'price_watch' => 'Suivre les prix de cette liste',
        'price_watch_hint' => 'Un e-mail le matin quand un article de la liste baisse de prix ou est de nouveau en stock.',
        'price_watch_threshold' => 'Prévenez-moi à partir d’une baisse de',
        'pledge_mode' => 'Comment chaque personne participe',
        'pledge_mode_each' => 'Chaque personne indique ce qu’elle met',
        'pledge_mode_fixed' => 'Tout le monde met la même chose',
        'pledge_mode_each_person' => 'par personne',
        'copy_link' => 'Copier le lien',
        'copied' => 'Lien copié',
        'claim' => 'Je m\'en occupe',
        'claimed' => 'Je m’en occupe',
        'claimed_by_someone' => 'Quelqu\'un s\'en occupe',
        'unclaim' => 'Finalement non',
        'already_claimed' => 'Quelqu\'un vient de le prendre.',
        'cannot_unclaim' => 'Vous ne pouvez annuler que votre propre choix.',
        'shared_intro' => 'Touchez un article pour indiquer que vous l\'offrez. :name ne verra pas qui offre quoi.',
        'recipient_added' => 'Personne ajoutée.',
        'recipient_removed' => 'Personne retirée.',
        'add_person' => 'Ajouter une personne',
        'person_name' => 'Son nom',
        'someone_new' => 'Une nouvelle personne',
        'from_your_friends' => 'Parmi vos amis',
        'copy_to' => 'Copier vers une autre liste',
        'copy_to_which' => 'Vers quelle liste ?',
        'copied_to' => 'Copié vers :list.',
        'add_to_my_list' => 'Ajouter à ma liste',
        'birthday_optional' => 'Son anniversaire (facultatif)',
        'birthday_why' => 'Jour et mois seulement. Nous l’utilisons pour vous prévenir à temps, jamais pour déduire son âge.',
        'birthday_day' => 'Jour',
        'birthday_month' => 'Mois',
        'price_now' => 'Maintenant :price',
        'sign_in_to_keep' => 'Connectez-vous pour conserver vos listes',
        'sign_in_hint' => 'Connectez-vous et tout ce que vous enregistrez reste sur votre compte, sur tous vos appareils.',
        'cannot_mark_sent' => 'Vous ne pouvez le faire que pour un article que vous avez réservé.',
        'mark_sent' => 'Je l’ai acheté',
        'sent' => 'Acheté',
        'progress' => ':claimed sur :total réservés',
        'asked_none' => ":name n'a encore rien mis sur une liste.",
        'ask_tab' => 'Demander des suggestions à :name',
        'ask_chip' => 'Demander des suggestions',
        'collaborator_removed' => 'Retiré.',
        'who_sees_what' => 'Qui voit quoi',
        'share_link' => 'Le lien vers cette liste',
        'copy_message' => 'Copier le message et le lien',
        'copy_manual' => 'Votre navigateur a refusé la copie. Le lien est sélectionné — appuyez sur Ctrl+C.',
        'invited_before' => 'Invités avant que le partage ne devienne un lien',
        'role_viewer' => 'Peut regarder',
        'role_editor' => 'Peut ajouter des articles',
        'adding_allowed' => 'Ajouts autorisés',
        'share_email' => 'E-mail',
        'friends' => 'Listes d’amis',
        'friends_empty' => "Personne que vous suivez n'a encore de liste publique.",
        'follow' => 'Suivre',
        'unfollow' => 'Ne plus suivre',
        'followed' => 'Vous les suivez maintenant.',
        'shared_intro_anon' => 'Touchez un article pour indiquer que vous l’offrez. La personne qui a fait cette liste ne verra pas qui a réservé quoi.',
        'shared_intro_gift' => 'Cette liste de cadeaux est partagée avec plusieurs personnes. Indiquez ce que vous prenez pour éviter les doublons.',
        'shared_intro_group' => 'Vous offrez un seul cadeau ensemble. Votez pour celui à prendre, puis dites ce que vous pouvez mettre.',
        'progress_gift' => ':claimed sur :total déjà pris',
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
        'claimed_by' => ':name s’en charge',
        'claim_anonymous_note' => 'Personne ne saura que c’était vous — pas même la personne qui gère cette liste.',
        'claim_named_note' => 'Votre nom sera visible par les autres sur cette liste, pour que tout le monde sache qui offre quoi.',
        'claim_sign_in_hint' => 'Connectez-vous et cela reste à vous : vous voyez ce que vous offrez depuis n’importe quel appareil, et vous pouvez y renoncer si vos plans changent.',
        'claimed_item' => 'Vous offrez :item.',
        'claim_names_visible' => 'Les noms de qui achète quoi sont visibles (sauf pour le destinataire)',
        'claim_mine_show_hint_mine' => 'Désactivé par défaut : une liste d’envies fonctionne parce que vous ne savez pas ce qui arrive. Activez-le si vous préférez voir.',
        'claim_mine_show' => 'Montrez-moi ce qui est réservé',
        'claim_mine' => 'Ce que vous voyez',
        'kind_mine' => 'Liste d’envies',
        'kind_for_someone' => 'Liste cadeaux',
        'kind_group' => 'Cadeau groupé',
        'about_mine_private' => 'Ce que vous gardez de côté. Vous seul la voyez — partagez-la et on pourra réserver un cadeau, sans que vous sachiez jamais lequel.',
        'about_mine_shared' => 'On peut réserver un cadeau sur cette liste. Vous ne saurez jamais lequel.',
        'about_for_someone_private' => 'Une liste à leur sujet, que vous seul voyez. Partagez-la si vous êtes plusieurs à acheter.',
        'about_for_someone_shared' => 'Chaque personne achète quelque chose de différent. Réservez-en un pour éviter les doublons.',
        'about_group_private' => 'Personne ne peut encore participer. Cliquez sur Partager pour inviter.',
        'about_group_shared' => 'Vous achetez un seul cadeau ensemble. Votez, puis dites ce que vous pouvez mettre.',
        'quiz_unlocks' => 'Partagez-la et vous pourrez en faire un quiz : quatre produits, un seul vraiment à vous. Voyez qui vous connaît le mieux.',
        'new_mine_body' => 'Ce qui vous ferait plaisir. Gardez-la pour vous, ou partagez-la et chaque personne réserve ce qu’elle prend.',
        'new_for_someone_body' => 'Une liste à leur sujet. Gardez-la pour vous, ou partagez-la : vous la construisez alors ensemble et répartissez les achats.',
        'new_group_body' => 'Vous achetez un seul cadeau à plusieurs et le partagez. Chacun vote et participe.',

        'for_me' => 'Pour moi',
        'for_someone_else' => 'Pour quelqu’un d’autre',
        'for_group' => 'À plusieurs, pour quelqu’un',
        'group_gift' => 'Cadeau commun',
        'start_group_gift' => 'Lancer un cadeau commun',
        'for_person' => 'Pour :name',
        'cancel' => 'Annuler',
        'share_text' => 'Voici ma liste : :title',
        'share_with_friends' => 'Partager avec des amis',
        'share_with_friends_hint' => 'Touchez un nom et cette personne reçoit le lien par e-mail.',
        'share_with' => 'Partager avec :name',
        'enable_sharing' => 'Activer le partage',
        'unshare_from' => 'Ne plus partager avec :name',
        'unshare_confirm' => 'Ne plus partager cette liste avec :name ? Un lien déjà reçu fonctionne toujours.',
        'already_shared' => 'déjà partagée',
        'shared_with_nobody' => 'Personne de nouveau à qui l\'envoyer.',
        'someones_wishlist' => 'La liste de :name',
        'shared_by' => ':name a partagé cette liste',
        'note_add' => 'Ajouter un mot',
        'note_edit' => 'Modifier',
        'note_placeholder' => 'Ce que les personnes qui ouvrent ceci doivent savoir.',
        'share_native' => 'Plus d’applications…',
        'share_instagram' => 'Instagram n’accepte pas de liens depuis un navigateur — copiez-le et collez-le là-bas.',
        'shared_badge' => 'Partagée — visible avec le lien',
        'private_badge' => 'Privée',
        'owner_view_note' => 'C\'est votre liste, donc les réservations vous sont cachées, c\'est le principe.',
    ],

    'recipients' => [
        'step_birthday' => 'Quand est votre anniversaire ?',
        'birthday_why' => 'Jour et mois seulement, pour qu’on puisse les prévenir à temps. Nous ne demandons jamais l’année.',
        'self_title' => 'Dites-leur ce qui vous ferait vraiment plaisir',
        'self_intro' => 'Quelqu’un cherche un cadeau pour vous, :name. Répondez comme vous voulez, et ajoutez ce qui vous plairait vraiment.',
        'saved' => 'Enregistré. Ils le verront la prochaine fois.',
        'linked' => 'C’est bien vous.',
        'claim_this_is_me' => 'C’est moi',
        'claim_is_you' => 'C’est le lien que vous leur envoyez. Vous avez créé cette liste, elle ne peut donc pas être la vôtre — c’est à eux de dire "c’est moi".',
        'claim_sign_in' => 'Connectez-vous pour dire que c’est vous. Cette liste devient alors la vôtre, et vous pouvez la partager avec qui vous voulez.',
        'claim_hint' => 'Liez ceci à votre compte et vos propres listes apparaîtront quand on cherchera pour vous.',
        'my_list' => 'Ce qui plairait à :name',
        'about_you' => 'À propos de vous',
        'step_interests' => 'Qu’est-ce qui vous plaît ?',
        'step_vibe' => 'Quel effet doit-il faire ?',
        'step_values' => 'Qu’est-ce qui compte pour vous ?',
        'your_list' => 'Ce qui vous ferait plaisir',
        'add_something' => 'Ajouter quelque chose',
        'search_placeholder' => 'Cherchez quelque chose qui vous plait',
        'suggest' => 'Montrez-moi des idées',
        'nothing_yet' => 'Rien pour le moment. Ajoutez la première chose.',
        'ask_them' => 'Demandez-leur directement',
        'ask_them_hint' => 'Le destinataire peut suggérer des cadeaux, mais ne voit jamais ce que vous mettez sur la liste.',
    ],
    'santa' => [

        // Public, and it explains itself to a visitor with no account,
        // so it is indexable. It shipped with no title and no description
        // at all until 2026-09-05.
        'seo_title' => 'Ami Secret, tiré au sort en ligne',
        'seo_description' => 'Créez un groupe, tirez les noms en ligne, et vous ne voyez que la personne pour qui vous achetez. Sans chapeau, sans tableur, sans fuite.',
        'title' => 'Ami Secret',
        'subtitle' => 'Un groupe, un tirage, personne ne sait qui a qui.',
        'create' => 'Créer un groupe',
        'group_name' => 'Comment s’appelle ce groupe ?',
        'budget' => 'Budget',
        'budget_hint' => 'Environ ce que chaque personne devrait dépenser.',
        'exchange_date' => 'Quand échangez-vous les cadeaux ?',
        'theme' => 'Thème (facultatif)',
        'invite' => 'Lien d’invitation',
        'invite_hint' => 'Envoyez-le à tout le monde. On rejoint avec un nom et un e-mail.',
        'join' => 'Rejoindre ce groupe',
        'your_name' => 'Votre nom',
        'your_email' => 'Votre e-mail',
        'exclusions' => 'Qui ne devez-vous pas tirer ?',
        'exclusions_hint' => 'Noms ou adresses e-mail, séparés par des virgules. Conjoints, ou la personne tirée l’an dernier.',
        'joined' => 'C’est bon. Nous vous écrirons après le tirage.',
        'members' => 'Qui participe',
        'draw' => 'Lancer le tirage',
        'draw_confirm' => 'Lancer le tirage pour :count personnes ? Chacun recevra un e-mail avec un nom, et cela ne peut pas être annulé.',
        'draw_needs_two' => 'Le tirage nécessite au moins deux personnes.',
        'members_count' => ':count personnes',
        'drawn' => 'Tirage effectué. Tout le monde a reçu un e-mail.',
        'redraw' => 'Retirer au sort pour cette personne',
        'remove_member' => 'Retirer',
        'member_removed' => 'Retiré. La personne qui l’avait sait maintenant pour qui elle achète.',
        'remove_confirm' => 'Retirer :name de ce groupe ?',
        'remove_confirm_drawn' => 'Retirer :name ? Le tirage a eu lieu, donc une autre personne recevra un nouveau nom par e-mail. C’est irréversible.',
        'redraw_confirm' => 'Retirer au sort pour :name ? Deux personnes recevront un nouveau nom par e-mail, et c’est irréversible.',
        'email_changed_subject' => 'Votre Ami Secret a changé : vous avez maintenant :name',
        'email_changed_intro' => 'Quelque chose a changé dans le groupe, ceci remplace donc le nom que nous vous avions envoyé.',
        'redrawn' => 'Nouveau tirage. Les deux personnes ont été prévenues.',
        'you_have' => 'Vous offrez à :name',
        'their_list' => 'Ce que :name a demandé',
        'no_list' => ':name n’a pas fait de liste. Vous êtes seul, mais nous pouvons aider.',
        'build_yours' => 'Faites d’abord votre propre liste',
        'build_yours_hint' => 'Celui qui vous a tiré n’a rien pour se guider tant que vous ne l’avez pas faite.',
        'mark_done' => 'J’ai acheté le mien',
        'marked_done' => 'Parfait. C’est fait pour vous.',
        'done_count' => ':done sur :total ont fini leurs achats',
        'too_few' => 'Il faut au moins deux personnes pour tirer.',
        'impossible' => 'Personne ne peut être associé avec ces exclusions.',
        'already_drawn' => 'Le tirage a déjà eu lieu pour ce groupe.',
        'not_drawn' => 'Le tirage n’a pas encore eu lieu.',
        'organiser_only' => 'Seul l’organisateur peut faire cela.',
        'email_subject' => 'Vous avez tiré :name',
        'email_intro' => 'Le tirage est fait. Vous offrez à :name.',
        'email_budget' => 'Le budget est d’environ :budget.',
        'email_date' => 'Vous échangez le :date.',
        'email_list' => 'Ils ont fait une liste. Jetez-y un œil :',
        'email_no_list' => 'Ils n’ont pas encore fait de liste, vous partez donc à l’aveugle. Nous pouvons aider :',
        'attach_hint' => 'Reliez un groupe à cette liste pour que la personne qui vous a tiré ait de quoi se guider.',
        'attach_list' => 'Utiliser cette liste',
        'list_attached' => 'Ce groupe voit maintenant cette liste.',
        'list_attached_short' => 'Utilisee',
        'invite_text' => 'Rejoignez notre Ami Secret : :title',
        'delete' => 'Supprimer ce groupe',
        'delete_confirm' => 'Supprimer :title ? Tous ceux qui ont rejoint le perdront.',
        'delete_confirm_drawn' => 'Supprimer :title ? Le tirage a eu lieu : tout le monde perd la personne tirée, et personne ne sera prévenu. Dites-le-leur avant.',
        'deleted' => 'Groupe supprimé.',
        'email_hint' => 'Pour que la personne qui vous tire sache qui elle a. Personne d’autre ne le voit.',
    ],
    'quiz' => [
        'title' => 'Les connaissez-vous vraiment ?',
        'intro' => "Quatre objets. L'un d'eux est vraiment sur la liste de :name. Trouvez lequel.",
        'create' => 'Créer un quiz à partir de cette liste',
        'created' => 'Quiz prêt. Envoyez-le à qui vous voulez.',
        'too_short' => 'Il faut au moins :count objets sur la liste pour que le quiz vaille le coup.',
        'share_first' => 'Partagez la liste d’abord. Un quiz montre ce qu’elle contient.',
        'round' => 'Manche :current sur :total',
        'submit' => 'Voir votre score',
        'answered_of' => ':answered sur :total répondues',
        'score' => 'Vous avez :score bonnes réponses sur :total',
        'share' => 'Partager votre score',
        'played' => ':count personnes ont joué',
        'average' => 'Score moyen :score',
        'owner_note' => 'C’est votre propre liste, vous ne pouvez donc pas jouer. Ce serait tricher.',
        'missed' => 'Ce que vous avez raté',
        'missed_hint' => 'Chacun de ces objets leur ferait vraiment plaisir.',
        'play_again' => 'Vous avez déjà joué. Une fois par personne, sinon le score ne veut rien dire.',
        'intro_own' => 'Partagez cette liste sous forme de quiz : quatre objets, un seul figure vraiment sur votre liste.',
        'share_text' => 'Me connaissez-vous vraiment ?',
        'own_title' => 'Découvrez si vos amis vous connaissent vraiment !',
        'open' => 'Ouvrir le quiz',
        'intro_anon' => "Quatre objets. L'un d'eux figure vraiment sur leur liste. Trouvez lequel.",
        'badge' => 'Quiz',
    ],
    'preview' => [
        'badge' => 'Aperçu',
        'note' => "Ceci n'est pas publié. Personne d'autre ne le voit, et les moteurs de recherche sont priés de l'ignorer.",
    ],

    'gift_cove' => [
        'seo_title' => "Listes d'envies, listes cadeaux et Ami Secret",
        'seo_description' => 'Listes d\'envies, liste pour un proche, achat à plusieurs, Ami Secret, alertes de prix et idées cadeaux. Personne ne voit qui achète quoi.',
        'title' => 'La Cove Cadeau',
        'rail_hint' => 'Tout ce qu’il faut pour acheter à quelqu’un d’autre, au même endroit.',
        'rail_cta' => 'Ouvrir la Cove Cadeau',
        'intro' => 'Tout pour offrir aux autres, et pour dire ce qui vous ferait plaisir.',
        'tools' => 'Ce que vous pouvez faire ici',
        'band_together' => 'À plusieurs',
        'band_find' => 'Trouver un cadeau',
        'band_inspire' => 'S\'inspirer',
        'search_title' => 'Chercher et comparer',
        'search_body' => 'Trouvez un produit chez les boutiques de votre pays et voyez qui le vend le moins cher. Sur votre téléphone, scannez le code-barres.',
        'ask_title' => 'Demander aux autres',
        'ask_body' => 'Décrivez pour qui vous cherchez et laissez la communauté proposer quelque chose. Chaque réponse vient avec de vrais produits et un prix.',
        'alerts_title' => 'Notifications',
        'alerts_body' => 'Quelqu\'un partage une liste avec vous, propose quelque chose, une personne de votre liste d\'amis va fêter son anniversaire, un prix baisse : vous l\'apprenez ici et par mail.',
        'friends_title' => 'Amis',
        'friends_body' => 'Les personnes avec qui vous partagez des listes, avec leur anniversaire, pour qu\'un rappel arrive deux semaines avant le jour.',
        'daily_title' => 'La Cove du jour',
        'daily_body' => 'Une petite sélection choisie à la main, nouvelle chaque matin. Pour quand vous ne cherchez rien de précis.',
        'guides_title' => 'Guides d\'achat',
        'guides_body' => 'Ce qui compte au moment de choisir, par sujet, avec les produits qui conviennent. À lire avant d\'acheter, pas après.',
        'ideas_title' => 'Idées cadeaux',
        'ideas_body' => 'Des idées par personne, occasion et budget, chacune prête à être enregistrée sur une liste.',
        'surprise_title' => 'Cove Surprise',
        'surprise_body' => 'Quelque chose de rare plutôt que de populaire, pour la personne qui a déjà tout.',
        'items_count' => ':count articles enregistrés',
        'open_list' => 'Ouvrir ma liste',
        'start_list' => 'Commencer ma liste',
        'my_wishlists' => 'Mes listes de souhaits',
        'another_list' => 'Une autre liste de souhaits',

        'manual' => 'Comment chaque outil fonctionne',
        'manual_link' => 'Comment chaque outil fonctionne',
        'manual_intro' => 'Neuf outils, et les étapes de chaque outil. Chaque bouton cité ci-dessous se trouve sur la page où il vous emmène.',
        'manual_back' => 'Retour à la Cove Cadeau',

        'wishlist_title' => 'Partagez votre propre liste d\'envies',
        'wishlist_body' => 'Enregistrez ce qui vous plairait et partagez le lien. Les personnes qui l\'ouvrent réservent ce qu\'elles prennent, pour que rien ne soit acheté deux fois. Vous n\'en voyez jamais rien.',
        'wishlist_step1' => 'Trouvez quelque chose qui vous plaît et appuyez sur le marque-page. Le sélecteur demande quelle liste : choisissez la vôtre.',
        'wishlist_step2' => 'Ouvrez la liste et appuyez sur Partager. Le lien s’active et s’affiche, prêt à envoyer à qui vous demande ce qui vous ferait plaisir.',
        'wishlist_step3' => 'Les autres ouvrent le lien et indiquent ce qu’ils offrent. On ne vous montre jamais que quelque chose a été pris.',

        'giftlist_title' => "Une liste pour quelqu'un d'autre",
        'giftlist_body' => 'Rassemblez des idées pour une personne. Gardez-la pour vous, ou partagez-la : vous construisez alors la liste ensemble et chaque personne réserve ce qu\'elle achète, pour que rien ne soit acheté deux fois.',
        'giftlist_step1' => 'Appuyez sur Nouvelle liste, choisissez « Pour quelqu’un d’autre » et nommez la personne. Cette carte ouvre le formulaire déjà réglé ainsi.',
        'giftlist_step2' => 'Ajoutez-y ce que vous trouvez, comme sur n’importe quelle autre liste.',
        'giftlist_step3' => 'Gardez-la pour vous, ou appuyez sur Partager : les autres la voient alors et peuvent indiquer ce qu’ils offrent, pour éviter les doublons.',

        'collab_title' => 'Acheter à plusieurs',
        'collab_body' => "Invitez d'autres personnes sur une liste pour choisir ensemble, ou participez à un cadeau plus important que l'un de vous achètera.",
        'collab_step1' => 'Appuyez sur Nouvelle liste, choisissez « À plusieurs, pour quelqu’un » et nommez la personne concernée.',
        'collab_step2' => 'Appuyez sur Partager et envoyez le lien à chaque co-offrant. Toute personne qui l’a peut regarder et réserver ; vous décidez si elle peut aussi ajouter des articles.',
        'collab_step3' => 'Choisissez ensemble, et indiquez ce que vous offrez pour éviter les doublons. Sous Partager, vous décidez aussi si les noms sont visibles.',

        'handover_title' => 'Transmettre une liste',
        'handover_body' => 'Vous avez commencé une liste pour quelqu\'un avant son arrivée ? Donnez-la-lui une fois la personne inscrite : elle devient sa propre liste.',
        'handover_step1' => 'Ouvrez la liste et envoyez-leur le lien « Demandez-leur directement », pour qu’il existe un compte à qui la transmettre.',
        'handover_step2' => 'Une fois qu’ils s’en sont servis, ouvrez Partager, appuyez sur Transmettre et saisissez l’adresse e-mail de leur inscription.',
        'handover_step3' => 'Confirmez : la liste est à eux, ils peuvent la partager et les autres peuvent y réserver.',

        'santa_title' => 'Ami Secret',
        'santa_body' => 'Un groupe, un tirage, personne ne sait qui a qui. Chacun peut rattacher sa liste pour que la personne qui le tire ne devine pas.',
        'santa_step1' => 'Appuyez sur Créer un groupe et donnez-lui un nom, un budget indicatif et la date de l’échange.',
        'santa_step2' => 'Envoyez le lien d’invitation à tout le monde. On rejoint avec un nom et un e-mail, sans compte, et chaque personne peut dire qui elle ne doit pas tirer.',
        'santa_step3' => 'Quand tout le monde est là, appuyez sur Lancer le tirage. Chacun reçoit un e-mail avec un seul nom : le sien.',

        'registry_title' => 'Une liste de cadeaux',
        'registry_body' => 'Une liste avec une occasion et une date : mariage, naissance, nouveau logement. Ajoutez une adresse : seuls ceux qui ont réservé la voient.',
        'registry_step1' => 'Ouvrez une de vos listes d’envies et appuyez sur Réglages.',
        'registry_step2' => 'Choisissez l’occasion et la date, et ajoutez une adresse de livraison si l’on doit vous envoyer les choses.',
        'registry_step3' => 'Partagez-la comme n’importe quelle liste. Elle se comporte pareil : on réserve, et on ne vous dit jamais quoi.',

        'quiz_title' => 'Vous connaissent-ils vraiment ?',
        'quiz_body' => 'Transformez votre liste en quiz : quatre objets, un seul y figure. On partage le score, pas les réponses.',
        'quiz_step1' => 'Ouvrez votre liste et appuyez sur Partager.',
        'quiz_step2' => 'Appuyez sur Quiz, puis sur « Créer un quiz à partir de cette liste ».',
        'quiz_step3' => 'Envoyez le lien. Cinq manches de quatre produits, un essai par personne.',

        'suggestions_title' => 'Suggestions',
        'suggestions_body' => "Ceux qui vous connaissent peuvent proposer des idées pour votre liste. Rien n'y apparaît tant que vous n'avez pas accepté.",
        'suggestions_step1' => 'Partagez votre liste de souhaits. Une suggestion ne peut venir que de quelqu’un qui a le lien.',
        'suggestions_step2' => 'Quand il en arrive une, elle attend en haut de la liste, avec le nom de la personne qui l’a envoyée.',
        'suggestions_step3' => 'Appuyez sur « Ajouter » et elle rejoint la liste, ou sur « Non merci » et elle disparaît. Rien n’y figure avant votre décision.',

        'whisperer_title' => 'Gift Whisperer',
        'whisperer_body' => 'Décrivez une personne et recevez quatre idées, chacune avec sa raison. Pour quand vous savez pour qui, mais pas quoi.',
        'whisperer_step1' => 'Répondez à six courtes questions sur la personne : qui elle est, ce qu’elle aime, ce que vous voulez dépenser, ce qu’il faut éviter.',
        'whisperer_step2' => 'Quatre idées reviennent, chacune avec sa raison. Demandez autre chose et ce que vous avez écarté ne revient jamais.',
        'whisperer_step3' => 'Enregistrez les bonnes directement sur une liste pour cette personne.',
        'band_own' => 'Votre propre liste',
        'band_someone' => 'Une liste pour quelqu\'un',
        'tools_intro' => 'Chaque carte dit ce que c\'est et où cela commence.',
        'wishlist_cta' => 'Ouvrir ma liste d\'envies',
        'registry_cta' => 'Ajouter une occasion',
        'suggestions_cta' => 'Voir les suggestions',
        'giftlist_cta' => 'Commencer une liste pour quelqu\'un',
        'split_title' => 'Acheter séparément pour une personne',
        'split_body' => 'Partagez une liste sur quelqu\'un avec les autres personnes qui offrent. Qui achète quelque chose le réserve, et personne n\'achète deux fois la même chose.',
        'split_cta' => 'Commencer à se coordonner',
        'handover_cta' => 'Transmettre une liste',
        'build_title' => 'Construire une liste à plusieurs',
        'build_body' => 'Activez « tout le monde peut ajouter » et toute personne avec le lien y met des choses. Ajouter seulement : personne d\'autre que vous ne retire quoi que ce soit.',
        'build_cta' => 'Ouvrir une liste à partager',
        'collab_cta' => 'Lancer un cadeau commun',
        'board_title' => 'Discuter d\'une liste',
        'board_body' => 'Chaque liste partagée a un tableau à côté, réservé aux personnes qui offrent. La personne à qui la liste est destinée ne le voit jamais.',
        'board_cta' => 'Ouvrir une liste partagée',
        'santa_cta' => 'Créer un groupe',
        'quiz_cta' => 'Créer un quiz',
        'friends_cta' => 'Voir mes amis',
        'whisperer_cta' => 'Décrire quelqu\'un',
        'search_cta' => 'Chercher',
        'ask_cta' => 'Poser la question',
        'alerts_cta' => 'Voir mes notifications',
        'daily_cta' => 'Voir aujourd\'hui',
        'guides_cta' => 'Lire les guides',
        'ideas_cta' => 'Parcourir les idées',
        'surprise_cta' => 'Surprenez-moi',
    ],

    'wizard' => [
        'title' => 'Créez une liste en trois étapes',
        'step_of' => 'Étape :step sur :total',
        'step_kind' => 'Pour qui',
        'step_details' => 'Nom et occasion',
        'step_sharing' => 'Partage',
        'kind_hint' => 'Cela décide ce que la liste peut faire. Tout le reste se change plus tard ; pas cela.',
        'title_for' => 'Pour :name',
        'kind_mine_body' => 'Ce qui vous ferait plaisir. Gardez-la pour vous, ou partagez-la : les gens réservent alors ce qu\'ils prennent, pour que rien ne soit acheté deux fois. Vous ne voyez jamais quoi, ni qui.',
        'kind_for_someone_body' => 'Une liste au sujet de quelqu\'un. Gardez-la pour vous comme recherche, ou partagez-la : vous la construisez alors ensemble et répartissez les achats, pour que personne n\'achète deux fois la même chose.',
        'kind_group_body' => 'Plusieurs personnes pour un seul cadeau. Tout le monde vote, participe, et l\'une d\'entre vous l\'achète.',
        'title_placeholder_mine' => 'Mon anniversaire, Ce qui me plairait…',
        'title_placeholder_for_someone' => 'Idées pour papa, Anna a 30 ans…',
        'title_placeholder_group' => 'Un cadeau pour Sam de notre part à tous…',
        'person' => 'Pour qui est-ce ?',
        'person_hint' => 'Choisissez un de vos amis pour lier la liste à son compte, ou tapez un nom pour quelqu\'un qui n\'est pas ici.',
        'occasion' => 'Une occasion ? (facultatif)',
        'occasion_hint_mine' => 'Une liste d\'envies avec une date est une liste de cadeaux : les gens voient pour quoi et quand. Vous pourrez ensuite ajouter une adresse de livraison sur la liste, visible seulement de qui réserve quelque chose. Nous vous rappelons deux semaines avant.',
        'occasion_hint_other' => 'Dites pour quoi et quand, et nous vous rappelons deux semaines avant, cinq jours avant et le jour même.',
        'date_label' => 'Date',
        'date_known' => 'Cela tombe le :date.',
        'date_other' => 'Une autre date',
        'date_from_birthday' => 'Nous mettons cet anniversaire comme date sur la liste.',
        'date_needs_birthday' => 'Indiquez leur anniversaire ci-dessus et la date suit.',
        'date_needs_mine' => 'Nous ne connaissons pas votre anniversaire, choisissez donc la date ici. Vous pouvez l\'indiquer sur la page Amis.',
        'sharing' => 'Qui peut la voir ?',
        'sharing_hint_mine' => 'Privée veut dire vous et personne d\'autre. Partagée veut dire que toute personne avec le lien peut l\'ouvrir et réserver ce qu\'elle prend, pour que rien ne soit acheté deux fois.',
        'sharing_hint_for_someone' => 'Privée veut dire vous et personne d\'autre : vos propres notes sur cette personne. Partagez-la et les autres réservent, ajoutent des idées et discutent sur le tableau.',
        'sharing_hint_group' => 'Un cadeau commun a besoin des autres : partagez-la et envoyez-leur le lien. Ils votent, participent et discutent sur le tableau.',
        'visibility_private' => 'Privée (ou partager plus tard)',
        'visibility_link' => 'Partager par un lien',
        'visibility_private_mine' => 'Personne d\'autre que vous ne la voit. Vous pouvez la partager à tout moment.',
        'visibility_link_mine' => 'Toute personne avec le lien la voit et réserve ce qu\'elle prend, pour que rien ne soit acheté deux fois. Rien de cela ne vous parvient jamais.',
        'visibility_private_for_someone' => 'Personne d\'autre que vous ne la voit. Pratique pour rassembler des idées discrètement.',
        'visibility_link_for_someone' => 'Les autres personnes la voient, réservent ce qu\'elles prennent, et la personne concernée n\'en sait rien.',
        'visibility_private_group' => 'Vous et personne d\'autre, pour l\'instant. Vous pourrez la partager dès qu\'il y a quelque chose dessus.',
        'visibility_link_group' => 'Toute personne avec le lien vote, participe et discute. La personne concernée ne la voit jamais.',
        'can_add_hint' => 'Construisez la liste ensemble : toute personne avec le lien peut y mettre des choses. Personne d\'autre que vous ne peut en retirer.',
        'friends_hint' => 'Ils reçoivent un mail avec le lien, et la liste apparaît sur leur page Amis.',
        'friends_none' => 'Dès que quelqu\'un ouvre un de vos liens, vous devenez amis et vous pouvez partager des listes avec cette personne par son nom.',
        'rule' => 'Une règle traverse tout : la personne à qui une liste est destinée ne sait jamais ce qui a été réservé. Ni qui, ni combien, ni même qu\'il y a quelque chose.',
        'back' => 'Retour',
        'next' => 'Suivant',
        'sign_in_hint' => 'Connectez-vous pour garder cette liste. Vos réponses sont conservées pendant ce temps.',
        'sign_in_and_create' => 'Se connecter et créer la liste',
    ],

    'reminders' => [
        'birthday_title' => 'L\'anniversaire de :name approche',
        'birthday_today_title' => 'C\'est l\'anniversaire de :name aujourd\'hui',
        'birthday_today' => 'C\'est le jour. Si quelque chose est en route, c\'est aussi le moment d\'envoyer un mot.',
        'exchange_title' => ':title approche',
        'list_title' => ':occasion de :name approche',
        'list_title_mine' => 'Votre :occasion approche',
        'lead' => 'Encore :days jours. De quoi trouver quelque chose pour :name.',
        'list_lead_mine' => 'Encore :days jours. Le bon moment pour vérifier que votre liste dit ce que vous voulez.',
        'mail_button' => 'Voir',
        'mail_why' => 'Vous recevez ceci parce que vous avez enregistré cette date. Vous pouvez désactiver les rappels dans votre compte.',
    ],

    'alerts' => [
        'watch_restock' => 'Prévenez-moi de son retour',
        'watching_price' => 'Nous surveillons le prix',
        'watching_restock' => 'Nous attendons son retour en stock',
        'stop' => 'Ne plus suivre',
        'created' => 'Nous vous préviendrons.',
        'removed' => 'Suivi arrêté.',
        'not_available' => 'Nous ne pouvons pas suivre celui-ci.',
        'mail_subject_drop' => ':title est moins cher',
        'mail_subject_restock' => ':title est de retour en stock',
        'mail_body_drop' => 'Maintenant :price, au lieu de :was.',
        'mail_body_restock' => 'Il est de retour en stock.',
        'mail_button' => 'Voir les offres',
        'mail_why' => 'Vous nous avez demandé de surveiller ce produit. Vous pouvez arrêter depuis sa page.',
    ],

    'list_watch' => [
        'mail_subject' => 'Changements de prix sur votre liste',
        'mail_heading' => 'Ce qui a changé sur votre liste',
        'mail_intro' => 'Vous nous avez demandé de suivre les prix de votre liste. Voici ce qui a changé depuis notre dernier passage.',
        'col_product' => 'Produit',
        'col_was' => 'Avant',
        'col_now' => 'Maintenant',
        'col_change' => 'Écart',
        'back_heading' => 'De nouveau disponible',
        'mail_button' => 'Ouvrir votre liste',
        'mail_button_lists' => 'Ouvrir vos listes',
        'mail_why' => 'Vous avez activé le suivi des prix pour cette liste. Vous pouvez le désactiver dans les options de la liste.',
    ],

    'notifications' => [
        'list_item_added_item' => 'Ajouté à « :list » : « :item »',
        'list_suggestion_item' => 'Proposition pour « :list » : « :item »',
        'list_shared' => ':name a partagé « :list » avec vous',
        'list_suggestion' => 'Nouvelle proposition pour « :list »',
        'list_item_added' => 'Quelqu\'un a ajouté quelque chose à « :list »',
        'list_message' => 'Nouveau message sur « :list »',
        'list_pledge' => 'Quelqu\'un a participé pour « :list »',
        'list_claimed' => 'Quelque chose est réservé sur « :list »',
        'list_claimed_item' => 'Réservé sur « :list » : « :item »',
        'title' => 'Notifications',
        'recent' => 'Récemment',
        'empty' => 'Rien pour l’instant. Nous vous préviendrons dès qu’un suivi bouge.',
        'dropped_to' => 'Maintenant :price, contre :was',
        'list_price_digest' => 'Changements de prix sur cette liste : :count',
        'search_match_title' => 'Nouveautés pour « :term »',
        'search_match' => ':count nouveaux produits correspondent à votre recherche',
        'back_in_stock' => 'De nouveau en stock',
        'watching' => 'Ce que vous suivez',
        'watching_empty' => 'Vous ne suivez encore rien.',
        'await_restock' => 'En attente de stock',
        'until' => 'Sous :price',
        'any_drop' => 'Toute baisse',
        'now' => 'actuellement :price',
    ],

    'gift' => [
        'title' => 'Trouveur de cadeaux',
        'subtitle' => 'Parlez-nous d’elle ou de lui. Nous trouvons quatre cadeaux qui tiennent la route.',
        'seo_description' => 'Décrivez la personne à qui vous offrez et recevez quatre idées de cadeaux, chacune avec sa raison et où l\'acheter.',

        'step_who' => 'C’est pour qui ?',
        'step_interests' => 'Qu’est-ce qui lui plaît ?',
        'step_vibe' => 'Quel effet doit-il faire ?',
        'step_budget' => 'Quel budget ?',
        'step_avoid' => 'Quelque chose à éviter ?',
        'step_values' => 'Qu’est-ce qui compte pour vous ?',

        'interests' => [
            'cooking' => 'La cuisine', 'coffee' => 'Le café', 'photography' => 'La photo',
            'music' => 'La musique', 'gaming' => 'Les jeux vidéo', 'reading' => 'La lecture',
            'fitness' => 'Le sport', 'outdoors' => 'Le plein air', 'travel' => 'Les voyages',
            'gardening' => 'Le jardinage', 'diy' => 'Le bricolage', 'beauty' => 'Les soins',
            'fashion' => 'La mode', 'tech' => 'La tech', 'home' => 'Son intérieur',
            'craft' => 'Créer de ses mains', 'film' => 'Films et séries', 'pets' => 'Son animal',
            'wellness' => 'Se détendre', 'kids' => 'Les enfants',
        ],

        'vibes' => [
            'practical' => 'Utile',
            'playful' => 'Amusant',
            'beautiful' => 'Beau',
        ],

        'values' => [
            'sustainable' => 'Durable',
            'local' => 'Fabriqué près d’ici',
            'handmade' => 'Fait main',
        ],

        'find' => 'Trouver des cadeaux',
        'again' => 'Réessayer',
        'swap' => 'Autre chose',
        'start_over' => 'Recommencer',
        'results_title' => 'Quatre idées',
        'no_results' => 'Rien ne correspondait. Essayez un budget plus large ou un autre centre d’intérêt.',
        'budget_any' => 'Sans limite',
        'budget_up_to' => 'Jusqu’à',
        'avoid_placeholder' => 'p. ex. alcool, laine',
        'avoid_hint' => 'Nous écarterons tout ce qui correspond à ces mots.',
        'avoid_add' => 'Ajouter',
        'recipient_use' => 'Utiliser ce que nous savons de :name',
        'recipient_none' => 'Quelqu’un de nouveau',
        'step' => 'Étape :current sur :total',
        'back' => 'Retour',
        'next' => 'Suivant',

        // The card shows one reason, not a breakdown: three reasons read as a
        // machine justifying itself.
        'reasons' => [
            'interest_fit' => 'Correspond à :match',
            'budget_fit' => 'Bien placé dans votre budget',
            'surprise' => 'Pas le choix évident',
            'vibe' => 'Correspond à l’effet recherché',
            'values' => 'Correspond à ce qui compte pour vous',
        ],
    ],

    'ask' => [
        'seo_title' => 'Demandez aux autres',
        'seo_description' => 'À court d’idées ? Décrivez la personne et laissez les autres vous suggérer quelque chose. Chaque réponse arrive avec de vrais produits et un prix.',
        'seo_question' => 'Idées cadeaux pour : :title. De vraies suggestions d’autres personnes, avec les produits et les prix qui vont avec.',

        'title' => 'Demandez aux autres',
        'intro' => 'Besoin d’inspiration ? Décrivez pour qui vous cherchez et laissez la communauté GiftCoves proposer quelque chose. Les réponses arrivent avec de vrais produits, pas seulement des conseils.',
        'nav_hint' => 'Décrivez pour qui c’est et laissez les autres proposer quelque chose.',

        'all' => 'Toutes les questions',
        'more_about_them' => 'Dites-en un peu plus sur elle ou lui (facultatif)',
        'more_hint' => 'Rien de tout cela n’est obligatoire. Cela donne simplement de meilleures réponses.',
        'occasion_label' => 'Occasion',
        'occasion_placeholder' => 'Anniversaire, départ à la retraite…',
        'age_label' => 'À peu près quel âge',
        'age_placeholder' => 'La trentaine',
        'ask_cta' => 'Poser une question',
        'ask_heading' => 'Sur quoi bloquez-vous ?',
        'question_label' => 'Votre question',
        'question_placeholder' => 'Ma sœur a 30 ans et possède déjà à peu près tout',
        'detail_label' => 'Tout ce qui peut aider',
        'detail_placeholder' => 'Ce qu’elle aime, ce que vous avez déjà écarté, à quel point vous la connaissez.',
        'budget_label' => 'Jusqu’à',
        'budget_hint' => 'Facultatif. Les réponses sont souvent meilleures avec un montant à viser.',
        'submit' => 'Demander',
        'cancel' => 'Annuler',

        'sign_in_to_ask' => 'Connectez-vous pour poser une question ou y répondre.',

        'submitted' => 'Merci — nous lisons chaque question avant sa mise en ligne. La vôtre paraîtra sous peu.',
        'answer_submitted' => 'Merci — nous lisons chaque réponse avant sa mise en ligne.',

        'mine_heading' => 'Vos questions',
        'pending_notice' => 'Nous sommes en train de la lire. Elle n’est pas encore sur le tableau.',
        'rejected_notice' => 'Nous n’avons pas pu mettre celle-ci sur le tableau.',

        'empty' => 'Personne n’a encore rien demandé.',
        'empty_hint' => 'Soyez le premier. Quelqu’un sait généralement.',

        'answers' => ':count réponses',
        'one_answer' => '1 réponse',
        'no_answers' => 'Pas encore de réponse',
        'asked_by' => 'Demandé par :name',
        'budget_up_to' => 'Jusqu’à :amount',

        'answer_heading' => 'Répondre',
        'answer_placeholder' => 'Que lui offririez-vous, et pourquoi ?',
        'answer_submit' => 'Publier ma réponse',
        'answers_heading' => 'Réponses',
        'be_first' => 'Pas encore de réponse. La vôtre serait la première.',

        'picks_heading' => 'Proposez quelque chose de précis',
        'picks_hint' => 'Ajoutez jusqu’à :count articles des boutiques que nous suivons. Une réponse avec un produit en vaut dix sans.',
        'picks_search' => 'Chercher un produit',
        'picks_add' => 'Ajouter',
        'picks_added' => 'Ajouté',
        'picks_full' => 'C’est le maximum pour une seule réponse.',
        'picks_none_found' => 'Rien ne correspond.',

        'status' => [
            'pending' => 'En cours de lecture',
            'published' => 'Sur le tableau',
            'rejected' => 'Non publié',
        ],
    ],

    'invitations' => [
        'mail_subject' => ':name aimerait votre aide pour choisir un cadeau',
        'mail_heading' => 'Aidez à choisir un cadeau',
        'mail_intro' => ':name vous demande de vous joindre au choix, sur une liste appelée ":list".',
        'mail_intro_for' => ':name vous demande de vous joindre au choix d’un cadeau pour :person.',
        'mail_what' => 'Ouvrez le lien ci-dessous et connectez-vous avec cette adresse. Vous verrez la liste et pourrez y ajouter des idées.',
        'mail_button' => 'Voir la liste',
        'mail_expiry' => 'Le lien est valable deux semaines.',
        'sign_in_first' => 'Connectez-vous avec l’adresse à laquelle l’invitation a été envoyée, et la liste vous attendra.',
    ],

    // Voir l'explication de ces clés dans lang/en/site.php.
    'feedback' => [
        'seo_title' => 'Dites-nous ce qui pourrait être mieux',
        'seo_description' => 'Dites ce qui pourrait être mieux — un prix qui n’est plus à jour, un lien qui ne mène nulle part — ou ce qui vous plaît. Aucun compte nécessaire.',
        'title' => 'Dites-nous ce qui pourrait être mieux, ou faites-nous un compliment',
        'message_label' => 'Votre message',
        'message_placeholder' => 'Qu’est-ce qui ne va pas, qu’est-ce qui manque, que feriez-vous autrement ? Ou dites-nous simplement ce que vous aimez chez GiftCoves :D',
        'email_label' => 'Votre e-mail (facultatif)',
        'email_placeholder' => 'vous@exemple.be',
        'email_hint' => 'Uniquement pour vous répondre. Rien d’autre n’y sera jamais envoyé.',
        'submit' => 'Envoyer',
        'sending' => 'Envoi…',
        'thanks' => 'Merci — c’est bien arrivé et quelqu’un le lira.',
    ],

    /*
     * What this market searches for, as a page.
     *
     * Replaced the related-search chips under every result set, which were
     * removed for cost on 2026-09-05. Linked from the footer of every page.
     */
    'popular_searches' => [
        'title' => 'Ce que les gens recherchent',
        'empty' => 'Rien à afficher pour l\'instant — ce marché n\'a pas encore été assez consulté pour qu\'une tendance se dégage.',
        'empty_link' => 'Faire une recherche',
        'note' => 'Uniquement les recherches qui ont donné des résultats, et seulement celles effectuées assez souvent pour représenter une tendance plutôt qu\'une seule personne.',
        'seo_title' => 'Ce que les gens recherchent',
        'seo_description' => 'Les recherches les plus fréquentes sur ce site ces trois derniers mois, chacune menant à ses résultats.',
        'popular_heading' => 'Les plus recherchées',
        'trending_heading' => 'En plus forte hausse',
        'trending_intro' => 'Plus recherchées cette semaine que leur propre moyenne récente, et non simplement les plus recherchées.',
        'latest_heading' => 'Recherchées récemment',
        'latest_intro' => 'Des recherches installées qui sont revenues ces derniers jours.',
        'movement_up' => 'En hausse par rapport à la période précédente',
        'movement_down' => 'En baisse par rapport à la période précédente',
        'movement_new' => 'Nouveau sur cette période',
        'movement_new_short' => 'Nouveau',
        'movement_same' => 'Inchangé',
        'period_empty' => 'Rien pour le moment.',
    ],

    'search_help' => [
        'seo_title' => 'Comment chercher : mots, codes-barres, Amazon',
        'seo_description' => "Ce que le champ de recherche comprend — noms de produits, marques, codes-barres et liens Amazon collés — et comment affiner jusqu'à la bonne offre.",
        'title' => 'Rechercher et scanner',
        'intro' => 'Ce que le champ de recherche comprend, comment affiner une liste de résultats, et ce qui se passe quand vous pointez une caméra sur un code-barres.',
        'link' => 'Que peut-on rechercher ici ?',
        /*
         * The footer's own label. `link` is a question — it works beside a
         * search box that has just disappointed somebody, and reads oddly in a
         * row of nouns at the bottom of every page.
         */
        'footer_link' => 'Aide à la recherche',

        'searching_heading' => 'Ce que vous pouvez rechercher',
        'searching_intro' => 'Un seul champ, quatre types de saisie. Il ressemble à n\'importe quel champ de recherche et il accepte bien davantage.',
        'what_words_term' => 'Des mots',
        'what_words' => 'Noms de produits, marques, catégories et mots tirés de la description du marchand. Un mot présent dans le titre compte plus que le même mot dans une description : les noms les plus proches arrivent donc en tête.',
        'what_typos_term' => 'Les fautes de frappe',
        'what_typos' => '« blutooth casqu » trouve les casques Bluetooth. La comparaison se fait mot à mot, donc une lettre fausse ne vous coûte pas le reste de la requête.',
        'what_accents_term' => 'Les accents',
        'what_accents' => 'Facultatifs dans les deux sens. « creme » trouve « crème » et inversement, dans toutes les langues que nous couvrons.',
        'what_language_term' => 'La langue',
        'what_language' => 'Chaque marché cherche dans sa propre langue, pluriels et terminaisons compris. Chercher dans la langue des boutiques que vous consultez donne les meilleurs résultats.',
        'what_barcode_term' => 'Un code-barres',
        'what_barcode' => 'Tapez ou collez les chiffres sous les barres — de 8 à 14 — et vous arrivez sur le produit lui-même plutôt que sur une liste. C\'est la recherche que fait la caméra.',
        'what_amazon_term' => 'Un lien Amazon',
        'what_amazon' => 'Collez l\'adresse d\'une fiche produit Amazon : nous lisons le nom du produit dans le lien lui-même et le cherchons ici. Nous n\'ouvrons jamais le lien, et une adresse raccourcie amzn.to ne contient rien à lire — collez l\'adresse complète.',

        'narrowing_heading' => 'Affiner une liste de résultats',
        'narrowing_intro' => 'Les filtres sont au-dessus des résultats. Tout ce que vous réglez reste dans l\'adresse : une recherche filtrée est donc un lien que vous pouvez envoyer ou mettre en favori.',
        'narrow_price_term' => 'Prix',
        'narrow_price' => 'Un plancher, un plafond, ou les deux. Les prix sont toujours ceux qui s\'appliquent sur votre marché.',
        'narrow_brand_term' => 'Marque et boutique',
        'narrow_brand' => 'Choisissez-en une ou plusieurs, des deux côtés. Les marques sont rapprochées par identité et non par orthographe : « Audio-Technica » et « Audio Technica » sont une seule marque.',
        'narrow_stock_term' => 'En stock',
        'narrow_stock' => 'Activé par défaut. Désactivez-le pour inclure ce qu\'aucune boutique ne peut expédier aujourd\'hui.',
        'narrow_sort_term' => 'Le tri',
        'narrow_sort' => 'Pertinence, prix croissant ou décroissant, remise la plus forte, ou nouveautés. Il existe aussi un affichage par boutique, qui regroupe les mêmes résultats sous les boutiques qui les vendent.',
        'narrow_terms_term' => 'Les mots au-dessus des résultats',
        'narrow_terms' => 'Des mots relevés sur les produits affichés. Chacun s\'ajoute à ce que vous avez tapé au lieu de le remplacer : la recherche se resserre et ne peut pas aboutir à une impasse.',

        'scanning_heading' => 'Scanner un code-barres',
        'scanning_intro' => 'Pointez votre téléphone sur le code-barres d\'une boîte en magasin et voyez si c\'est moins cher ailleurs, pendant que vous y êtes encore.',
        'scan_where_term' => 'Par où commencer',
        'scan_where' => 'Le bouton caméra dans le champ de recherche, sur la page de recherche. Il existe aussi une page dédiée, si vous préférez en garder un raccourci.',
        'scan_privacy_term' => 'L\'image de la caméra ne quitte pas votre téléphone',
        'scan_privacy' => 'Le code-barres est lu sur l\'appareil lui-même et seuls les chiffres nous sont envoyés. Aucune image n\'est transmise, conservée ni journalisée. La caméra exige une connexion sécurisée et votre autorisation, et reste éteinte tant que vous n\'appuyez pas.',
        'scan_devices_term' => 'Quels téléphones en sont capables',
        'scan_devices' => 'Chrome sur Android lit les codes-barres nativement. Sur iPhone, ainsi que dans Safari et Firefox, un lecteur est téléchargé à l\'ouverture du scanner : le premier scan prend un instant de plus, les suivants non.',
        'scan_misses_term' => 'Ne rien trouver est normal',
        'scan_misses' => 'Seuls les produits identifiés par leur code-barres peuvent être retrouvés, et toutes les boutiques n\'en publient pas. Nous consultons d\'abord notre catalogue, puis nous interrogeons bol directement. Un résultat vide signifie que nous n\'avons pas encore cet article — pas qu\'il n\'existe pas.',
        'scan_misread_term' => 'Les mauvaises lectures',
        'scan_misread' => 'Chaque code-barres porte un chiffre de contrôle, et un code qui ne le vérifie pas est écarté plutôt que recherché : un chiffre faux désigne un autre produit bien réel, pas un presque-résultat. S\'il ne se passe rien, gardez la caméra dessus.',
        'scan_manual_term' => 'Quand la caméra n\'y arrive pas',
        'scan_manual' => 'Les codes-barres courbés, froissés ou sous film plastique sont vraiment difficiles, et l\'éclairage des magasins n\'aide pas. Tapez les chiffres à la place — cela marche toujours.',

        'go_search' => 'Aller à la recherche',
        'go_scan' => 'Ouvrir le scanner',
    ],

    'scan' => [
        'title' => 'Scanner un code-barres',
        'subtitle' => 'Vous êtes en magasin ? Scannez et voyez le prix partout ailleurs.',
        'seo_description' => 'Scannez un code-barres et voyez le prix demandé par chaque boutique qui vend le produit.',
        'start' => 'Ouvrir la caméra',
        'stop' => 'Arrêter',
        'manual_placeholder' => 'Ou saisissez le code-barres',
        'look_up' => 'Rechercher',
        'close' => 'Fermer',
        'shops' => 'dans :count boutiques',
        'preparing' => 'Préparation…',
        'unsupported' => 'Le scanner n’a pas pu démarrer. Saisissez le numéro ci-dessous, il figure sous les barres.',
        'no_camera' => 'Pas de caméra disponible, ou autorisation refusée. Saisissez le numéro ci-dessous.',
        'invalid' => 'Ce n’est pas un code-barres valide. Vérifiez les chiffres sous les barres.',
        'not_found' => 'Nous ne l’avons pas encore.',
        'search_instead' => 'Chercher quand même',
    ],

    'surprise' => [
        'title' => 'Des choses dont vous ignoriez l’existence',
        'subtitle' => 'Rares, en stock, et vendues par presque personne.',
        'seo_description' => 'Des produits inhabituels absents de toute liste des meilleures ventes, notés selon leur rareté et vérifiés pour valoir le coup d’œil.',
        'reroll' => 'Montrez-m’en d’autres',
        'empty' => 'Rien n’a encore été évalué. Revenez après le prochain passage du catalogue.',

        'by_brand' => 'Par :brand',
    ],

    'gift_ideas' => [

        /*
         * A persona's listing title, which its theme_title cannot be.
         *
         * "The one who reads" is a good heading and an unsearchable
         * listing: it holds no word anybody types. The query is "gift for
         * someone who reads" and this page is exactly that answer, so the
         * H1 keeps the editorial title and the listing gets this one.
         * GiftIdeasController falls back to the bare theme_title when the
         * two together would run past what a listing shows.
         */
        'persona_seo_title' => 'Idées cadeaux pour :persona',

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
        'draft_title' => ':interest — idées cadeaux',

        'title' => 'Idées cadeaux, par profil',
        'description' => "Des cadeaux choisis autour d'une personne plutôt que d'une date : l'herboriste, le père qui a déjà tout, l'ami qui lit.",
        'empty' => "Rien ici pour l'instant. Ils sont écrits un par un ; le premier arrive.",
        'finds_title' => 'Quoi lui offrir',
        'find_count' => ':count idées',
    ],

    'daily' => [
        'title' => 'La Cove Quotidienne',
        'seo_title' => ':theme — idées cadeaux',
        'seo_description' => 'Une poignée de choses dont vous ignoriez l’existence, et un guide d’achat bâti sur ce que les gens ont réellement cherché ici.',
        'finds_title' => 'Les trouvailles du jour',
        'guide_title' => 'Le guide du jour',
        'guide_why' => 'Écrit parce que :count recherches ici l’ont demandé.',

        // Days worth building an edition around. A blurb is optional; a missing
        // one renders as no blurb rather than as a dotted key.
        /*
         * Titres seulement, pour l'instant.
         *
         * Observance::blurb() ne se rabat pas sur l'anglais : une phrase
         * anglaise sous un titre français a l'air cassée, une phrase absente
         * n'a l'air de rien. Les accroches viennent de la passe éditoriale IA,
         * ou pas du tout.
         */
        'observances' => [
            // Janvier.
            'new_year' => ['title' => 'Le début de quelque chose'],
            'sleep' => ['title' => 'Journée du sommeil'],
            'three_kings' => ['title' => 'Jour des Rois'],
            'houseplants' => ['title' => 'Journée des plantes d’intérieur'],
            'pet_style' => ['title' => 'Déguisez votre animal'],
            'hats' => ['title' => 'Journée du chapeau'],
            'hot_tea' => ['title' => 'Journée du thé chaud'],
            'popcorn' => ['title' => 'Journée du popcorn'],
            'cheese' => ['title' => 'Journée des amateurs de fromage'],
            'hugs' => ['title' => 'Journée des choses douces'],
            'pie' => ['title' => 'Journée de la tarte'],
            'burns_night' => ['title' => 'Burns Night'],
            'lego_day' => ['title' => 'Journée internationale du LEGO'],
            'puzzles' => ['title' => 'Journée du puzzle'],
            'blue_monday' => ['title' => 'Blue Monday'],

            // Février.
            'pizza' => ['title' => 'La pizza, sérieusement'],
            'science' => ['title' => 'Les femmes et les filles de science'],
            'radio' => ['title' => 'Journée mondiale de la radio'],
            'valentines' => ['title' => 'Saint-Valentin'],
            'kindness' => ['title' => 'Journée des actes de gentillesse'],
            'wine' => ['title' => 'Journée du vin'],
            'love_your_pet' => ['title' => 'Journée de votre animal'],
            'cocktails' => ['title' => 'Journée de la margarita'],
            'pokemon' => ['title' => 'Journée Pokémon'],

            // Mars.
            'wildlife' => ['title' => 'Pour observer la faune'],
            'womens_day' => ['title' => 'Journée internationale des droits des femmes'],
            'mario_day' => ['title' => 'MAR10, la journée Mario'],
            'pi_day' => ['title' => 'Journée de Pi'],
            'happiness' => ['title' => 'Objets réjouissants'],
            'poetry' => ['title' => 'Journée mondiale de la poésie'],
            'water' => ['title' => 'Journée mondiale de l’eau'],
            'tolkien' => ['title' => 'Journée de lecture Tolkien'],
            'pencils' => ['title' => 'Journée du crayon'],
            'backup' => ['title' => 'Journée mondiale de la sauvegarde'],

            // Avril.
            'april_fools' => ['title' => 'Poisson d’avril'],
            'childrens_books' => ['title' => 'Journée du livre pour enfants'],
            'health' => ['title' => 'Discrètement bon pour vous'],
            'pets' => ['title' => 'Pour l’animal qui dirige la maison'],
            'space' => ['title' => 'La nuit de Youri'],
            'earth' => ['title' => 'Les choses qui durent'],
            'books' => ['title' => 'Pour les lecteurs'],
            'kingsday' => ['title' => 'Koningsdag'],
            'record_store_day' => ['title' => 'Disquaire Day'],
            'dance' => ['title' => 'Journée internationale de la danse'],
            'jazz' => ['title' => 'Journée internationale du jazz'],

            // Mai.
            'makers' => ['title' => 'Ce qui fabrique le reste'],
            'star_wars' => ['title' => 'May the Fourth'],
            'eat_what_you_want' => ['title' => 'Mangez ce que vous voulez'],
            'family' => ['title' => 'Journée internationale des familles'],
            'bees' => ['title' => 'Journée mondiale des abeilles'],
            'tea' => ['title' => 'Journée internationale du thé'],
            'geek_pride' => ['title' => 'Geek Pride Day'],
            'mountains' => ['title' => 'Jour de l’Everest'],
            'mothers_day' => ['title' => 'Pour votre mère'],

            // Juin.
            'bicycle' => ['title' => 'Deux roues'],
            'environment' => ['title' => 'Moins de déchets'],
            'oceans' => ['title' => 'Journée mondiale de l’océan'],
            'sushi' => ['title' => 'Journée internationale du sushi'],
            'music' => ['title' => 'Faites du bruit'],
            'skateboarding' => ['title' => 'Go Skateboarding Day'],
            'fathers_day' => ['title' => 'Pour votre père'],

            // Juillet.
            'chocolate' => ['title' => 'Journée mondiale du chocolat'],
            'emoji' => ['title' => 'Journée mondiale de l’emoji'],
            'moon' => ['title' => 'Jour de la Lune'],
            'belgian_national' => ['title' => 'Fête nationale'],
            'friendship' => ['title' => 'Journée internationale de l’amitié'],
            'wizarding' => ['title' => 'Un anniversaire de sorcier'],

            // Août.
            'cats' => ['title' => 'Journée du chat'],
            'book_lovers' => ['title' => 'Journée des amoureux des livres'],
            'lefthanders' => ['title' => 'Journée des gauchers'],
            'photography' => ['title' => 'Pour bien regarder'],
            'dogs' => ['title' => 'Journée internationale du chien'],
            'back_to_school' => ['title' => 'Le dernier jour des vacances'],

            // Septembre.
            'coffee' => ['title' => 'Le terrier du café'],
            'literacy' => ['title' => 'Journée internationale de l’alphabétisation'],
            'programmers' => ['title' => 'Journée des programmeurs'],
            'pirates' => ['title' => 'Parlez comme un pirate'],
            'peace_quiet' => ['title' => 'La paix et le silence'],
            'travel' => ['title' => 'Journée mondiale du tourisme'],

            // Octobre.
            'coffee_intl' => ['title' => 'Journée internationale du café'],
            'animals' => ['title' => 'Journée mondiale des animaux'],
            'teachers' => ['title' => 'Journée mondiale des enseignants'],
            'food' => ['title' => 'Journée mondiale de l’alimentation'],
            'chefs' => ['title' => 'Journée internationale des chefs'],
            'internet' => ['title' => 'Journée mondiale de l’internet'],
            'halloween' => ['title' => 'Halloween'],

            // Novembre.
            'singles_day' => ['title' => 'Singles Day'],
            'world_kindness' => ['title' => 'Journée mondiale de la gentillesse'],
            'mens_health' => ['title' => 'Se raser, sans chichis'],
            'television' => ['title' => 'Journée mondiale de la télévision'],
            'digital_tidy' => ['title' => 'Journée de la sécurité informatique'],
            'black_friday' => ['title' => 'Vraiment moins cher'],

            // Décembre.
            'wildlife_conservation' => ['title' => 'Observer sans déranger'],
            'sinterklaas' => ['title' => 'Pakjesavond'],
            'saint_nicolas' => ['title' => 'Saint-Nicolas'],
            'solstice' => ['title' => 'La plus longue nuit'],
            'christmas_eve' => ['title' => 'Réveillon de Noël'],
            'christmas_day' => ['title' => 'Jour de Noël'],
            'boxing_day' => ['title' => 'Lendemain de Noël'],
            'new_years_eve' => ['title' => 'Réveillon du Nouvel An'],
        ],

        'day_themes' => [
            'desk_reset' => ['title' => 'Remettre le bureau à zéro'],
            'coffee_ritual' => ['title' => 'Le rituel du matin'],
            'tea_corner' => ['title' => 'Le coin thé'],
            'one_good_knife' => ['title' => 'Un bon couteau'],
            'slow_cooking' => ['title' => 'Ce qui prend des heures'],
            'baking' => ['title' => 'Peser, mélanger, attendre'],
            'sound' => ['title' => 'Mieux sonner'],
            'vinyl' => ['title' => 'Les disques et ce qui les lit'],
            'gaming_night' => ['title' => 'L’installation'],
            'board_games' => ['title' => 'Autour d’une table'],
            'reading_nook' => ['title' => 'Un endroit pour lire'],
            'better_sleep' => ['title' => 'Mieux dormir'],
            'bathroom' => ['title' => 'La petite pièce'],
            'skincare' => ['title' => 'Le visage que vous avez'],
            'hair' => ['title' => 'Les cheveux, réglés'],
            'shaving' => ['title' => 'Se raser, sans chichis'],
            'running' => ['title' => 'Sortir courir'],
            'yoga' => ['title' => 'Le travail au sol'],
            'home_gym' => ['title' => 'La salle dans la chambre d’amis'],
            'cycling' => ['title' => 'Sur deux roues'],
            'travel_kit' => ['title' => 'Bien faire sa valise'],
            'small_flying_things' => ['title' => 'Petites choses volantes'],
            'smart_home' => ['title' => 'La maison qui fait des choses'],
            'clean_house' => ['title' => 'Le ménage, mécanisé'],
            'laundry' => ['title' => 'Le problème du linge'],
            'storage' => ['title' => 'Où ranger tout ça'],
            'plants' => ['title' => 'Garder les plantes en vie'],
            'tools' => ['title' => 'Le réparer soi-même'],
            'car_care' => ['title' => 'À l’intérieur de la voiture'],
            'the_dog' => ['title' => 'Pour le chien'],
            'the_cat' => ['title' => 'Pour le chat'],
            'kids_making' => ['title' => 'Faire des dégâts exprès'],
            'bricks' => ['title' => 'Construire'],
            'writing' => ['title' => 'Sur papier'],
            'drawing' => ['title' => 'Dessiner mal, avec plaisir'],
            'sewing' => ['title' => 'Fait plutôt qu’acheté'],
            'photography_kit' => ['title' => 'Ce qu’il y a autour de l’appareil'],
            'the_hallway' => ['title' => 'Les deux premiers mètres'],
            'phone_life' => ['title' => 'Garder le téléphone en vie'],
            'first_flat' => ['title' => 'Le premier appartement'],

            'grilling' => ['title' => 'Cuisiner dehors'],
            'picnic' => ['title' => 'Manger par terre'],
            'beach' => ['title' => 'Sable et sel'],
            'keeping_cool' => ['title' => 'Traverser la chaleur'],
            'camping' => ['title' => 'Dormir dehors'],
            'garden' => ['title' => 'Le jardin, ou le balcon'],
            'hiking' => ['title' => 'Une longue marche'],
            'cosy' => ['title' => 'Rester à l’intérieur'],
            'hot_drinks' => ['title' => 'Quelque chose de chaud'],
            'rain' => ['title' => 'Les mois humides'],
            'indoor_air' => ['title' => 'L’air à l’intérieur'],
            'winter_sports' => ['title' => 'De la neige, un jour'],
            'dark_evenings' => ['title' => 'Nuit à dix-sept heures'],
            'spring_clean' => ['title' => 'Le grand tri annuel'],

            'early_summer' => ['title' => 'Le premier week-end chaud'],
            'pool_side' => ['title' => 'De l’eau dans le jardin'],
            'grilling_season' => ['title' => 'La saison du barbecue'],
            'holiday_packing' => ['title' => 'Avant de partir'],
            'school_run_up' => ['title' => 'Avant la rentrée'],
            'pre_halloween' => ['title' => 'Avant Halloween'],
            'autumn_indoors' => ['title' => 'On rentre'],
            'sinterklaas_run_up' => ['title' => 'Avant la Saint-Nicolas'],
            'gift_season' => ['title' => 'La saison des cadeaux'],
            'new_year_reset' => ['title' => 'La remise à zéro de janvier'],
        ],

        // The no-AI theme rotation, indexed by day of year modulo 7. Dated
        // rather than random so a rebuild of the same day is identical.
        'themes' => [
            'Ce que personne d’autre ne vend',
            'Discrètement excellent',
            'Résout un problème que vous avez',
            'Étrange mais utile',
            'Mérite sa place sur l’étagère',
            'Trouvé au fond du catalogue',
            'Vous ignoriez en avoir besoin',
        ],
        'deals_title' => 'Les plus fortes baisses',
        'deals_hint' => 'Mesuré sur notre propre médiane à 30 jours, pas sur un prix barré.',
    ],

    'guides' => [
        /*
         * The heading is the section's name; the `seo_*` pair is deliberately
         * not.
         *
         * "Acheter malin" is what this section is called on this site and is what
         * the header, the footer and the front page say — but nobody searches
         * for it, because it is our phrase. The <title> and the meta
         * description still lead with "guides d'achat", which is what a person
         * actually types. Our own name in an H1 and the reader's vocabulary in
         * the <title> is the normal split, not an inconsistency.
         *
         * It replaced "Coves Inspiration" on 2026-09-01, which named a mood
         * where this shelf gives advice — see navigation.md.
         */
        'seo_title' => 'Guides d’achat avec prix en direct',
        'title' => 'Acheter malin',
        'subtitle' => "Conseils d'achat et guides, écrits à partir de ce que les gens cherchent ici plutôt que d'un outil de mots-clés.",
        'seo_description' => 'Des guides d’achat bâtis sur une demande réelle, avec les prix en direct de toutes les boutiques qui vendent chaque produit.',
        'empty' => "Pas encore de conseils d'achat. Ils s'écrivent dès qu'un sujet accumule assez de demande.",
        'how_to_choose' => 'Comment choisir',
        'faq' => 'Questions',
        'updated' => 'Vérifié le :date',
        'why' => 'écrit parce que :count recherches ici l’ont demandé',
        'shops' => ':count boutiques',
        'unavailable' => 'En rupture',
        'slug_prefix' => 'meilleur',
        'template_title' => 'Les meilleurs :topic',
        'template_intro' => ':count options pour :topic, avec le prix de chaque boutique côte à côte.',
        // A season published as a series. See docs/features/seasonal-series.md.
        'series_title' => ':topic, partie :part',
        'series_slug_part' => 'partie',
        'series_heading' => 'Dans cette série',
    ],

    'og' => [
        'daily' => 'La Cove Quotidienne',
        'default_title' => 'Découvrez des produits et des marques',
        'default_footnote' => 'giftcoves.com',
        'product' => 'Produit',
        'guide' => 'Guide d\'achat',
        'guide_footnote' => ':count produits, choisis et commentés',
        'brand' => 'Marque',
        'brand_footnote' => ':products produits chez :shops boutiques',
        'shops' => '{1} 1 boutique|[2,*] :count boutiques',
        'from_price' => 'à partir de :price',
    ],

    /*
     * The product rails under a piece about a shop or a brand.
     *
     * Each caption is the claim its rail makes, and they are different
     * claims: a discount is measured against our own median, a chart is
     * somebody else's, and a wishlist count is our own visitors'. The
     * blurbs say which, because a shelf that does not say where its
     * order came from is a shelf a reader cannot weigh.
     */
    'entity_rails' => [
        'discounts' => [
            'title' => 'En baisse en ce moment',
            'blurb' => 'Mesuré par rapport au prix médian des trente derniers jours, pas à un prix barré.',
        ],
        'popular' => [
            'title' => 'Souvent vendu',
            'blurb' => "D'après les classements des boutiques que nous comparons.",
        ],
        'wishlisted' => [
            'title' => 'Souvent dans les listes',
            'blurb' => 'Ce que les visiteurs ajoutent à une liste. Affiché seulement si plusieurs listes concordent.',
        ],
        'sidebar_heading' => 'Produits de cette marque ou de cette boutique',
        'see_all' => 'Voir toutes les offres :entity',
    ],

    /*
     * La page derrière chaque adresse qui n'existe pas.
     *
     * Écrite comme une indication de route, pas comme des excuses : celui qui
     * arrive ici cherchait quelque chose de précis et ne l'a pas trouvé, et
     * l'étape suivante lui sert plus qu'une explication de code d'erreur.
     */
    'not_found' => [
        'seo_title' => 'Page introuvable',
        'seo_description' => "Cette adresse n'existe pas. Cherchez ce que vous vouliez, ou continuez ailleurs sur GiftCoves.",
        'title' => "Cette page n'est pas ici",
        'intro' => "L'adresse a peut-être changé, ou la page n'existe plus. Cherchez ce que vous vouliez, ou repartez d'ailleurs.",
        'search_placeholder' => 'Cherchez un cadeau ou scannez un code-barres',
        'search_button' => 'Rechercher',
        'elsewhere' => 'Ou commencez ailleurs',
        'gift' => 'Trouver un cadeau',
        'gift_blurb' => 'Quelques questions, et des idées qui correspondent à la personne.',
        'daily' => 'Cove Quotidienne',
        'daily_blurb' => 'Une nouvelle sélection chaque jour.',
        'guides' => "Guides d'achat",
        'guides_blurb' => "Ce qu'il faut regarder, et ce qui vaut son prix.",
        'surprise' => 'Cove Surprise',
        'surprise_blurb' => "Quelque chose dont vous ignoriez l'existence.",
        'brands' => 'Marques',
        'brands_blurb' => 'Toutes les marques que nous suivons, et leurs points forts.',
        'lists' => 'Listes de souhaits',
        'lists_blurb' => 'Gardez ce que vous voulez et partagez-le avec ceux qui achètent.',
        'home' => 'Accueil',
        'popular' => 'Recherches populaires',
        'shops' => 'Boutiques',
    ],

    /*
     * Comment fonctionnent les listes. Dans l'ordre où on les rencontre —
     * chercher, enregistrer, consulter — et non dans l'ordre où elles sont
     * construites : personne ne crée une liste vide avant d'aller chercher.
     */
    'lists_help' => [
        // Only the link stays here; the pages' own words are in help_lists.php,
        // read on the server, so they do not ride along with every page.
        'link' => 'Comment fonctionnent les listes ?',
    ],

    /*
     * Comment le site fonctionne, et où dire qu'il ne fonctionne pas. Les
     * explications d'abord : qui arrive ici est bloqué plutôt que rapporteur.
     */
    'help' => [
        'seo_title' => 'Aide',
        'seo_description' => 'Comment chercher, comment fonctionnent les listes, et où nous dire que quelque chose ne va pas.',
        'title' => 'Aide',
        'intro' => 'Comment obtenir de ce site ce que vous cherchez, et où le dire quand ça ne marche pas.',
        'guides_heading' => 'Comment ça marche',
        'search_title' => 'Rechercher',
        'search_blurb' => 'Ce que le champ accepte, comment fonctionne le scanner, et pourquoi une faute de frappe trouve quand même.',
        'lists_title' => 'Listes',
        'lists_blurb' => 'Enregistrer, partager, offrir à plusieurs, Ami secret, amis et rappels. Neuf pages courtes.',
        'link' => 'Aide',
    ],

    /*
     * Les personnes avec qui vous partagez des listes.
     *
     * Créé en ouvrant le lien de partage de quelqu'un puis en se connectant, ou
     * en ajoutant une adresse ici. Voir App\Services\Social\Friends.
     */
    'friends' => [
        'title' => 'Amis',
        'intro' => 'Les personnes dont vous avez une liste, et qui ont l\'une des vôtres. Qui ouvre une liste que vous avez partagée apparaît ici, et vous chez eux.',
        'empty' => 'Personne pour l\'instant. Ouvrez une liste que l\'on vous a partagée, ou ajoutez une adresse ci-dessous.',
        'your_note' => 'votre note',
        'they_share' => 'Listes de :name',
        'they_see' => 'Vos listes que :name voit',
        'day' => 'Jour',
        'month' => 'Mois',
        'their_birthday' => 'Son anniversaire',
        'their_birthday_optional' => 'Son anniversaire (facultatif)',
        'add_birthday' => 'Ajouter un anniversaire',
        'edit_birthday' => 'Modifier l\'anniversaire',
        'remove' => 'Retirer',
        'remove_confirm' => 'Retirer :name ? Vous disparaissez de la liste d\'amis de l\'autre, dans les deux sens.',
        'removed' => 'Retiré. Vous n\'êtes plus liés.',
        'save' => 'Enregistrer',
        'add_title' => 'Ajouter quelqu\'un',
        'add_hint' => 'Par e-mail. Si la personne a déjà un compte, vous êtes liés tout de suite ; sinon, dès sa connexion. Nous ne lui envoyons aucun e-mail.',
        'email' => 'Son e-mail',
        'add' => 'Ajouter',
        'added' => 'Ajouté. La personne apparaîtra ici une fois connectée.',
        'settings_title' => 'Ce que vos amis voient',
        'settings_saved' => 'Enregistré.',
        'my_birthday' => 'Mon anniversaire',
        'see_birthday' => 'Mes amis peuvent voir mon anniversaire',
        'see_birthday_hint' => 'Le jour et le mois. Jamais l\'année.',
    ],

];
