<?php

declare(strict_types=1);

/*
 * What goes with what: the "next step" after a gift.
 *
 * A moka pot last year makes coffee beans or a grinder a good present this
 * year. Each family below names the thing that was given (`triggers`) and
 * what is used with it or used up by it (`goes_with`), in the four languages
 * the catalogue's titles are written in. Read by App\Services\Gift\NextSteps
 * to fetch candidates and by NextStepScorer to judge them; see
 * docs/features/gift-history.md.
 *
 * ## How a word matches
 *
 * Case and accents are ignored, and a word matches at the START of a word in
 * a title, so "grinder" also finds "grinders" and "koffiemolen" finds
 * "koffiemolens". It does not have to end there, which is why short words are
 * dangerous here: "wol" (wool) would find "wolf", "stof" (fabric) would find
 * "stofzuiger" (vacuum cleaner), "miel" (honey) would find "Miele", "dés"
 * (dice) would find the French word "des", "mando" (a controller) would find
 * "mandoline" and "recharge" would find "rechargeable". Those are left out on purpose;
 * check a new short word against the catalogue before adding it.
 *
 * ## What does not belong here
 *
 * The same brand is its own reason and needs no family (a LEGO set after a
 * LEGO set). Products people keep together on lists are found from
 * `product_links`. This file is for knowledge neither of those can show: that
 * a razor needs blades.
 */

