<?php

declare(strict_types=1);

/**
 * The list help pages, in French. See lang/nl/help_lists.php for the shape,
 * the [words](path) link syntax, the "1. " steps, and why this is not in
 * site.php.
 */
return [
    'index' => [
        'title' => 'Comment fonctionnent les listes',
        'seo_title' => 'Comment fonctionnent les listes',
        'seo_description' => 'Tout ce qu’une liste permet : enregistrer, partager, offrir à plusieurs, Ami secret, amis et rappels. Pas à pas, en images.',
        'intro' => 'Une liste garde ce que vous trouvez ici, pour vous ou pour quelqu’un d’autre. Ci-dessous, ce qu’on peut en faire et comment, sujet par sujet, en images. Commencez par le premier si vous n’avez encore rien enregistré.',
        'back' => 'Tous les sujets',
        'next' => 'Suivant',
        'cta_search' => 'Trouver quelque chose à enregistrer',
        'cta_lists' => 'Vers mes listes',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Enregistrer et créer une liste',
            'blurb' => 'Trouver, enregistrer, ouvrir ses listes, et créer une liste en trois étapes.',
            'seo_description' => 'Enregistrez tout ce que vous trouvez ici dans une liste de souhaits et créez-en une en trois étapes. En images.',
            'intro' => 'Pas besoin de créer une liste d’abord. L’enregistrement le propose, et la page d’accueil a un bouton qui en crée une en trois étapes.',
            'numbered' => true,
            'sections' => [
                [
                    'title' => 'Trouvez quelque chose à garder',
                    'body' => '[Cherchez](search), ou parcourez une [Cove](cove). Chaque fiche produit porte un marque-page sur sa photo.',
                    'shot' => 'find',
                    'alt' => 'Deux fiches produit, chacune avec un bouton marque-page sur sa photo.',
                ],
                [
                    'title' => 'Enregistrez-le et choisissez une liste',
                    'body' => 'Touchez le marque-page et c’est dans votre liste. Touchez encore pour choisir une autre liste ou en commencer une. Sur un ordinateur, une petite flèche à côté du marque-page ouvre ce volet directement.',
                    'shot' => 'choose',
                    'alt' => 'Le volet ouvert à côté d’un produit, avec les listes où enregistrer et l’option d’en commencer une nouvelle.',
                ],
                [
                    'title' => 'Ouvrez vos listes',
                    'body' => 'Tout ce que vous avez enregistré est sous [Mes listes](lists). Chaque liste montre ce qu’elle contient, si elle est privée et pour qui elle est. Quand un prix baisse, la fiche le dit.',
                    'shot' => 'lists',
                    'alt' => 'La page Mes listes, avec deux listes et le bouton pour en créer une.',
                ],
                [
                    'title' => 'Créer une liste en trois étapes',
                    'body' => "1. Touchez « Nouvelle liste » sous [Mes listes](lists), ou « Créer une nouvelle liste » sur la page d’accueil.\n2. Choisissez pour qui elle est : « Pour moi », « Pour quelqu’un d’autre » ou « À plusieurs, pour quelqu’un ». Touchez « Suivant ».\n3. Donnez un nom et une occasion à la liste. Touchez « Suivant ».\n4. Choisissez « Privée (ou partager plus tard) » ou « Partager par un lien », et touchez « Créer la liste ».\n\nOu sautez tout cela : en enregistrant, touchez le marque-page et choisissez-y une nouvelle liste. Ce que vous enregistriez y va tout de suite.\n\nUn choix est ensuite fixé : pour qui elle est. Tout le reste se change encore. Ce que permettent les trois sortes est sous [Liste de souhaits, liste cadeau ou cadeau de groupe](lists-help/kinds).",
                    'shot' => 'wizard',
                    'alt' => 'La première étape d’une nouvelle liste, avec les trois choix de pour qui elle est.',
                ],
                [
                    'title' => 'Pas besoin d’être connecté pour commencer',
                    'body' => 'Les trois étapes marchent sans compte. À la fin vous vous connectez et la liste est là. Ce que vous avez rempli est gardé un jour, vous pouvez donc vous absenter.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Liste de souhaits, liste cadeau ou cadeau de groupe',
            'blurb' => 'Le seul choix qui est fixé, et ce que chaque sorte de liste permet.',
            'seo_description' => 'Trois sortes de listes : une liste de souhaits pour soi, une liste cadeau pour quelqu’un d’autre, ou un cadeau de groupe. Ce que chacune permet et ce qui est fixé.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Un choix est fixé',
                    'body' => 'La première étape d’une [nouvelle liste](lists-help/saving) demande pour qui elle est. Cela décide de ce que la liste permet, et c’est la seule chose qu’on ne change plus ensuite. Le nom, l’occasion et qui la voit se changent toujours.',
                ],
                [
                    'title' => 'Pour moi : une liste de souhaits',
                    'body' => 'Ce que vous aimeriez. [Partagez-la](lists-help/sharing) et les autres peuvent cocher ce qu’ils achètent, sans que vous voyiez quoi ni qui. La surprise tient. Si vous préférez savoir, activez-le pour cette liste.',
                ],
                [
                    'title' => 'Pour quelqu’un d’autre : une liste cadeau',
                    'body' => 'Des idées pour quelqu’un qui n’ouvre jamais la liste lui-même. Ceux avec qui vous la partagez cochent ce qu’ils [achètent](lists-help/claiming), pour que personne n’achète deux fois. Vous le voyez, puisque vous offrez aussi.',
                ],
                [
                    'title' => 'À plusieurs, pour quelqu’un : un cadeau de groupe',
                    'body' => 'Un cadeau, plusieurs donateurs. Toute personne avec le lien peut ajouter des idées, voter et dire ce qu’elle met. Aucun argent ne circule ici ; vous réglez cela entre vous. Plus sous [Offrir un cadeau à plusieurs](lists-help/group).',
                ],
                [
                    'title' => 'Une occasion et une date',
                    'body' => 'Toute liste peut porter une occasion : anniversaire, Noël, mariage, naissance, et dix autres. Pour un anniversaire, Noël et la Saint-Valentin, la date se remplit toute seule. Avec une date, vous recevez un [rappel](lists-help/friends) à temps.',
                ],
            ],
        ],

        'items' => [
            'title' => 'Ce qui va sur une liste',
            'blurb' => 'Des produits d’ici, vos propres articles avec un lien, copier, modifier, et le prix qui baisse.',
            'seo_description' => 'Enregistrer des produits, ajouter vos propres articles avec un lien et un prix, copier vers une autre liste, et voir quand un prix baisse.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Le marque-page',
                    'body' => 'Sur chaque fiche produit, dans la [recherche](search) et dans chaque [Cove](cove). Un toucher enregistre dans la dernière liste où vous avez enregistré, sinon dans votre liste par défaut. Un autre toucher ouvre le volet : vous y choisissez une autre liste, le déplacez, ou le retirez. Sur un ordinateur, une petite flèche à côté du marque-page ouvre le volet directement.',
                ],
                [
                    'title' => 'Ajouter depuis la liste',
                    'body' => "1. Ouvrez votre liste sous [Mes listes](lists).\n2. Touchez « + Ajouter un produit ».\n3. Tapez ce que vous cherchez et appuyez sur Entrée, ou touchez l’icône de scan et visez le code-barres avec votre appareil photo.\n4. Touchez le produit dans les résultats. Il est tout de suite sur votre liste.",
                    'shot' => 'add',
                    'alt' => 'Le champ de recherche en haut d’une liste pour ajouter un produit, avec en dessous le lien pour l’ajouter vous-même.',
                ],
                [
                    'title' => 'Quelque chose qui n’est pas sur ce site',
                    'body' => "1. Touchez « + Ajouter un produit ».\n2. Sous le champ de recherche, choisissez « Ajoutez-le vous-même ».\n3. Indiquez ce que c’est. Un lien, un prix et une note comme « taille M, en bleu » peuvent l’accompagner.\n4. Enregistrez.\n\nVos propres articles se modifient plus tard avec « Modifier ». Les produits du catalogue non : leur titre et leur prix viennent de la boutique.",
                ],
                [
                    'title' => 'Copier, pas déplacer',
                    'body' => 'Chaque article a « Copier vers une autre liste ». Sur la [liste partagée](lists-help/claiming) de quelqu’un d’autre, c’est « Ajouter à ma liste ». L’original reste ; la note et le prix suivent, la personne qui l’achète non.',
                ],
                [
                    'title' => 'Quand le prix baisse',
                    'body' => 'Un produit enregistré retient le prix du moment. S’il baisse, la fiche montre le nouveau prix avec l’ancien barré. Il n’y a rien à régler. Plus sous [Garder un œil sur les prix et le stock](lists-help/alerts).',
                ],
                [
                    'title' => 'Retirer',
                    'body' => 'Touchez la croix sur l’article et confirmez. Seule la personne qui gère la liste peut retirer un article. Le plus récent est en haut.',
                ],
            ],
        ],

        'sharing' => [
            'title' => 'Partager une liste',
            'blurb' => 'Par un lien ou avec des amis par leur nom, qui voit quoi, et comment arrêter.',
            'seo_description' => 'Partager une liste de souhaits par un lien ou avec des amis, décider qui peut ajouter et qui voit ce qui a été acheté, et arrêter le partage.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Privée jusqu’à ce que vous la partagiez',
                    'body' => 'Une nouvelle liste n’est vue que par vous. Rien ne la partage en douce : ni une occasion, ni un ami, ni un quiz. C’est vous qui partagez, avec « Partager » sur la liste.',
                ],
                [
                    'title' => 'Par un lien',
                    'body' => "1. Ouvrez votre liste et touchez « Partager ».\n2. Mettez-la sur « Partager par un lien » si elle est encore privée.\n3. Touchez « Copier le lien » et collez-le dans un message. Ou touchez « Copier le message et le lien » pour un petit message tout prêt, ou « Partager » pour choisir WhatsApp, Telegram, l’e-mail ou une autre application.\n\nToute personne avec le lien voit la liste. « Arrêter le partage » rend chaque lien envoyé invalide ; partagez de nouveau et vous en recevez un nouveau.",
                    'shot' => 'share',
                    'alt' => 'Le volet de partage d’une liste, avec le lien, le bouton pour le copier et le bouton pour arrêter le partage.',
                ],
                [
                    'title' => 'Avec des amis par leur nom',
                    'body' => "1. Touchez « Partager », puis « Partager avec des amis ».\n2. Choisissez les [amis](friends) qui peuvent la voir.\n3. Touchez « Envoyer ».\n\nIls reçoivent un e-mail avec le lien, sans le contenu, et la liste apparaît sur leur [page d’amis](friends). « Ne plus partager avec … » l’en retire ; un lien qu’ils avaient déjà continue de marcher jusqu’à ce que vous arrêtiez le partage. Comment on devient amis est sous [Amis, anniversaires et rappels](lists-help/friends).",
                ],
                [
                    'title' => 'Qui peut ajouter',
                    'body' => 'Avec « Tout le monde peut ajouter des cadeaux » activé, qui a le lien met quelque chose sur la liste tout de suite. Désactivé, les propositions vous arrivent et vous décidez. Les articles écrits à la main vous attendent toujours.',
                ],
                [
                    'title' => 'Qui voit ce qui a été acheté',
                    'body' => 'Sur une [liste de souhaits](lists-help/kinds), vous ne voyez pas ce qui est réservé. C’est désactivé par défaut et vous l’activez par liste avec « Montrez-moi ce qui est réservé ». Sur une liste cadeau c’est activé, puisque vous offrez aussi. Les noms de qui achète quoi sont cachés par défaut ; si vous les activez, cela vaut pour les nouvelles réservations seulement.',
                ],
                [
                    'title' => 'Adresse de livraison',
                    'body' => 'Sur votre propre liste de souhaits, vous pouvez garder une adresse de livraison. Elle est stockée chiffrée et n’apparaît qu’à qui a [réservé](lists-help/claiming) quelque chose. Si cette personne lâche la réservation, elle disparaît de nouveau.',
                ],
            ],
        ],

        'claiming' => [
            'title' => 'Acheter sur une liste partagée',
            'blurb' => 'Réserver, lâcher, marquer acheté, proposer quelque chose, et le quiz.',
            'seo_description' => 'Ce que vous pouvez faire sur une liste de souhaits qu’on a partagée avec vous : réserver ce que vous achetez, proposer quelque chose, et jouer au quiz.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Réserver',
                    'body' => "1. Ouvrez le lien que vous avez reçu.\n2. Touchez « Je m'en occupe » sur le cadeau que vous achetez.\n3. Connectez-vous si on vous le demande ; votre geste est exécuté ensuite.\n\nAinsi personne d’autre ne l’achète aussi. La personne pour qui est la liste n’en voit rien.",
                    'shot' => 'shared',
                    'alt' => 'Deux cadeaux sur une liste partagée, chacun avec le bouton pour dire que vous vous en occupez.',
                ],
                [
                    'title' => 'Finalement non, ou acheté',
                    'body' => '« Finalement non » le relâche, quand vous voulez. « Je l’ai acheté » le marque acheté. En haut, vous voyez combien est déjà réservé.',
                ],
                [
                    'title' => 'Proposer quelque chose',
                    'body' => "1. Cherchez au bas de la liste ce que vous voulez proposer, ou décrivez-le vous-même.\n2. Touchez « Proposer quelque chose », ou « Ajouter à la liste » là où c’est permis directement.\n\nLa personne qui gère la liste voit votre proposition et décide. Si c’est permis directement ou non est expliqué sous [Partager une liste](lists-help/sharing).",
                ],
                [
                    'title' => 'Gardez-le aussi pour vous',
                    'body' => 'Chaque article a un marque-page et « Ajouter à ma liste ». Ce que vous copiez arrive sur [votre liste](lists) sans la réservation.',
                ],
                [
                    'title' => 'Le quiz : les connaissez-vous bien ?',
                    'body' => 'Sur une liste partagée d’au moins cinq articles, la personne qui la gère peut créer un quiz. Cinq manches, quatre produits à chaque fois dont un seul est vraiment sur la liste, et un score à partager. La personne qui gère la liste ne joue pas et voit seulement combien ont joué et le score moyen.',
                ],
            ],
        ],

        'group' => [
            'title' => 'Offrir un cadeau à plusieurs',
            'blurb' => 'Cadeau de groupe, votes, contributions, et discussion avec ceux qui participent.',
            'seo_description' => 'Acheter un cadeau à plusieurs : réunir des idées, voter, convenir de qui met quoi, et en discuter.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Un cadeau de groupe',
                    'body' => "1. Créez une [nouvelle liste](lists-help/saving) et choisissez « À plusieurs, pour quelqu’un » à la première étape.\n2. Dites pour qui elle est et donnez-lui un nom.\n3. Partagez le lien avec ceux qui participent.\n\nToute personne avec le lien peut ajouter des idées et voter. Rien à réserver : c’est un seul cadeau de vous tous. Tirer les noms au sort est autre chose ; c’est sous [Ami secret](lists-help/santa).",
                ],
                [
                    'title' => 'Voter',
                    'body' => 'Chaque idée a « Voter pour » et un compteur. L’ordre ne change pas pendant que vous regardez ; les compteurs, si.',
                ],
                [
                    'title' => 'Contribuer',
                    'body' => 'Sous « Comment chacun contribue », vous choisissez : chacun choisit son montant, ou tout le monde le même. Qui participe touche « J\'en suis » et voit sa part et le total. Seul l’organisateur voit qui donne quoi, sauf s’il active « Tout le monde voit qui participe ». Aucun argent ne circule ici ; vous réglez cela entre vous.',
                ],
                [
                    'title' => 'En discuter',
                    'body' => 'À côté de la liste se trouve « Discussion », un fil pour tous ceux qui ont le lien. La personne pour qui est la liste ne le lit pas. Vous publiez avec votre nom ; vous pouvez retirer vos propres messages, la personne qui gère la liste tous. Une liste privée n’a pas de discussion.',
                ],
            ],
        ],

        'santa' => [
            'title' => 'Ami secret',
            'blurb' => 'Tirer les noms au sort sans papiers : un groupe, un budget, une date, et chacun reçoit un nom.',
            'seo_description' => 'Tirer les noms au sort pour un Ami secret ou Secret Santa : créez un groupe, invitez tout le monde par un lien, tirez, et rattachez une liste de souhaits.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Créer un groupe',
                    'body' => "1. Allez sur [Ami secret](santa).\n2. Touchez « Créer un groupe ».\n3. Donnez au groupe un nom, un budget et la date où vous offrez les cadeaux.\n\nVous êtes l’organisateur.",
                    'shot' => 'santa',
                    'alt' => 'La page Ami secret, avec le bouton pour créer un groupe.',
                ],
                [
                    'title' => 'Inviter tout le monde',
                    'body' => "1. Copiez le lien d’invitation du groupe.\n2. Envoyez-le à tous ceux qui participent.\n3. Qui l’ouvre remplit un nom et une adresse e-mail. Pas besoin de compte.",
                ],
                [
                    'title' => 'Tirer au sort',
                    'body' => "1. Attendez que tout le monde soit là, au moins deux personnes.\n2. Touchez « Lancer le tirage ».\n\nChacun reçoit un nom par e-mail. L’organisateur ne voit jamais les paires, donc vous aussi restez surpris.",
                ],
                [
                    'title' => 'Si quelqu’un se retire',
                    'body' => 'Retirez cette personne du groupe, ou choisissez « Retirer au sort pour cette personne ». Seules les paires concernées sont retirées au sort, et seules ces personnes reçoivent un nouvel e-mail.',
                ],
                [
                    'title' => 'Rattacher ma liste de souhaits au groupe',
                    'body' => "1. Ouvrez votre [liste de souhaits](lists).\n2. Touchez « Utiliser cette liste » à côté du groupe.\n\nLa personne qui vous a tiré voit alors ce qui vous ferait plaisir, sans que vous sachiez qui c’est. Pas encore de liste ? [Créez-en une](lists-help/saving) en trois étapes.",
                ],
                [
                    'title' => 'Un rappel avant',
                    'body' => 'Trente, quinze et deux jours avant la date, vous recevez un signe, ici et par e-mail. Plus sous [Amis, anniversaires et rappels](lists-help/friends).',
                ],
            ],
        ],

        'friends' => [
            'title' => 'Amis, anniversaires et rappels',
            'blurb' => 'Qui sont vos amis, ce qu’ils voient, et quand vous recevez un signe.',
            'seo_description' => 'Ajouter des amis, garder les anniversaires, et recevoir un rappel à temps pour un anniversaire ou une occasion.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Devenir amis',
                    'body' => "Quand quelqu’un ouvre votre lien de partage en étant connecté, vous êtes [amis](friends). Pour ajouter quelqu’un vous-même :\n\n1. Allez sur [Amis](friends).\n2. Sous « Ajouter une personne », indiquez une adresse e-mail, et l’anniversaire si vous voulez.\n3. Touchez « Ajouter ».\n\nCette personne ne reçoit pas d’e-mail pour cela. Si elle a déjà un compte, vous êtes reliés tout de suite ; sinon dès qu’elle se connecte.",
                    'shot' => 'friends',
                    'alt' => 'La page des amis, avec le formulaire pour ajouter quelqu’un par son adresse e-mail.',
                ],
                [
                    'title' => 'Ce qu’un ami voit',
                    'body' => 'La [page des amis](friends) montre, par ami, son anniversaire, les listes qu’il a partagées avec vous et lesquelles de vos listes il voit. Ce qui est réservé n’y apparaît jamais. Retirer un ami retire le lien des deux côtés ; listes et réservations restent.',
                ],
                [
                    'title' => 'Anniversaires',
                    'body' => 'Pour un ami, vous gardez le jour et le mois, jamais l’année. C’est votre note. Votre propre anniversaire se remplit dans votre compte, avec le choix de le montrer à vos amis ou non.',
                ],
                [
                    'title' => 'Rappels',
                    'body' => 'Trente, quinze et deux jours avant, vous recevez un signe pour un anniversaire, la date d’un [Ami secret](lists-help/santa) et l’[occasion](lists-help/kinds) d’une liste. Ici et par e-mail. L’e-mail nomme la date et le lien, jamais ce qui est sur la liste.',
                ],
                [
                    'title' => 'Notifications',
                    'body' => 'Sous [Notifications](notifications), vous voyez ce qui s’est passé : quelqu’un a partagé une liste avec vous, a ajouté ou proposé quelque chose, il y a un nouveau message dans une discussion, un produit est de retour en stock, une recherche que vous suivez a du nouveau. Ouvrir la page marque tout comme lu.',
                ],
            ],
        ],

        'alerts' => [
            'title' => 'Garder un œil sur les prix et le stock',
            'blurb' => 'Le prix qui baisse, un produit qui revient, et une recherche que vous suivez.',
            'seo_description' => 'Voir quand un prix baisse, être prévenu quand un produit est de retour en stock, et suivre une recherche.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Enregistrez-le, et le prix suit tout seul',
                    'body' => 'Un produit sur votre [liste](lists) retient le prix du moment. S’il baisse, vous le voyez sur la fiche, avec l’ancien prix barré. Rien d’autre à faire.',
                ],
                [
                    'title' => 'De retour en stock',
                    'body' => "1. Ouvrez la page d’un produit que plus aucune boutique n’a.\n2. Touchez « Prévenez-moi de son retour ».\n\nVous recevez un signe dès qu’une boutique l’a de nouveau. Arrêtez au même endroit avec « Ne plus suivre ».",
                ],
                [
                    'title' => 'Suivre une recherche',
                    'body' => "1. [Cherchez](search) ce que vous voulez suivre.\n2. Au-dessus des résultats, touchez « Prévenez-moi des nouveautés ».\n3. Indiquez un prix maximum si vous voulez et touchez « Suivre cette recherche ».\n\nChaque matin nous regardons s’il y a du nouveau qui correspond, et vous le voyez sous [Notifications](notifications). Arrêtez sur la même page de recherche avec « Arrêter ».",
                    'shot' => 'watch',
                    'alt' => 'Le bouton pour suivre une recherche, avec en dessous le champ pour un prix maximum et le bouton pour confirmer.',
                ],
            ],
        ],
    ],
];
