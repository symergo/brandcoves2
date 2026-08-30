<?php

declare(strict_types=1);

/**
 * The page copy this site shipped with, as template blocks.
 *
 * ## Why the strings are here and not read from `lang/`
 *
 * The migration that consumes this file has to produce the same result on a
 * database created next year, and by then `narrative.*` and `brand_narrative.*`
 * are gone from the language files — they are deleted in the same release, which
 * is the point of the exercise. `__('site.narrative.compare_1', [], 'nl')` would
 * work today and silently return the literal key string after that, seeding a
 * fresh environment with dotted paths where its copy should be.
 *
 * Forward-only means self-contained. So this is a snapshot, taken once from the
 * four language files, and it is never updated again: after the release, copy is
 * edited in `/admin`, and this file is only ever read by a database that has not
 * been seeded yet.
 *
 * ## `slot` is the bridge to the old table
 *
 * Where an environment has `copy_templates` rows for that slot — staging ran
 * `bc:seed-copy` and somebody may have rewritten them since — the migration
 * takes those instead, with their weights and their alternatives. An editor's
 * afternoon is the one thing in that table that cannot be regenerated. `null`
 * means the block is new and has no old slot behind it.
 *
 * `conditions` are the guards that used to be hardcoded in `PageNarrative`:
 * `$facts['comparable'] > 0 ? $this->line('compare_2') : null` is now
 * `['multi_shop']` on that block.
 */

