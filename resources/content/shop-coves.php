<?php

declare(strict_types=1);

/**
 * Shipped Shop Coves: what each shop we compare is like to buy from.
 *
 * Keyed on `merchants.domain`, not on the name and not on `external_id`. The
 * name is what an editor sees and gets tidied ("Coolblue BE" → "Coolblue"); the
 * external id is an Awin advertiser number that changes if an advertiser is
 * re-onboarded. The domain is the one thing that is both stable and the shop's
 * actual identity to a reader.
 *
 * Then by language, because a Cove is written per market in that market's
 * language — the same rule as every other kind. A shop with no entry for a
 * language has no Cove in the markets that speak it, and `/shops` renders the
 * directory without the band. That is deliberate: an untranslated Cove is worse
 * than an absent one.
 *
 * ## What may and may not be said here
 *
 * These follow the same rules as `Defaults::SHOP_SYSTEM`, the prompt an
 * AI-written one is held to — because a hand-written Cove that breaks rules the
 * generated ones keep is how a section stops being coherent:
 *
 * - **No delivery times, return windows, shipping fees, minimum orders or
 *   subscription prices.** They differ per market, change without notice, and a
 *   reader who acts on a wrong one is out of pocket. Point at the shop's own
 *   page instead.
 * - **No "cheapest", "best" or "fastest".** The comparison on the product page
 *   answers that, and the answer changes per product.
 * - **No invented history**: no founding dates, no employee numbers, no revenue.
 *
 * What is left is what we can stand behind: what they sell, who they suit, and
 * what is worth checking before buying. We earn a commission on what people buy
 * through us, which is exactly why a piece that finds nothing to qualify would
 * not be worth publishing.
 */