return [
    'coffee' => [
        'triggers' => [
            'moka', 'espresso machine', 'espressomachine', 'espresso maker', 'coffee maker', 'koffiezetapparaat',
            'koffiemachine', 'machine à café', 'machine expresso', 'cafetera', 'cafetière', 'french press',
            'percolator', 'aeropress', 'pour over', 'chemex', 'nespresso', 'dolce gusto', 'senseo',
        ],
        'goes_with' => [
            'coffee beans', 'koffiebonen', 'café en grains', 'café en grano', 'granos de café', 'grinder',
            'koffiemolen', 'moulin à café', 'molinillo', 'milk frother', 'melkopschuimer', 'mousseur à lait',
            'espumador', 'espresso cups', 'espressokopjes', 'tasses à espresso', 'tazas de espresso', 'capsules',
            'cápsulas', 'koffiecups', 'koffiepads',
        ],
    ],

    'tea' => [
        'triggers' => ['teapot', 'theepot', 'théière', 'tetera', 'tea infuser', 'theezeef', 'infuseur', 'infusor'],
        'goes_with' => [
            'loose leaf tea', 'losse thee', 'thé en vrac', 'té a granel', 'tea tin', 'theeblik', 'tea cups',
            'theekopjes', 'tasses à thé', 'tazas de té', 'honey', 'honing',
        ],
    ],

    'wine' => [
        'triggers' => [
            'wine glass', 'wijnglas', 'wijnglazen', 'verres à vin', 'copas de vino', 'decanter', 'karaf',
            'carafe', 'decantador', 'wine rack', 'wijnrek',
        ],
        'goes_with' => [
            'corkscrew', 'kurkentrekker', 'tire-bouchon', 'sacacorchos', 'wine stopper', 'wijnstopper',
            'wine cooler', 'wijnkoeler', 'wine aerator', 'beluchter', 'aérateur', 'wine glass', 'wijnglazen',
            'verres à vin', 'copas de vino', 'decanter', 'decantador',
        ],
    ],

    'barbecue' => [
        'triggers' => ['barbecue', 'bbq', 'kamado', 'plancha', 'parrilla', 'barbacoa'],
        'goes_with' => [
            'grill tongs', 'grilltang', 'pinces barbecue', 'pinzas', 'meat thermometer', 'vleesthermometer',
            'thermomètre', 'termómetro', 'smoking chips', 'rookhout', 'rookchips', 'copeaux de fumage',
            'astillas', 'bbq sauce', 'barbecuesaus', 'grill brush', 'grillborstel', 'barbecue cookbook',
            'barbecueboek', 'apron', 'schort', 'tablier', 'delantal',
        ],
    ],

    'cooking' => [
        'triggers' => [
            'dutch oven', 'braadpan', 'cocotte', 'cast iron', 'gietijzer', 'hierro fundido', 'wok',
            "chef's knife", 'chef knife', 'koksmes', 'messenset', 'couteau', 'cuchillo',
        ],
        'goes_with' => [
            'cookbook', 'kookboek', 'livre de cuisine', 'libro de cocina', 'knife sharpener', 'messenslijper',
            'slijpsteen', 'aiguiseur', 'afilador', 'cutting board', 'snijplank', 'planche à découper',
            'tabla de cortar', 'apron', 'keukenschort', 'tablier', 'delantal', 'spice', 'kruiden', 'épices',
            'especias',
        ],
    ],

    'baking' => [
        'triggers' => ['stand mixer', 'keukenmachine', 'robot pâtissier', 'batidora', 'bakvorm', 'moule à gâteau', 'kitchenaid'],
        'goes_with' => [
            'baking book', 'bakboek', 'livre de pâtisserie', 'libro de repostería', 'piping', 'spuitzak',
            'poche à douille', 'manga pastelera', 'cake stand', 'taartplateau', 'baking mat', 'bakmat',
            'tapis de cuisson',
        ],
    ],

    'gardening' => [
        'triggers' => [
            'garden', 'tuingereedschap', 'jardinage', 'jardín', 'plant pot', 'bloempot', 'pot de fleurs',
            'maceta', 'greenhouse', 'kweekkas', 'invernadero',
        ],
        'goes_with' => [
            'gardening gloves', 'tuinhandschoenen', 'gants de jardinage', 'guantes de jardinería', 'seeds',
            'zaden', 'graines', 'semillas', 'pruning shears', 'snoeischaar', 'sécateur', 'tijeras de podar',
            'plant food', 'plantenvoeding', 'engrais', 'fertilizante', 'watering can', 'gieter', 'arrosoir',
            'regadera', 'knielkussen',
        ],
    ],

    'photography' => [
        'triggers' => [
            'camera', 'appareil photo', 'cámara', 'fotocamera', 'systeemcamera', 'spiegelreflex', 'mirrorless',
            'instax', 'polaroid',
        ],
        'goes_with' => [
            'memory card', 'geheugenkaart', 'carte mémoire', 'tarjeta de memoria', 'sd card', 'sd-kaart',
            'carte sd', 'tarjeta sd', 'tripod', 'statief', 'trépied', 'trípode', 'camera bag', 'cameratas',
            'sac photo', 'instax film', 'polaroid film', 'fotopapier',
        ],
    ],

    'gaming' => [
        'triggers' => ['playstation', 'ps5', 'xbox', 'nintendo switch', 'spelcomputer', 'game console', 'spelconsole'],
        'goes_with' => [
            'controller', 'manette', 'mando inalámbrico', 'gamepad', 'gaming headset', 'charging station', 'oplaadstation',
            'station de charge', 'estación de carga',
        ],
    ],

    'reading' => [
        'triggers' => ['e-reader', 'ereader', 'kindle', 'kobo', 'liseuse', 'lector de libros', 'tolino', 'pocketbook'],
        'goes_with' => [
            'e-reader cover', 'e-reader hoes', 'sleepcover', 'housse', 'funda para', 'reading light', 'leeslampje',
            'lampe de lecture', 'luz de lectura', 'book light', 'boeklampje',
        ],
    ],

    'listening' => [
        'triggers' => [
            'headphones', 'koptelefoon', 'casque audio', 'auriculares', 'earbuds', 'oordopjes', 'turntable',
            'platenspeler', 'platine vinyle', 'tocadiscos', 'record player',
        ],
        'goes_with' => [
            'headphone stand', 'koptelefoonstandaard', 'support casque', 'carrying case', 'opbergcase',
            'étui', 'estuche', 'vinyl', 'record cleaning', 'platenborstel', 'brosse vinyle',
        ],
    ],

    'running' => [
        'triggers' => ['running shoes', 'hardloopschoenen', 'chaussures de running', 'zapatillas de running'],
        'goes_with' => [
            'running socks', 'hardloopsokken', 'chaussettes de running', 'calcetines de running', 'sports watch',
            'sporthorloge', 'montre de sport', 'reloj deportivo', 'running belt', 'hardloopriem', 'water bottle',
            'drinkfles', 'bidon', 'gourde',
        ],
    ],

    'yoga' => [
        'triggers' => ['yoga mat', 'yogamat', 'tapis de yoga', 'esterilla de yoga'],
        'goes_with' => [
            'yoga block', 'yogablok', 'brique de yoga', 'bloque de yoga', 'yoga strap', 'yogariem',
            'meditation cushion', 'meditatiekussen', 'coussin de méditation', 'cojín de meditación', 'yoga bag',
            'yogatas',
        ],
    ],

    'crafts' => [
        'triggers' => [
            'knitting', 'breien', 'tricot', 'tejer', 'crochet', 'haken', 'ganchillo', 'sewing machine',
            'naaimachine', 'machine à coudre', 'máquina de coser',
        ],
        'goes_with' => [
            'yarn', 'garen', 'breiwol', 'laine', 'ovillo', 'knitting needles', 'breinaalden',
            'aiguilles à tricoter', 'agujas de tejer', 'sewing kit', 'naaiset', 'fabric', 'tissu',
        ],
    ],

    'home_fragrance' => [
        'triggers' => ['diffuser', 'geurverspreider', 'diffuseur', 'difusor'],
        'goes_with' => [
            'essential oil', 'etherische olie', 'huile essentielle', 'aceite esencial', 'fragrance oil',
            'geurolie', 'refill', 'navulling', 'recharge de', 'recambio',
        ],
    ],

    'shaving' => [
        'triggers' => [
            'safety razor', 'scheermes', 'rasoir', 'maquinilla', 'shaving brush', 'scheerkwast', 'blaireau',
            'brocha de afeitar', 'beard trimmer', 'baardtrimmer', 'tondeuse à barbe', 'recortadora de barba',
        ],
        'goes_with' => [
            'razor blades', 'scheermesjes', 'lames de rasoir', 'cuchillas', 'shaving soap', 'scheerzeep',
            'savon à barbe', 'jabón de afeitar', 'beard oil', 'baardolie', 'huile à barbe', 'aceite para barba',
            'shaving cream', 'scheercrème', 'crème à raser', 'crema de afeitar', 'beard balm', 'baardbalsem',
        ],
    ],

    'board_games' => [
        'triggers' => ['board game', 'bordspel', 'jeu de société', 'juego de mesa'],
        'goes_with' => [
            'expansion', 'uitbreiding', 'expansión', 'card sleeves', 'kaarthoesjes', 'protège-cartes',
            'fundas para cartas', 'dobbelstenen', 'dados',
        ],
    ],
];