return [
    0 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 1,
        'conditions' => [
        ],
        'slot' => 'narrative.compare_heading',
        'bodies' => [
            'nl' => ':term vergelijken tussen winkels',
            'fr' => 'Comparer :term entre boutiques',
            'en' => 'Comparing :term across shops',
            'es' => 'Comparar :term entre tiendas',
        ],
    ],
    1 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 2,
        'conditions' => [
        ],
        'slot' => 'narrative.compare_1',
        'bodies' => [
            'nl' => 'Deze pagina verzamelt elke :term waarvoor we een actuele prijs vinden en toont één kaart per fysiek product, niet één kaart per aanbieding. Dat onderscheid is de hele reden dat deze pagina bestaat: een winkel die dezelfde :term verkoopt als drie andere biedt hetzelfde voorwerp aan tegen een ander getal, en de enige interessante vraag is welk getal vandaag het laagst is.',
            'fr' => 'Cette page rassemble chaque :term pour lequel nous trouvons un prix en direct et affiche une fiche par produit physique, pas une fiche par annonce. Cette distinction justifie à elle seule la page : une boutique qui vend le même :term que trois autres propose le même objet à un chiffre différent, et la seule question intéressante est de savoir lequel est le plus bas aujourd’hui.',
            'en' => 'This page collects every :term we can find a live price for and puts one card per physical product, not one card per listing. That distinction is the whole reason the page exists: a shop that stocks the same :term as three others is offering the same object at a different number, and the only interesting question is which number is lowest today.',
            'es' => 'Esta página reúne cada :term del que encontramos un precio en vivo y muestra una ficha por producto físico, no una por anuncio. Esa distinción es la razón de ser de la página: una tienda que vende el mismo :term que otras tres ofrece el mismo objeto con otra cifra, y la única pregunta interesante es cuál es la más baja hoy.',
        ],
    ],
    2 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 3,
        'conditions' => [
            0 => 'multi_shop',
        ],
        'slot' => 'narrative.compare_2',
        'bodies' => [
            'nl' => ':comparable van de :shown getoonde producten worden door meer dan één winkel verkocht, dus elk van die kaarten is op zich al een vergelijking, de goedkoopste aanbieding bovenaan, de rest één klik verder, met op elke kaart de naam van de winkel. Wordt iets maar door één winkel verkocht, dan zeggen we dat, in plaats van een keuze te suggereren die er niet is.',
            'fr' => ':comparable des :shown produits affichés sont vendus par plus d’une boutique : chacune de ces fiches est donc déjà une comparaison, l’offre la moins chère en premier, le reste à un clic, et le nom de la boutique sur chacune. Lorsqu’un produit n’est vendu que par une boutique, nous le disons plutôt que de laisser croire à un choix inexistant.',
            'en' => ':comparable of the :shown products shown here are stocked by more than one shop, so each of those cards is a small comparison in itself, the cheapest offer first, the rest a click away, and the shop names on every one of them. Where a product is sold by a single shop we say so rather than implying a choice that does not exist.',
            'es' => ':comparable de los :shown productos mostrados los venden más de una tienda, así que cada una de esas fichas ya es una comparación en sí misma, la oferta más barata primero, el resto a un clic, y el nombre de la tienda en todas. Cuando algo lo vende una sola tienda lo decimos, en lugar de insinuar una elección que no existe.',
        ],
    ],
    3 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 4,
        'conditions' => [
        ],
        'slot' => 'narrative.compare_3',
        'bodies' => [
            'nl' => 'Aanbiedingen komen uit winkelfeeds en uit live opvragingen op het moment dat je de pagina laadt; daarom kan een prijs hier verschillen van wat je vanochtend zag. Elke link gaat naar de winkel die de aanbieding doet. Wij verkopen zelf niets en hebben geen voorraad.',
            'fr' => 'Les offres viennent des flux des marchands et de requêtes en direct au moment où vous chargez la page, d’où l’écart possible avec un prix vu ce matin. Chaque lien mène à la boutique qui fait l’offre : nous ne vendons rien nous-mêmes et ne détenons aucun stock.',
            'en' => 'Offers come from retailer feeds and from live queries at the moment you load the page, which is why a price here can differ from one you saw this morning. Every link goes to the shop that made the offer; we do not sell anything ourselves and we hold no stock.',
            'es' => 'Las ofertas vienen de los feeds de las tiendas y de consultas en vivo en el momento en que cargas la página; por eso un precio puede diferir del que viste esta mañana. Cada enlace lleva a la tienda que hace la oferta: nosotros no vendemos nada ni tenemos stock.',
        ],
    ],
    4 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 5,
        'conditions' => [
        ],
        'slot' => 'narrative.prices_heading',
        'bodies' => [
            'nl' => 'Wat de prijzen voor :term betekenen',
            'fr' => 'Ce que signifient les prix pour :term',
            'en' => 'What the prices for :term mean',
            'es' => 'Qué significan los precios de :term',
        ],
    ],
    5 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 6,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'narrative.prices_1',
        'bodies' => [
            'nl' => 'De prijzen op deze pagina lopen van :low tot :high, en die spreiding is meestal een spreiding van producten en niet van winkels, de goedkoopste :term en de duurste zijn zelden hetzelfde ding met een andere sticker. Sorteren op prijs is de snelste manier om te zien waar het bruikbare midden van dat bereik ligt.',
            'fr' => 'Les prix de cette page vont de :low à :high, et cet écart est le plus souvent un écart de produits, pas de boutiques, le :term le moins cher et le plus cher sont rarement la même chose avec une autre étiquette. Trier par prix reste le moyen le plus rapide de voir où se situe le milieu utile de cette fourchette.',
            'en' => 'Prices on this page run from :low to :high, and that spread is usually a spread of products rather than of shops, the cheapest :term and the most expensive one are rarely the same thing with a different sticker. Sorting by price is the fastest way to see where the useful middle of that range sits.',
            'es' => 'Los precios de esta página van de :low a :high, y esa diferencia suele ser una diferencia de productos y no de tiendas, el :term más barato y el más caro rara vez son la misma cosa con otra etiqueta. Ordenar por precio es la forma más rápida de ver dónde está el centro útil de ese rango.',
        ],
    ],
    6 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 7,
        'conditions' => [
        ],
        'slot' => 'narrative.prices_2',
        'bodies' => [
            'nl' => 'Een kortingslabel hier wordt gemeten tegen onze eigen 30-daagse medianprijs voor precies dat product, nooit tegen een doorgestreepte winkelprijs. Die twee verschillen vaker dan je zou denken: een "van"-prijs is een marketingbeslissing, een mediaan is wat het ding een maand lang echt heeft gekost bij iedereen die het verkoopt. Beweegt er niets echt, dan verschijnt er geen label.',
            'fr' => 'Une étiquette de remise est mesurée sur notre propre prix médian à 30 jours pour ce produit précis, jamais sur un prix barré affiché par une boutique. Les deux divergent plus souvent qu’on ne le croit : un prix « avant » est une décision marketing, une médiane est ce que la chose a réellement coûté pendant un mois chez tous ceux qui la vendent. Si rien n’a bougé, aucune étiquette n’apparaît.',
            'en' => 'A discount badge here is measured against our own 30-day median price for that exact product, never against a shop\\x27s crossed-out figure. The two disagree more often than you would expect: a "was" price is a marketing decision, while a median is what the thing has actually cost over a month across everyone selling it. If nothing genuinely moved, no badge appears.',
            'es' => 'Una etiqueta de descuento aquí se mide contra nuestra propia mediana de 30 días para ese producto exacto, nunca contra un precio tachado de la tienda. Los dos discrepan más de lo que parece: un precio «antes» es una decisión de marketing, y una mediana es lo que la cosa ha costado realmente durante un mes entre todos los que la venden. Si nada se ha movido de verdad, no aparece etiqueta.',
        ],
    ],
    7 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 8,
        'conditions' => [
            0 => 'has_discount',
        ],
        'slot' => 'narrative.prices_3',
        'bodies' => [
            'nl' => ':reduced producten op deze pagina staan nu onder die mediaan, de grootste met :percent%. Prijzen en voorraad worden twee keer per dag opnieuw gecontroleerd en live bronnen worden bij elke zoekopdracht bevraagd, dus dit is geen momentopname van vorige week.',
            'fr' => ':reduced produits de cette page sont actuellement sous cette médiane, le plus fort écart étant de :percent%. Prix et stocks sont revérifiés deux fois par jour et les sources en direct sont interrogées à chaque recherche : ce n’est pas un instantané de la semaine dernière.',
            'en' => ':reduced of the products on this page are currently below that median, the largest by :percent%. Prices and stock are re-checked twice a day and live sources are queried on every search, so this page is not a snapshot of last week.',
            'es' => ':reduced productos de esta página están ahora por debajo de esa mediana, el mayor un :percent%. Precios y stock se revisan dos veces al día y las fuentes en vivo se consultan en cada búsqueda, así que esto no es una foto de la semana pasada.',
        ],
    ],
    8 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 9,
        'conditions' => [
        ],
        'slot' => 'narrative.choosing_heading',
        'bodies' => [
            'nl' => 'Kiezen tussen :term',
            'fr' => 'Choisir entre :term',
            'en' => 'Choosing between :term',
            'es' => 'Elegir entre :term',
        ],
    ],
    9 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 10,
        'conditions' => [
        ],
        'slot' => 'narrative.choosing_1',
        'bodies' => [
            'nl' => 'Begin bij het aantal aanbiedingen, niet bij de prijs. Een product dat vier winkels verkopen heeft een echte marktprijs en een bodem waar je op kunt vertrouwen; een product bij één winkel heeft een prijs en geen enkele manier om die te toetsen, en dat is het waard om te weten voordat je besluit dat het een goede deal is.',
            'fr' => 'Commencez par le nombre d’offres plutôt que par le prix. Un produit référencé chez quatre boutiques a un vrai prix de marché et un plancher fiable ; un produit vendu par une seule boutique a un prix et aucun moyen de le vérifier, ce qu’il vaut mieux savoir avant de conclure à la bonne affaire.',
            'en' => 'Start with the offer count rather than the price. A product carried by four shops has a real market price and a floor you can trust; one carried by a single shop has a price and no way to test it, which is worth knowing before you decide the deal is good.',
            'es' => 'Empieza por el número de ofertas y no por el precio. Un producto que venden cuatro tiendas tiene un precio de mercado real y un suelo fiable; uno que vende una sola tienda tiene un precio y ninguna forma de contrastarlo, y eso conviene saberlo antes de decidir que es una ganga.',
        ],
    ],
    10 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 11,
        'conditions' => [
        ],
        'slot' => 'narrative.choosing_2',
        'bodies' => [
            'nl' => 'Kijk daarna naar de voorraad. Alles op deze pagina is standaard op voorraad, want een prijs die je niet kunt kopen is geen aanbieding, je kunt dat filter uitzetten om het volledige aanbod te zien, inclusief wat tijdelijk uitverkocht is. De prijsgeschiedenis op elke productpagina laat zien of vandaag echt een goed moment is of gewoon een gemiddelde dag.',
            'fr' => 'Vérifiez ensuite la disponibilité. Tout est en stock par défaut sur cette page, car un prix qu’on ne peut pas payer n’est pas une offre, désactivez le filtre pour voir l’ensemble, y compris ce qui est temporairement indisponible. L’historique de prix sur chaque fiche produit indique si aujourd’hui est réellement un bon moment ou un jour ordinaire.',
            'en' => 'Then check stock. Everything on this page is in stock by default, because an unbuyable price is not an offer, you can turn that filter off if you want to see the full range, including things that are only temporarily unavailable. The price history on each product page shows whether today is genuinely a good moment or an ordinary one.',
            'es' => 'Después mira el stock. Todo en esta página está disponible por defecto, porque un precio que no puedes pagar no es una oferta, puedes desactivar ese filtro para ver el catálogo completo, incluido lo que está agotado temporalmente. El histórico de precios de cada ficha muestra si hoy es realmente un buen momento o uno cualquiera.',
        ],
    ],
    11 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 12,
        'conditions' => [
            0 => 'has_brands',
        ],
        'slot' => 'narrative.choosing_3',
        'bodies' => [
            'nl' => 'Merken in deze resultaten zijn onder meer :brands. Elk merk heeft een eigen pagina met alles wat we ervan voeren, met dezelfde vergelijking tussen winkels.',
            'fr' => 'Parmi les marques présentes dans ces résultats : :brands. Chacune dispose de sa propre page listant tout ce que nous référençons, avec la même comparaison entre boutiques.',
            'en' => 'Brands appearing in these results include :brands. Each has a page of its own listing everything we carry from it, with the same comparison across shops.',
            'es' => 'Entre las marcas de estos resultados están :brands. Cada una tiene su propia página con todo lo que llevamos de ella y la misma comparación entre tiendas.',
        ],
    ],
    12 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 13,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'narrative.faq_price_q',
        'bodies' => [
            'nl' => 'Wat kost :term?',
            'fr' => 'Combien coûte :term ?',
            'en' => 'How much does :term cost?',
            'es' => '¿Cuánto cuesta :term?',
        ],
    ],
    13 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 14,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'narrative.faq_price_a',
        'bodies' => [
            'nl' => 'Op deze pagina loopt :term van :low tot :high. De onderkant en de bovenkant zijn meestal verschillende soorten producten en niet hetzelfde product tegen twee prijzen.',
            'fr' => 'Sur cette page, :term va de :low à :high. Le bas et le haut correspondent généralement à des produits différents, pas au même produit à deux prix.',
            'en' => 'On this page, :term ranges from :low to :high. The low end and the high end are usually different kinds of product rather than the same one at two prices.',
            'es' => 'En esta página, :term va de :low a :high. El extremo bajo y el alto suelen ser tipos de producto distintos y no el mismo a dos precios.',
        ],
    ],
    14 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 15,
        'conditions' => [
        ],
        'slot' => 'narrative.faq_where_q',
        'bodies' => [
            'nl' => 'Waar kan ik :term kopen?',
            'fr' => 'Où acheter :term ?',
            'en' => 'Where can I buy :term?',
            'es' => '¿Dónde puedo comprar :term?',
        ],
    ],
    15 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 16,
        'conditions' => [
        ],
        'slot' => 'narrative.faq_where_a',
        'bodies' => [
            'nl' => 'Bij de winkels die op elke kaart staan. Deze pagina bundelt :shops winkelaanbiedingen over de getoonde producten. Wij zijn een ontdekkingssite, geen winkel: elke link gaat naar de winkel die de aanbieding doet, en je koopt bij hen onder hun voorwaarden.',
            'fr' => 'Chez les boutiques indiquées sur chaque fiche, cette page réunit :shops annonces de boutiques sur les produits affichés. Nous sommes un site de découverte, pas un marchand : chaque lien mène à la boutique qui fait l’offre, et vous achetez chez elle, à ses conditions.',
            'en' => 'From the shops listed on each card. This page draws :shops shop listings across the products shown. We are a discovery site, not a retailer: every link goes to the shop making the offer, and you buy from them on their own terms.',
            'es' => 'En las tiendas que aparecen en cada ficha, esta página reúne :shops anuncios de tiendas sobre los productos mostrados. Somos un sitio de descubrimiento, no una tienda: cada enlace lleva a quien hace la oferta y compras allí, en sus condiciones.',
        ],
    ],
    16 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 17,
        'conditions' => [
        ],
        'slot' => 'narrative.faq_fresh_q',
        'bodies' => [
            'nl' => 'Hoe actueel zijn deze prijzen voor :term?',
            'fr' => 'Ces prix pour :term sont-ils à jour ?',
            'en' => 'How current are these :term prices?',
            'es' => '¿Están actualizados estos precios de :term?',
        ],
    ],
    17 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 18,
        'conditions' => [
        ],
        'slot' => 'narrative.faq_fresh_a',
        'bodies' => [
            'nl' => 'Feedprijzen worden twee keer per dag ververst en live bronnen worden bij het zoeken bevraagd, dus deze pagina laat vandaag zien en niet vorige week. Een prijs kan alsnog veranderen tussen het laden van de pagina en de winkel; de pagina van de winkel zelf is altijd doorslaggevend.',
            'fr' => 'Les prix des flux sont rafraîchis deux fois par jour et les sources en direct sont interrogées lors de la recherche : cette page reflète aujourd’hui, pas la semaine dernière. Un prix peut encore changer entre le chargement de la page et l’arrivée en boutique ; la page du marchand fait toujours foi.',
            'en' => 'Feed prices are refreshed twice a day and live sources are queried when you search, so this page reflects today rather than last week. A price can still change between loading the page and reaching the shop, and the shop\\x27s own page is always the final word.',
            'es' => 'Los precios de los feeds se actualizan dos veces al día y las fuentes en vivo se consultan al buscar, así que esta página refleja hoy y no la semana pasada. Un precio puede cambiar entre cargar la página y llegar a la tienda; la página de la tienda siempre manda.',
        ],
    ],
    18 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 19,
        'conditions' => [
        ],
        'slot' => 'narrative.related_heading',
        'bodies' => [
            'nl' => 'Verwante zoekopdrachten',
            'fr' => 'Recherches associées',
            'en' => 'Related searches',
            'es' => 'Búsquedas relacionadas',
        ],
    ],
    19 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 20,
        'conditions' => [
        ],
        'slot' => 'narrative.related_intro',
        'bodies' => [
            'nl' => 'Wat mensen hier verder zochten na :term.',
            'fr' => 'Ce que les gens ont cherché ici après :term.',
            'en' => 'Other things people looked for here after searching for :term.',
            'es' => 'Lo que la gente buscó aquí después de :term.',
        ],
    ],
    20 => [
        'page' => 'search',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 21,
        'conditions' => [
        ],
        'slot' => null,
        'bodies' => [
            'nl' => ':related_searches',
            'fr' => ':related_searches',
            'en' => ':related_searches',
            'es' => ':related_searches',
        ],
    ],
    21 => [
        'page' => 'search',
        'region' => 'empty_state',
        'kind' => 'paragraph',
        'position' => 1,
        'conditions' => [
        ],
        'slot' => 'search.empty_hint',
        'bodies' => [
            'nl' => 'Probeer een kortere zoekterm, of controleer de spelling.',
            'fr' => 'Essayez une recherche plus courte, ou vérifiez l\'orthographe.',
            'en' => 'Try a shorter search, or check the spelling.',
            'es' => 'Prueba con una búsqueda más corta o revisa la ortografía.',
        ],
    ],
    22 => [
        'page' => 'search',
        'region' => 'empty_state',
        'kind' => 'paragraph',
        'position' => 2,
        'conditions' => [
        ],
        'slot' => null,
        'bodies' => [
            'nl' => ':related_searches',
            'fr' => ':related_searches',
            'en' => ':related_searches',
            'es' => ':related_searches',
        ],
    ],
    23 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 1,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.about_heading',
        'bodies' => [
            'nl' => 'Over :brand',
            'fr' => 'À propos de :brand',
            'en' => 'About :brand',
            'es' => 'Sobre :brand',
        ],
    ],
    24 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 2,
        'conditions' => [
            0 => 'has_categories',
        ],
        'slot' => 'brand_narrative.about_1',
        'bodies' => [
            'nl' => 'In deze markt duikt :brand op in :categories. Dat is waar het merk hier voor staat, en dat is niet altijd wat de wereldwijde catalogus suggereert: een naam kan een begrip zijn in een categorie die het in een bepaald land nauwelijks verkoopt.',
            'fr' => 'Sur ce marché, :brand apparaît en :categories. C’est ce que la marque signifie ici, et pas toujours ce que laisse entendre son catalogue mondial : un nom peut être une référence dans une catégorie qu’il ne vend presque pas dans un pays donné.',
            'en' => 'In this market :brand appears in :categories. That is what the brand is for here, which is not always what its worldwide catalogue suggests: a name can be a household one in a category it barely sells in a given country.',
            'es' => 'En este mercado :brand aparece en :categories. Eso es lo que la marca significa aquí, que no siempre coincide con lo que sugiere su catálogo mundial: un nombre puede ser un referente en una categoría que apenas vende en un país concreto.',
        ],
    ],
    25 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 3,
        'conditions' => [
            0 => 'has_top_category',
        ],
        'slot' => 'brand_narrative.about_2',
        'bodies' => [
            'nl' => 'Het grootste deel is :category, en daar levert vergelijken het meeste op, want daar verkopen meerdere winkels hetzelfde model van :brand en zijn ze het oneens over de prijs.',
            'fr' => 'L’essentiel relève de :category, et c’est là que comparer vaut le plus la peine : plusieurs boutiques y vendent le même modèle :brand et ne s’accordent pas sur le prix.',
            'en' => 'The bulk of it is :category, and that is where comparing is most worth your time, because it is where several shops carry the same :brand model and disagree about the price.',
            'es' => 'La mayor parte es :category, y ahí es donde más compensa comparar: es donde varias tiendas venden el mismo modelo de :brand y no se ponen de acuerdo en el precio.',
        ],
    ],
    26 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 4,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.about_3',
        'bodies' => [
            'nl' => 'Alles op deze pagina is een product dat :brand hier echt verkoopt, verzameld uit de winkels die we volgen en niet van de site van :brand zelf. Eén kaart per product, niet per aanbieding, dus twee winkels met hetzelfde artikel worden één product met twee prijzen in plaats van twee resultaten.',
            'fr' => 'Tout ce qui figure sur cette page est un produit que :brand vend réellement ici, rassemblé depuis les boutiques que nous suivons et non depuis le site de :brand. Une fiche par produit et non par annonce : deux boutiques vendant la même chose donnent un produit à deux prix, pas deux résultats.',
            'en' => 'Everything on this page is a product :brand actually sells here, gathered from the shops we track rather than from :brand\'s own site. One card per product, not one per listing, so two shops selling the same thing appear as one product with two prices rather than as two results.',
            'es' => 'Todo lo que hay en esta página es un producto que :brand vende de verdad aquí, recogido de las tiendas que seguimos y no de la web de :brand. Una ficha por producto y no por anuncio, así que dos tiendas con el mismo artículo son un producto con dos precios, no dos resultados.',
        ],
    ],
    27 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 5,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.stocked_heading',
        'bodies' => [
            'nl' => 'Waar :brand verkocht wordt',
            'fr' => 'Où :brand est vendu',
            'en' => 'Where :brand is sold',
            'es' => 'Dónde se vende :brand',
        ],
    ],
    28 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 6,
        'conditions' => [
            0 => 'has_top_shop',
        ],
        'slot' => 'brand_narrative.stocked_1',
        'bodies' => [
            'nl' => ':shop heeft meer :brand dan elke andere winkel die we volgen, wat het een logisch beginpunt maakt en een slecht eindpunt: de goedkoopste aanbieding voor een bepaald product zit vaak ergens anders, en die staat hoe dan ook op de kaart.',
            'fr' => ':shop propose plus de :brand que toute autre boutique que nous suivons, ce qui en fait un bon point de départ et un mauvais point d’arrivée : l’offre la moins chère sur un produit donné se trouve souvent ailleurs, et elle figure de toute façon sur la fiche.',
            'en' => ':shop carries more :brand than any other shop we track, which makes it a sensible place to start and a poor place to stop: the cheapest offer on a given product is frequently somewhere else, and it is on the card either way.',
            'es' => ':shop tiene más :brand que cualquier otra tienda que sigamos, lo que la convierte en un buen punto de partida y en un mal punto final: la oferta más barata de un producto concreto suele estar en otra parte, y aparece en la ficha de todos modos.',
        ],
    ],
    29 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 7,
        'conditions' => [
            0 => 'multi_shop',
        ],
        'slot' => 'brand_narrative.stocked_2',
        'bodies' => [
            'nl' => ':comparable van de :shown getoonde producten van :brand liggen bij meer dan één winkel. Daar is de prijs een marktprijs en niet de mening van één winkel. Ligt iets maar bij één winkel, dan zeggen we dat, in plaats van een keuze te suggereren die er niet is.',
            'fr' => ':comparable des :shown produits :brand affichés ici sont vendus par plus d’une boutique. Ce sont ceux dont le prix est un prix de marché et non l’avis d’une seule enseigne. Quand un seul magasin vend un article, nous le disons plutôt que de suggérer un choix qui n’existe pas.',
            'en' => ':comparable of the :shown :brand products shown here are carried by more than one shop. Those are the ones where the price is a market price rather than one shop\'s opinion. Where only one shop stocks something we say so, rather than implying a choice you do not have.',
            'es' => ':comparable de los :shown productos de :brand que se muestran aquí los venden más de una tienda. En esos el precio es un precio de mercado y no la opinión de una sola tienda. Cuando algo lo vende una única tienda lo decimos, en vez de insinuar una elección que no existe.',
        ],
    ],
    30 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 8,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.choosing_heading',
        'bodies' => [
            'nl' => 'Een product van :brand kiezen',
            'fr' => 'Choisir un produit :brand',
            'en' => 'Choosing a :brand product',
            'es' => 'Elegir un producto de :brand',
        ],
    ],
    31 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 9,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.choosing_1',
        'bodies' => [
            'nl' => 'Kijk eerst naar het aantal aanbiedingen, dan pas naar de prijs. Een product van :brand dat bij vier winkels ligt heeft een prijs die je kunt vertrouwen; bij één winkel is er een prijs en niets om die tegen af te zetten. Alles hier is standaard op voorraad, want een prijs die je niet kunt afrekenen is geen aanbieding.',
            'fr' => 'Regardez le nombre d’offres avant le prix. Un produit :brand vendu par quatre boutiques a un prix fiable ; vendu par une seule, il a un prix et rien pour le vérifier. Tout est en stock par défaut, car un prix qu’on ne peut pas payer n’est pas une offre.',
            'en' => 'Look at the offer count before the price. A :brand product stocked by four shops has a price you can trust; one stocked by a single shop has a price and nothing to check it against. Everything here is in stock by default, because an unbuyable price is not an offer.',
            'es' => 'Mira el número de ofertas antes que el precio. Un producto de :brand que venden cuatro tiendas tiene un precio fiable; el que vende una sola tiene un precio y nada con qué contrastarlo. Todo está en stock por defecto, porque un precio que no puedes pagar no es una oferta.',
        ],
    ],
    32 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 10,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'brand_narrative.choosing_2',
        'bodies' => [
            'nl' => 'De prijzen lopen hier van :low tot :high. Dat is een reeks producten, geen reeks marges, dus sorteren op prijs laat zien waar het assortiment van :brand ligt, niet wie het goedkoopst is.',
            'fr' => 'Les prix vont ici de :low à :high. C’est une gamme de produits et non une gamme de marges : trier par prix montre où se situe la gamme :brand, pas qui est le moins cher.',
            'en' => 'Prices here run from :low to :high. That is a range of products, not a range of margins, so sorting by price tells you where :brand\'s range actually sits rather than who is cheapest.',
            'es' => 'Los precios van aquí de :low a :high. Es una gama de productos, no una gama de márgenes, así que ordenar por precio enseña dónde está la gama de :brand, no quién es más barato.',
        ],
    ],
    33 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 11,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.choosing_3',
        'bodies' => [
            'nl' => 'Elke productpagina heeft de volledige aanbiedingstabel en 90 dagen prijsgeschiedenis, zodat je ziet of vandaag een echt goed moment is om een bepaald product van :brand te kopen of gewoon een doordeweeks moment.',
            'fr' => 'Chaque fiche produit porte le tableau complet des offres et 90 jours d’historique de prix, de quoi voir si aujourd’hui est un bon moment pour acheter tel produit :brand ou un moment ordinaire.',
            'en' => 'Each product page carries the full offer table and 90 days of price history, so you can see whether today is a genuinely good moment to buy a particular :brand product or an ordinary one.',
            'es' => 'Cada ficha de producto lleva la tabla completa de ofertas y 90 días de histórico de precios, para ver si hoy es un momento realmente bueno para comprar un producto concreto de :brand o uno corriente.',
        ],
    ],
    34 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 12,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'brand_narrative.faq_price_q',
        'bodies' => [
            'nl' => 'Wat kosten :brand-producten?',
            'fr' => 'Combien coûtent les produits :brand ?',
            'en' => 'How much do :brand products cost?',
            'es' => '¿Cuánto cuestan los productos de :brand?',
        ],
    ],
    35 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 13,
        'conditions' => [
            0 => 'has_prices',
        ],
        'slot' => 'brand_narrative.faq_price_a',
        'bodies' => [
            'nl' => ':brand-producten lopen op deze pagina van :low tot :high. De onderkant en de bovenkant zijn meestal verschillende producten, niet hetzelfde product tegen twee prijzen.',
            'fr' => 'Les produits :brand de cette page vont de :low à :high. Le bas et le haut correspondent généralement à des produits différents, pas au même à deux prix.',
            'en' => ':brand products on this page range from :low to :high. The low and the high are usually different products rather than the same one at two prices.',
            'es' => 'Los productos de :brand en esta página van de :low a :high. El extremo bajo y el alto suelen ser productos distintos, no el mismo a dos precios.',
        ],
    ],
    36 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 14,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.faq_where_q',
        'bodies' => [
            'nl' => 'Welke winkels verkopen :brand?',
            'fr' => 'Quelles boutiques vendent :brand ?',
            'en' => 'Which shops sell :brand?',
            'es' => '¿Qué tiendas venden :brand?',
        ],
    ],
    37 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 15,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.faq_where_a',
        'bodies' => [
            'nl' => 'De winkels die op elke kaart staan. Deze pagina bundelt :shops winkelaanbiedingen over de getoonde :brand-producten. Wij tonen en verkopen niet: elke link gaat naar de winkel die de aanbieding doet.',
            'fr' => 'Celles nommées sur chaque fiche, cette page réunit :shops annonces de boutiques sur les produits :brand affichés. Nous présentons, nous ne vendons pas : chaque lien mène à la boutique qui fait l’offre.',
            'en' => 'The shops named on each card. This page draws :shops shop listings across the :brand products shown. We list rather than sell: every link goes to the shop making the offer.',
            'es' => 'Las que aparecen en cada ficha, esta página reúne :shops anuncios de tiendas sobre los productos de :brand mostrados. Mostramos, no vendemos: cada enlace lleva a la tienda que hace la oferta.',
        ],
    ],
    38 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 16,
        'conditions' => [
            0 => 'has_discount',
        ],
        'slot' => 'brand_narrative.faq_discount_q',
        'bodies' => [
            'nl' => 'Is :brand nu in de aanbieding?',
            'fr' => ':brand est-il en promotion en ce moment ?',
            'en' => 'Is :brand on offer right now?',
            'es' => '¿Hay ofertas de :brand ahora mismo?',
        ],
    ],
    39 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 17,
        'conditions' => [
            0 => 'has_discount',
        ],
        'slot' => 'brand_narrative.faq_discount_a',
        'bodies' => [
            'nl' => 'Ja, :reduced :brand-producten staan nu onder hun 30-daagse medianprijs, de grootste met :percent%. Dat is gemeten tegen onze eigen prijsgeschiedenis en niet tegen een doorgestreepte winkelprijs, dus het gaat om een echte beweging.',
            'fr' => 'Oui, :reduced produits :brand sont actuellement sous leur prix médian à 30 jours, le plus fort écart étant de :percent%. C’est mesuré sur notre propre historique de prix et non sur un prix barré : il s’agit donc d’un mouvement réel.',
            'en' => 'Yes, :reduced :brand products are currently below their 30-day median price, the largest by :percent%. That is measured against our own price history rather than a shop\\x27s crossed-out figure, so it reflects a real movement rather than a claimed one.',
            'es' => 'Sí, :reduced productos de :brand están por debajo de su precio mediano de 30 días, el mayor un :percent%. Se mide contra nuestro propio histórico y no contra un precio tachado, así que refleja un movimiento real.',
        ],
    ],
    40 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'heading',
        'position' => 18,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.related_heading',
        'bodies' => [
            'nl' => 'Verwante zoekopdrachten',
            'fr' => 'Recherches associées',
            'en' => 'Related searches',
            'es' => 'Búsquedas relacionadas',
        ],
    ],
    41 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 19,
        'conditions' => [
        ],
        'slot' => 'brand_narrative.related_intro',
        'bodies' => [
            'nl' => 'Wat mensen hier rond :brand zochten.',
            'fr' => 'Ce que les gens ont cherché ici autour de :brand.',
            'en' => 'What people looked for here around :brand.',
            'es' => 'Lo que la gente buscó aquí en torno a :brand.',
        ],
    ],
    42 => [
        'page' => 'brand',
        'region' => 'below_grid',
        'kind' => 'paragraph',
        'position' => 20,
        'conditions' => [
        ],
        'slot' => null,
        'bodies' => [
            'nl' => ':related_searches',
            'fr' => ':related_searches',
            'en' => ':related_searches',
            'es' => ':related_searches',
        ],
    ],
    43 => [
        'page' => 'brand',
        'region' => 'empty_state',
        'kind' => 'paragraph',
        'position' => 1,
        'conditions' => [
        ],
        'slot' => 'brand.empty_hint',
        'bodies' => [
            'nl' => 'Prijzen en voorraad worden twee keer per dag opnieuw gecontroleerd, dus deze pagina verandert.',
            'fr' => 'Les prix et les stocks sont revérifiés deux fois par jour : cette page change.',
            'en' => 'Prices and stock are re-checked twice a day, so this page changes.',
            'es' => 'Los precios y el stock se revisan dos veces al día, así que esta página cambia.',
        ],
    ],
    44 => [
        'page' => 'brand',
        'region' => 'empty_state',
        'kind' => 'paragraph',
        'position' => 2,
        'conditions' => [
        ],
        'slot' => null,
        'bodies' => [
            'nl' => ':related_searches',
            'fr' => ':related_searches',
            'en' => ':related_searches',
            'es' => ':related_searches',
        ],
    ],
];