return [
    'bol.com' => [
        'nl' => [
            'title' => 'Kopen bij bol',
            'blurb' => 'Een warenhuis met een marktplaats erin. Wat je koopt is vaak niet van bol zelf — en dat is het eerste wat je wilt weten.',
            'body' => "bol begon als boekwinkel en verkoopt inmiddels zowat alles: elektronica, speelgoed, huishoudelijke spullen, tuin, verzorging. Voor veel mensen in Nederland en België is het de eerste plek waar ze kijken, en dat is precies waarom het de moeite loont om te weten hoe het in elkaar zit.\n\nHet belangrijkste: een deel van het aanbod komt van bol zelf, een ander deel van externe verkopers op hun platform. Op de productpagina staat wie de verkoper is. Dat bepaalt bij wie je koopt, wie je aanspreekt als er iets misgaat, en soms ook wat je voor verzending betaalt. Twee aanbiedingen van hetzelfde artikel kunnen van twee verschillende partijen komen.\n\nWij tonen bol-prijzen naast die van andere winkels, en die vergelijking verandert per product. Waar bol vooral sterk in is, is breedte: als iets nergens anders te vinden is, staat het hier vaak wel. Voor grote elektronica of witgoed loont het om ook naar de gespecialiseerde ketens te kijken — niet omdat bol daar slecht in is, maar omdat service en installatie daar een groter deel van de aankoop zijn.\n\nKijk voor levering, retour en garantie altijd op de pagina van de verkoper zelf. Bij een marktplaats zijn dat geen vaste huisregels.",
        ],
        'fr' => [
            'title' => 'Acheter chez bol',
            'blurb' => "Un grand magasin doublé d'une place de marché. Ce que vous achetez ne vient souvent pas de bol — et c'est la première chose à savoir.",
            'body' => "bol a commencé comme librairie et vend aujourd'hui à peu près tout : électronique, jouets, articles ménagers, jardin, soins. Pour beaucoup de gens en Belgique et aux Pays-Bas, c'est le premier endroit où l'on regarde, et c'est précisément pour cela qu'il vaut la peine de comprendre comment cela fonctionne.\n\nL'essentiel : une partie de l'offre vient de bol, une autre de vendeurs externes présents sur leur plateforme. La fiche produit indique qui est le vendeur. Cela détermine chez qui vous achetez, à qui vous vous adressez en cas de problème, et parfois ce que vous payez pour l'envoi. Deux offres du même article peuvent venir de deux parties différentes.\n\nNous affichons les prix de bol à côté de ceux des autres boutiques, et cette comparaison change selon le produit. La force de bol, c'est l'étendue : ce qui est introuvable ailleurs s'y trouve souvent. Pour le gros électroménager, cela vaut la peine de regarder aussi les chaînes spécialisées — non pas parce que bol s'en sort mal, mais parce que le service et l'installation y pèsent davantage dans l'achat.\n\nPour la livraison, les retours et la garantie, consultez toujours la page du vendeur lui-même. Sur une place de marché, ce ne sont pas des règles maison uniformes.",
        ],
        'en' => [
            'title' => 'Buying from bol',
            'blurb' => 'A department store with a marketplace inside it. What you buy is often not sold by bol — and that is the first thing worth knowing.',
            'body' => "bol started as a bookshop and now sells close to everything: electronics, toys, household goods, garden, personal care. For a lot of people in the Netherlands and Belgium it is the first place they look, which is exactly why it is worth understanding how it works.\n\nThe important part: some of the range is sold by bol itself and some by third-party sellers on their platform. The product page says which. That decides who you are buying from, who you deal with when something goes wrong, and sometimes what you pay to have it sent. Two offers for the same item can come from two different parties.\n\nWe show bol's prices next to other shops', and that comparison changes per product. What bol is genuinely strong at is breadth: if something is nowhere else, it is often here. For large appliances it is worth also looking at the specialists — not because bol is bad at them, but because service and installation are a bigger part of that purchase.\n\nFor delivery, returns and warranty, always read the seller's own page. On a marketplace those are not one uniform set of house rules.",
        ],
    ],

    'coolblue.be' => [
        'nl' => [
            'title' => 'Kopen bij Coolblue',
            'blurb' => 'Elektronica en witgoed, met uitleg erbij. Je betaalt mee aan de service — de vraag is of je die nodig hebt.',
            'body' => "Coolblue verkoopt elektronica en huishoudelijke apparaten: laptops, telefoons, wasmachines, koffiemachines, televisies. Wat ze onderscheidt is niet het assortiment maar de manier waarop het gepresenteerd wordt — productpagina's met uitleg in gewone taal, vergelijkingen tussen modellen, en advies over wat je wel en niet nodig hebt.\n\nDat is echt iets waard als je iets koopt waar je weinig van weet. Een wasmachine of een fototoestel kiezen op specificatielijstjes is lastig, en een winkel die uitlegt waarom een verschil ertoe doet, bespaart je een aankoop waar je spijt van krijgt. Ze hebben ook fysieke winkels, en voor grote apparaten leveren en installeren ze zelf.\n\nDaar staat tegenover dat die service in de prijs zit. Voor een artikel waarvan je precies weet welk model je wilt — een kabel, een bekende gameconsole, een vervangonderdeel — koop je hetzelfde ding elders soms goedkoper. Onze prijsvergelijking laat dat per product zien, en het antwoord verschilt echt per artikel.\n\nLevering, montage en retour staan op hun eigen pagina's; die voorwaarden verschillen per soort product en per land.",
        ],
        'fr' => [
            'title' => 'Acheter chez Coolblue',
            'blurb' => "De l'électronique et de l'électroménager, avec les explications. Le service est dans le prix — reste à savoir si vous en avez besoin.",
            'body' => "Coolblue vend de l'électronique et de l'électroménager : ordinateurs portables, téléphones, lave-linge, machines à café, téléviseurs. Ce qui les distingue n'est pas l'assortiment mais la façon dont il est présenté — des fiches produit écrites en langage clair, des comparaisons entre modèles, et des conseils sur ce dont vous avez ou non besoin.\n\nCela vaut réellement quelque chose quand vous achetez un objet que vous connaissez mal. Choisir un lave-linge ou un appareil photo sur des listes de spécifications est difficile, et une boutique qui explique pourquoi une différence compte vous évite un achat que vous regretterez. Ils ont aussi des magasins physiques, et pour les gros appareils ils livrent et installent eux-mêmes.\n\nEn contrepartie, ce service est dans le prix. Pour un article dont vous connaissez déjà exactement le modèle — un câble, une console connue, une pièce de rechange — le même objet se trouve parfois moins cher ailleurs. Notre comparaison le montre produit par produit, et la réponse change vraiment d'un article à l'autre.\n\nLa livraison, l'installation et les retours figurent sur leurs propres pages ; ces conditions varient selon le type de produit et le pays.",
        ],
    ],

    'coolblue.nl' => [
        'nl' => [
            'title' => 'Kopen bij Coolblue',
            'blurb' => 'Elektronica en witgoed, met uitleg erbij. Je betaalt mee aan de service — de vraag is of je die nodig hebt.',
            'body' => "Coolblue verkoopt elektronica en huishoudelijke apparaten: laptops, telefoons, wasmachines, koffiemachines, televisies. Wat ze onderscheidt is niet het assortiment maar de manier waarop het gepresenteerd wordt — productpagina's met uitleg in gewone taal, vergelijkingen tussen modellen, en advies over wat je wel en niet nodig hebt.\n\nDat is echt iets waard als je iets koopt waar je weinig van weet. Een wasmachine of een fototoestel kiezen op specificatielijstjes is lastig, en een winkel die uitlegt waarom een verschil ertoe doet, bespaart je een aankoop waar je spijt van krijgt. Ze hebben ook fysieke winkels, en voor grote apparaten leveren en installeren ze zelf.\n\nDaar staat tegenover dat die service in de prijs zit. Voor een artikel waarvan je precies weet welk model je wilt — een kabel, een bekende gameconsole, een vervangonderdeel — koop je hetzelfde ding elders soms goedkoper. Onze prijsvergelijking laat dat per product zien, en het antwoord verschilt echt per artikel.\n\nLevering, montage en retour staan op hun eigen pagina's; die voorwaarden verschillen per soort product.",
        ],
    ],

    'dreamland.be' => [
        'nl' => [
            'title' => 'Kopen bij DreamLand',
            'blurb' => 'De speelgoedwinkel van de Colruyt Group. Sterk op zoeken per leeftijd, druk rond Sinterklaas — en dat laatste is ook de kanttekening.',
            'body' => "DreamLand is een Belgische speelgoedketen, onderdeel van de Colruyt Group. Het aanbod is speelgoed, spelletjes, knutselspullen en babyartikelen, met de grote merken die kinderen bij naam kennen en een eigen huismerk ernaast.\n\nWaar ze goed in zijn: zoeken op leeftijd en op wat een kind leuk vindt in plaats van op merk. Voor een cadeau voor een kind dat je niet dagelijks ziet, is dat precies de ingang die je nodig hebt — je weet wel dat hij zeven is en van dino's houdt, niet welk merk hij al heeft.\n\nHet seizoen is de kanttekening. Rond Sinterklaas en Kerst is dit een van de drukste winkels van het land, en populair speelgoed is dan het eerst uitverkocht. Als je zeker wilt zijn van iets specifieks, is vroeg kijken hier meer waard dan bij een winkel met een vlakker jaar. Onze prijsvergelijking toont per artikel welke winkels het nog hebben.\n\nVoorraad per winkel, levering en retour vind je op hun eigen site; dat verschilt per artikel en per periode.",
        ],
        'fr' => [
            'title' => 'Acheter chez DreamLand',
            'blurb' => "Le magasin de jouets du groupe Colruyt. Fort pour chercher par âge, très fréquenté à la Saint-Nicolas — et c'est là que ça coince.",
            'body' => "DreamLand est une chaîne de magasins de jouets belge, filiale du groupe Colruyt. On y trouve des jouets, des jeux, du matériel de bricolage et des articles pour bébé, avec les grandes marques que les enfants connaissent par leur nom et une marque maison à côté.\n\nLeur point fort : chercher par âge et par centre d'intérêt plutôt que par marque. Pour un cadeau destiné à un enfant que vous ne voyez pas tous les jours, c'est exactement l'entrée dont vous avez besoin — vous savez qu'il a sept ans et qu'il aime les dinosaures, pas quelle marque il possède déjà.\n\nLa saison est la réserve à émettre. Autour de la Saint-Nicolas et de Noël, c'est l'un des magasins les plus fréquentés du pays, et les jouets populaires partent en premier. Si vous tenez à quelque chose de précis, s'y prendre tôt vaut ici davantage que chez une boutique à l'année plus régulière. Notre comparaison indique, article par article, quelles boutiques l'ont encore.\n\nLe stock par magasin, la livraison et les retours figurent sur leur propre site ; cela varie selon l'article et la période.",
        ],
    ],

    'vandenborre.be' => [
        'nl' => [
            'title' => 'Kopen bij Vanden Borre',
            'blurb' => 'Witgoed en elektronica, met winkels in het hele land. Interessant als je iets groots koopt dat geplaatst moet worden.',
            'body' => "Vanden Borre is een Belgische keten voor elektronica en huishoudtoestellen: wasmachines, koelkasten, ovens, televisies, klein keukengerei. Ze hebben winkels verspreid over het land, wat uitmaakt bij een aankoop die je liever eerst ziet.\n\nVoor een groot toestel is dat het echte verschil met online-only verkopen. Een vaatwasser of een inbouwoven kopen is niet alleen een prijs: er is levering, plaatsing, soms het meenemen van je oude toestel, en iemand die het aansluit. Wie dat allemaal zelf regelt, betaalt daar liever niet voor mee — wie dat niet wil, koopt hier makkelijker.\n\nVoor kleine elektronica waarvan je precies weet wat je zoekt, is het beeld anders: daar is het gewoon één van de winkels in de vergelijking, en of ze scherp staan hangt van het artikel af. Dat is precies wat de prijzen op onze productpagina's laten zien.\n\nWat levering, plaatsing en de overname van een oud toestel kosten of inhouden, staat op hun eigen site en verschilt per producttype.",
        ],
        'fr' => [
            'title' => 'Acheter chez Vanden Borre',
            'blurb' => 'Électroménager et électronique, avec des magasins dans tout le pays. Intéressant pour un gros achat qui doit être installé.',
            'body' => "Vanden Borre est une chaîne belge d'électroménager et d'électronique : lave-linge, réfrigérateurs, fours, téléviseurs, petit électroménager de cuisine. Ils ont des magasins répartis dans tout le pays, ce qui compte pour un achat que l'on préfère voir d'abord.\n\nPour un gros appareil, c'est la vraie différence avec une vente uniquement en ligne. Acheter un lave-vaisselle ou un four encastrable, ce n'est pas seulement un prix : il y a la livraison, la pose, parfois la reprise de l'ancien appareil, et quelqu'un qui raccorde le tout. Celui qui organise tout cela lui-même préfère ne pas le payer ; celui qui n'y tient pas achète plus facilement ici.\n\nPour du petit électronique dont vous savez exactement ce que vous cherchez, le tableau est différent : ce n'est alors qu'une boutique parmi d'autres dans la comparaison, et savoir si elle est bien placée dépend de l'article. C'est précisément ce que montrent les prix sur nos fiches produit.\n\nCe que coûtent et couvrent la livraison, la pose et la reprise figure sur leur propre site et varie selon le type de produit.",
        ],
    ],

    'krefel.be' => [
        'nl' => [
            'title' => 'Kopen bij Krëfel',
            'blurb' => 'Elektro en witgoed, met een eigen ritme van acties. De vraag is meestal niet wat het kost, maar wanneer.',
            'body' => "Krëfel is een Belgische keten voor elektro, witgoed en multimedia — wasmachines, televisies, laptops, klein huishoudelijk — met winkels in het hele land naast de webshop.\n\nWat opvalt is dat het aanbod in golven beweegt. Er zijn periodes met scherpe acties op een deel van het assortiment, en periodes zonder. Voor iets dat je toch al van plan was te kopen en dat niet dringend is, is het aankoopmoment hier vaak belangrijker dan de winkelkeuze. Voor iets dat vandaag stuk is, geldt dat natuurlijk niet.\n\nVerder is het een klassieke elektroketen: winkels waar je toestellen kunt zien, personeel dat je kunt aanspreken, en levering voor de grote stukken. Dat maakt het een reële optie voor een aankoop waarbij je twijfelt tussen twee modellen en die je niet online wilt beslissen.\n\nWat een actie precies inhoudt, en wat levering of installatie kost, staat op hun eigen pagina's — en dat wisselt met de actie.",
        ],
        'fr' => [
            'title' => 'Acheter chez Krëfel',
            'blurb' => "Électro et électroménager, avec son propre rythme de promotions. La question n'est souvent pas combien, mais quand.",
            'body' => "Krëfel est une chaîne belge d'électro, d'électroménager et de multimédia — lave-linge, téléviseurs, ordinateurs portables, petit électroménager — avec des magasins dans tout le pays en plus de la boutique en ligne.\n\nCe qui frappe, c'est que l'offre évolue par vagues. Il y a des périodes de promotions marquées sur une partie de l'assortiment, et des périodes sans. Pour quelque chose que vous comptiez de toute façon acheter et qui n'est pas urgent, le moment de l'achat compte ici souvent plus que le choix de la boutique. Pour un appareil tombé en panne aujourd'hui, cela ne s'applique évidemment pas.\n\nPour le reste, c'est une chaîne d'électro classique : des magasins où voir les appareils, du personnel à qui parler, et la livraison pour les grosses pièces. Cela en fait une option réelle quand vous hésitez entre deux modèles et ne voulez pas trancher en ligne.\n\nCe que couvre exactement une promotion, et ce que coûtent la livraison ou l'installation, figure sur leurs propres pages — et cela change avec la promotion.",
        ],
    ],

    'shop.action.com' => [
        'nl' => [
            'title' => 'Kopen bij Action',
            'blurb' => 'Goedkoop, wisselend en zelden twee keer hetzelfde. Handig voor spullen die niet lang mee hoeven — minder voor spullen die dat wel moeten.',
            'body' => "Action verkoopt huishoudelijke spullen, opberg- en schoonmaakartikelen, knutselmateriaal, feestbenodigdheden en seizoensgebonden dingen, tegen lage prijzen. Het is geen elektronicawinkel en geen cadeauwinkel in de klassieke zin, en dat is precies waar de bruikbaarheid zit.\n\nWaar het goed voor is: dingen die je nodig hebt maar niet jarenlang wilt bewaren. Verpakkingsmateriaal, knutselspullen voor een kinderfeestje, opbergdozen, kaarsen, tafeldecoratie. Voor een cadeau werkt het vooral als aanvulling — het materiaal rond het cadeau in plaats van het cadeau zelf.\n\nHet assortiment wisselt sterk en veel artikelen komen niet terug. Wie iets ziet dat past, koopt het beter meteen dan dat hij het over twee weken nog eens probeert. Dat is ook de reden dat het weinig zin heeft om hier naar één bepaald merkartikel te zoeken: het aanbod is niet opgebouwd om vergeleken te worden, maar om gevonden te worden.\n\nVoorraad, bestellen en retour lopen via hun eigen site en verschillen per land.",
        ],
    ],
];
