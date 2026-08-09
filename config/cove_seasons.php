<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Seasonal Cove topics
|--------------------------------------------------------------------------
|
| Coves are evergreen — a themed reference page that earns its traffic over
| years. Most of them come from `TopicMiner`, which reads 30 days of our own
| searches, and that is the right primary signal: it is real demand, measured
| here, and no competitor has it.
|
| It has one structural blind spot. **It cannot know about a season before the
| season arrives.** Barbecue searches peak in June; a miner reading June's log
| commissions the barbecue Cove in July and it first earns real traffic the
| following May. Halloween is worse — the whole window is three weeks, so by the
| time the demand shows in the log the demand is finished.
|
| These are the topics we know are coming. Each one opens a window *before* its
| season so the Cove is written, indexed and already ranking when people start
| looking. `TopicMiner::ripest()` prefers an in-season topic over a
| higher-scoring evergreen one, for exactly that reason.
|
| HOW THIS DIFFERS FROM config/observances.php
|
| Observances are Daily Coves: one day, dated, gone tomorrow. These are Coves —
| evergreen pages that happen to be *commissioned* seasonally. The distinction
| matters in the copy: a Cove must never say "today", because it will be read in
| February. The window controls when it is written, not what it claims.
|
| `topic` is the cluster key `TopicMiner` uses, so a seasonal topic and a mined
| one can collide on the same row and the seasonal window simply gets attached to
| the topic people are already searching for — which is the best possible
| outcome and needs no special handling.
|
| `queries` are the member queries the builder retrieves from, keyed by
| LANGUAGE — and they have to be, because a query here is matched against
| product titles and a title is written in the market's language. Left in Dutch,
| "tent" and "slaapzak" found nothing in the French catalogue, so every seasonal
| topic in be-fr reported zero products and the whole feature was silently inert
| outside the two Dutch markets. In admin that reads as "no demand" rather than
| "wrong words", which is the worst kind of failure: plausible.
|
| A flat list is still accepted and applies to every market. A market with no
| entry falls back to English, which matches the loan words ("barbecue",
| "camping") and misses the rest — a thinner topic rather than an absent one.
*/

return [

    /*
     * Spring.
     */
    [
        'topic' => 'schoonmaken',
        'queries' => [
            'nl' => ['schoonmaakset', 'raamwisser', 'stoomreiniger', 'robotstofzuiger'],
            'fr' => ['kit de nettoyage', 'raclette à vitres', 'nettoyeur vapeur', 'aspirateur robot'],
            'en' => ['cleaning set', 'window vacuum', 'steam cleaner', 'robot vacuum'],
            'es' => ['kit de limpieza', 'limpiacristales', 'limpiador a vapor', 'robot aspirador'],
        ],
        // Opens in February: the searches start with the first mild weekend, and
        // that is three weeks before anyone expects it.
        'window' => ['from' => '02-10', 'to' => '05-15'],
    ],
    [
        'topic' => 'hardlopen',
        'queries' => [
            'nl' => ['hardloopschoenen', 'sporthorloge', 'hardloopjack', 'hardlooprugzak'],
            'fr' => ['chaussures de running', 'montre de sport', 'veste de running', 'ceinture de running'],
            'en' => ['running shoes', 'sports watch', 'running jacket', 'running belt'],
            'es' => ['zapatillas de running', 'reloj deportivo', 'chaqueta de running', 'cinturón de running'],
        ],
        // The single biggest new-runner month is April, and the decision is made
        // in March.
        'window' => ['from' => '02-15', 'to' => '05-31'],
    ],
    [
        'topic' => 'tuinieren',
        'queries' => [
            'nl' => ['tuingereedschap', 'snoeischaar', 'plantenbak', 'kweekkas'],
            'fr' => ['outils de jardin', 'sécateur', 'jardinière', 'serre'],
            'en' => ['garden tools', 'pruning shears', 'planter', 'greenhouse'],
            'es' => ['herramientas de jardín', 'tijeras de podar', 'jardinera', 'invernadero'],
        ],
        'window' => ['from' => '02-20', 'to' => '06-30'],
    ],
    [
        'topic' => 'fietsen',
        'queries' => [
            'nl' => ['fietstas', 'fietsslot', 'fietscomputer', 'fietshelm'],
            'fr' => ['sacoche vélo', 'antivol vélo', 'compteur vélo', 'casque vélo'],
            'en' => ['bike bag', 'bike lock', 'bike computer', 'bike helmet'],
            'es' => ['alforja bici', 'candado bici', 'ciclocomputador', 'casco bici'],
        ],
        'window' => ['from' => '03-01', 'to' => '07-31'],
    ],

    /*
     * Summer.
     *
     * The cluster that sells hardest and rots fastest: everything here is bought
     * in a fortnight and the fortnight moves with the weather.
     */
    [
        'topic' => 'barbecue',
        'queries' => [
            'nl' => ['gasbarbecue', 'houtskoolbarbecue', 'kamado', 'vleesthermometer'],
            'fr' => ['barbecue à gaz', 'barbecue charbon', 'kamado', 'thermomètre à viande'],
            'en' => ['gas barbecue', 'charcoal barbecue', 'kamado', 'meat thermometer'],
            'es' => ['barbacoa de gas', 'barbacoa de carbón', 'kamado', 'termómetro de carne'],
        ],
        'window' => ['from' => '03-15', 'to' => '07-31'],
    ],
    [
        'topic' => 'zwembad',
        'queries' => [
            'nl' => ['opblaasbaar zwembad', 'zwembadpomp', 'zwembadtrap', 'zwembadstofzuiger'],
            'fr' => ['piscine gonflable', 'pompe piscine', 'échelle piscine', 'aspirateur piscine'],
            'en' => ['inflatable pool', 'pool pump', 'pool ladder', 'pool vacuum'],
            'es' => ['piscina hinchable', 'bomba piscina', 'escalera piscina', 'limpiafondos'],
        ],
        'window' => ['from' => '04-01', 'to' => '07-31'],
    ],
    [
        'topic' => 'zonbescherming',
        'queries' => [
            'nl' => ['parasol', 'zonnescherm', 'schaduwdoek', 'zonnehoed'],
            'fr' => ['parasol', 'store banne', 'voile d\'ombrage', 'chapeau de soleil'],
            'en' => ['parasol', 'sun awning', 'shade sail', 'sun hat'],
            'es' => ['sombrilla', 'toldo', 'vela de sombra', 'sombrero de sol'],
        ],
        'window' => ['from' => '04-15', 'to' => '08-15'],
    ],
    [
        'topic' => 'tuinmeubelen',
        'queries' => [
            'nl' => ['tuinset', 'loungeset', 'hangmat', 'tuinkussens'],
            'fr' => ['salon de jardin', 'salon lounge', 'hamac', 'coussins de jardin'],
            'en' => ['garden set', 'lounge set', 'hammock', 'garden cushions'],
            'es' => ['conjunto de jardín', 'set lounge', 'hamaca', 'cojines de jardín'],
        ],
        'window' => ['from' => '03-15', 'to' => '07-15'],
    ],
    [
        'topic' => 'kamperen',
        'queries' => [
            'nl' => ['tent', 'slaapzak', 'campingstoel', 'kampeerkooktoestel'],
            'fr' => ['tente', 'sac de couchage', 'chaise de camping', 'réchaud camping'],
            'en' => ['tent', 'sleeping bag', 'camping chair', 'camping stove'],
            'es' => ['tienda de campaña', 'saco de dormir', 'silla de camping', 'hornillo camping'],
        ],
        'window' => ['from' => '03-15', 'to' => '08-15'],
    ],
    [
        'topic' => 'ventilatie',
        'queries' => [
            'nl' => ['ventilator', 'airco', 'verduisterende gordijnen', 'luchtkoeler'],
            'fr' => ['ventilateur', 'climatiseur', 'rideaux occultants', 'rafraîchisseur d\'air'],
            'en' => ['fan', 'air conditioner', 'blackout curtains', 'air cooler'],
            'es' => ['ventilador', 'aire acondicionado', 'cortinas opacas', 'climatizador evaporativo'],
        ],
        // Panic-bought in the first heatwave. Written before it, this is the page
        // that is already ranking when the heatwave arrives.
        'window' => ['from' => '04-20', 'to' => '08-31'],
    ],
    [
        'topic' => 'strand',
        'queries' => [
            'nl' => ['strandlaken', 'strandtas', 'snorkelset', 'bodyboard'],
            'fr' => ['serviette de plage', 'sac de plage', 'kit de snorkeling', 'bodyboard'],
            'en' => ['beach towel', 'beach bag', 'snorkel set', 'bodyboard'],
            'es' => ['toalla de playa', 'bolsa de playa', 'set de snorkel', 'bodyboard'],
        ],
        'window' => ['from' => '04-15', 'to' => '08-31'],
    ],

    /*
     * Autumn.
     */
    [
        'topic' => 'schoolspullen',
        'queries' => [
            'nl' => ['rugzak', 'broodtrommel', 'schoolagenda', 'etui'],
            'fr' => ['cartable', 'boîte à goûter', 'agenda scolaire', 'trousse'],
            'en' => ['backpack', 'lunch box', 'school planner', 'pencil case'],
            'es' => ['mochila', 'fiambrera', 'agenda escolar', 'estuche'],
        ],
        // Written in June, ranking in August. The demand curve is a cliff on
        // 1 September.
        'window' => ['from' => '06-15', 'to' => '09-10'],
    ],
    [
        'topic' => 'halloween',
        'queries' => [
            'nl' => ['halloween verkleedkleding', 'halloween decoratie', 'pompoen snijset'],
            'fr' => ['déguisement halloween', 'décoration halloween', 'kit sculpture citrouille'],
            'en' => ['halloween costume', 'halloween decoration', 'pumpkin carving kit'],
            'es' => ['disfraz halloween', 'decoración halloween', 'kit tallar calabaza'],
        ],
        // Deliberately long: three weeks of demand needs three months of
        // indexing to arrive in time.
        'window' => ['from' => '08-01', 'to' => '10-31'],
    ],
    [
        'topic' => 'luchtkwaliteit',
        'queries' => [
            'nl' => ['luchtreiniger', 'luchtbevochtiger', 'ontvochtiger', 'hygrometer'],
            'fr' => ['purificateur d\'air', 'humidificateur', 'déshumidificateur', 'hygromètre'],
            'en' => ['air purifier', 'humidifier', 'dehumidifier', 'hygrometer'],
            'es' => ['purificador de aire', 'humidificador', 'deshumidificador', 'higrómetro'],
        ],
        // Windows close, and the searches start.
        'window' => ['from' => '08-15', 'to' => '12-31'],
    ],
    [
        'topic' => 'verlichting',
        'queries' => [
            'nl' => ['daglichtlamp', 'lichtsnoer', 'tafellamp', 'sfeerverlichting'],
            'fr' => ['lampe de luminothérapie', 'guirlande lumineuse', 'lampe de table', 'éclairage d\'ambiance'],
            'en' => ['daylight lamp', 'string lights', 'table lamp', 'mood lighting'],
            'es' => ['lámpara de luz diurna', 'guirnalda de luces', 'lámpara de mesa', 'iluminación ambiental'],
        ],
        'window' => ['from' => '08-20', 'to' => '01-31'],
    ],

    /*
     * Winter.
     */
    [
        'topic' => 'wintersport',
        'queries' => [
            'nl' => ['skibril', 'skihelm', 'thermokleding', 'skihandschoenen'],
            'fr' => ['masque de ski', 'casque de ski', 'sous-vêtements thermiques', 'gants de ski'],
            'en' => ['ski goggles', 'ski helmet', 'thermal underwear', 'ski gloves'],
            'es' => ['gafas de esquí', 'casco de esquí', 'ropa térmica', 'guantes de esquí'],
        ],
        // Bought in November and December for a January trip.
        'window' => ['from' => '09-15', 'to' => '02-15'],
    ],
    [
        'topic' => 'cadeaus',
        'queries' => [
            'nl' => ['cadeau', 'kerstcadeau', 'cadeauverpakking'],
            'fr' => ['cadeau', 'cadeau de noël', 'emballage cadeau'],
            'en' => ['gift', 'christmas gift', 'gift wrap'],
            'es' => ['regalo', 'regalo de navidad', 'papel de regalo'],
        ],
        'window' => ['from' => '09-01', 'to' => '12-23'],
    ],
    [
        'topic' => 'sinterklaas',
        'queries' => [
            'nl' => ['speelgoed', 'bouwset', 'strooigoed', 'sinterklaascadeau'],
            'fr' => ['jouet', 'jeu de construction', 'cadeau saint-nicolas'],
            'en' => ['toy', 'building set', 'gift'],
            'es' => ['juguete', 'set de construcción', 'regalo'],
        ],
        'markets' => ['be-nl', 'nl-nl'],
        'window' => ['from' => '09-15', 'to' => '12-05'],
    ],
    [
        'topic' => 'kerstverlichting',
        'queries' => [
            'nl' => ['kerstboom', 'kerstverlichting', 'kerstversiering'],
            'fr' => ['sapin de noël', 'guirlande de noël', 'décoration de noël'],
            'en' => ['christmas tree', 'christmas lights', 'christmas decoration'],
            'es' => ['árbol de navidad', 'luces de navidad', 'decoración navideña'],
        ],
        'window' => ['from' => '09-20', 'to' => '12-24'],
    ],
    [
        'topic' => 'fitness',
        'queries' => [
            'nl' => ['hometrainer', 'halterset', 'roeitrainer', 'weerstandsbanden'],
            'fr' => ['vélo d\'appartement', 'set d\'haltères', 'rameur', 'bandes de résistance'],
            'en' => ['exercise bike', 'dumbbell set', 'rowing machine', 'resistance bands'],
            'es' => ['bicicleta estática', 'set de mancuernas', 'máquina de remo', 'bandas elásticas'],
        ],
        // January, and the searches begin the day after Christmas.
        'window' => ['from' => '11-15', 'to' => '02-28'],
    ],

    /*
     * Occasion-driven, which is not the same as seasonal.
     */
    [
        'topic' => 'paascadeau',
        'queries' => [
            'nl' => ['paasdecoratie', 'chocolade eieren', 'paasmand'],
            'fr' => ['décoration de pâques', 'oeufs en chocolat', 'panier de pâques'],
            'en' => ['easter decoration', 'chocolate eggs', 'easter basket'],
            'es' => ['decoración de pascua', 'huevos de chocolate', 'cesta de pascua'],
        ],
        // Easter moves and this window does not, because it is wide enough to
        // contain every date Easter can fall on. Computing the actual date would
        // be more precise and buy nothing: the topic is written months earlier.
        'window' => ['from' => '02-01', 'to' => '04-25'],
    ],
    [
        'topic' => 'moederdagcadeau',
        'queries' => [
            'nl' => ['moederdag cadeau', 'sieraden', 'geurkaars'],
            'fr' => ['cadeau fête des mères', 'bijoux', 'bougie parfumée'],
            'en' => ['mothers day gift', 'jewellery', 'scented candle'],
            'es' => ['regalo día de la madre', 'joyas', 'vela aromática'],
        ],
        'window' => ['from' => '03-15', 'to' => '05-31'],
    ],
    [
        'topic' => 'vaderdagcadeau',
        'queries' => [
            'nl' => ['vaderdag cadeau', 'multitool', 'gereedschapskoffer'],
            'fr' => ['cadeau fête des pères', 'multitool', 'caisse à outils'],
            'en' => ['fathers day gift', 'multitool', 'tool case'],
            'es' => ['regalo día del padre', 'multiherramienta', 'caja de herramientas'],
        ],
        'window' => ['from' => '04-15', 'to' => '06-30'],
    ],
    [
        'topic' => 'valentijnscadeau',
        'queries' => [
            'nl' => ['valentijn cadeau', 'sieraden', 'parfum'],
            'fr' => ['cadeau saint-valentin', 'bijoux', 'parfum'],
            'en' => ['valentines gift', 'jewellery', 'perfume'],
            'es' => ['regalo san valentín', 'joyas', 'perfume'],
        ],
        'window' => ['from' => '12-27', 'to' => '02-14'],
    ],
];
