<?php

declare(strict_types=1);

/*
 * Words that say what a product is for, read from its title and category.
 *
 * Used by App\Services\Gift\InterestGuesser, for This or that (2026-09-26).
 * Of production's roughly 150,000 giftable products per market, some 700 carry
 * an interest tag; the tool learned almost nothing from choices between the
 * rest. A word in a product's own title or category is weaker evidence than a
 * tag, and is weighted so (TasteCard), but it is on every product.
 *
 * One list per interest, every language together: categories arrive in the
 * feed's language and eBay's in English, German or Italian, whatever the
 * market ("Portable Audio & Headphones" on be-nl).
 *
 * How a word matches (InterestGuesser::matches):
 * - text is lowercased and accents are dropped ("café" -> "cafe");
 * - a word of five letters or more matches at the START of a word, so
 *   "koffie" finds "koffiemolen" and "koffie-pads" but not "espressokoffie";
 * - a shorter word must be the whole word, so "pan" finds "pan 28 cm" and
 *   not "pantalon", "spaniel" or "panda";
 * - a word written with a leading "=" must be the whole word whatever its
 *   length: "=filter" leaves out a filter but not a filterkoffie machine;
 * - punctuation counts as a space on both sides, so "t-shirt" is "t shirt"
 *   and "cars, trucks" is "cars trucks".
 * Write words as they begin, singular, without accents.
 *
 * 'not_gifts' are what a person does not unwrap: parts, refills, cases,
 * cables and household supplies. A match there keeps the product out of the
 * pairs entirely, whatever else it matches.
 */

return [
    'interests' => [
        'cooking' => ['koken', 'kookboek', 'koksmes', 'keukenmes', 'messenset', 'pannenset', 'pan', 'wok', 'braadpan', 'snijplank', 'keukenmachine', 'foodprocessor', 'blender', 'airfryer', 'heteluchtfriteuse', 'kruiden', 'kitchen', 'cookware', 'cookbook', 'cuisine', 'casserole', 'couteau', 'poele', 'robot culinaire', 'kochbuch', 'pfanne', 'cucina', 'barbecue', 'bbq', 'grill', 'pizzaoven', 'sous vide', 'small kitchen appliances'],
        'coffee' => ['koffie', 'espresso', 'cappuccino', 'melkopschuimer', 'melkschuim', 'french press', 'aeropress', 'nespresso', 'dolce gusto', 'senseo', 'coffee', 'cafetiere', 'moka', 'percolator', 'kaffee', 'barista', 'koffiemolen', 'grinder'],
        'photography' => ['camera', 'fotocamera', 'compactcamera', 'systeemcamera', 'objectief', 'lens', 'lenses', 'statief', 'tripod', 'cameratas', 'instantcamera', 'polaroid', 'instax', 'fotolijst', 'fotoprinter', 'appareil photo', 'objectif', 'kamera', 'fotocamere', 'gopro', 'action cam', 'drone', 'drohne'],
        'music' => ['koptelefoon', 'hoofdtelefoon', 'headphone', 'oortjes', 'earbuds', 'oordopjes voor muziek', 'speaker', 'luidspreker', 'soundbar', 'platenspeler', 'turntable', 'vinyl', 'lp', 'cd', 'muziek', 'music', 'musique', 'gitaar', 'guitar', 'guitare', 'ukelele', 'ukulele', 'piano', 'keyboard piano', 'drumstel', 'microfoon', 'microphone', 'kopfhorer', 'lautsprecher', 'cuffie', 'casque audio', 'enceinte', 'hifi', 'home audio', 'pro audio', 'portable audio'],
        'gaming' => ['videogame', 'video game', 'game headset', 'gaming', 'controller', 'gamepad', 'playstation', 'ps5', 'ps4', 'xbox', 'nintendo', 'switch', 'console', 'jeu video', 'manette', 'videospiel', 'videogioco', 'steam deck', 'racestuur'],
        'reading' => ['boek', 'book', 'livre', 'buch', 'libro', 'roman', 'e-reader', 'ereader', 'kobo', 'kindle', 'leeslamp', 'boekensteun', 'boekenlegger', 'strip', 'stripboek', 'bande dessinee', 'manga', 'comic', 'stripverhaal'],
        'fitness' => ['fitness', 'dumbbell', 'halter', 'kettlebell', 'yogamat', 'weerstandsband', 'foam roller', 'sporthorloge', 'activity tracker', 'hometrainer', 'loopband', 'roeitrainer', 'gym', 'musculation', 'krachttraining', 'sporttas', 'bidon'],
        'outdoors' => ['wandel', 'hiking', 'rugzak', 'backpack', 'sac a dos', 'rucksack', 'tent', 'kamperen', 'camping', 'kampeer', 'campingkooktoestel', 'slaapzak', 'hoofdlamp', 'zaklamp', 'verrekijker', 'zakmes', 'thermosfles', 'outdoor', 'randonnee', 'koeler', 'koelbox', 'hangmat'],
        'travel' => ['reis', 'reiskoffer', 'koffer', 'handbagage', 'travel', 'suitcase', 'valise', 'voyage', 'reisadapter', 'nekkussen', 'paspoort', 'toilettas', 'reisetasche', 'weekendtas'],
        'gardening' => ['tuin', 'garden', 'jardin', 'garten', 'giardino', 'snoeischaar', 'plantenbak', 'bloempot', 'gieter', 'kweekkas', 'zaden', 'moestuin', 'tuingereedschap', 'grasmaaier', 'plant', 'planten', 'vogelhuisje', 'insectenhotel'],
        'diy' => ['gereedschap', 'tool', 'outil', 'werkzeug', 'boormachine', 'accuboor', 'schroevendraaier', 'waterpas', 'multitool', 'zaag', 'schuurmachine', 'klus', 'werkbank', 'bosch professional', 'makita', 'dewalt'],
        'beauty' => ['parfum', 'eau de parfum', 'eau de toilette', 'perfume', 'make-up', 'makeup', 'mascara', 'lippenstift', 'nagellak', 'huidverzorging', 'gezichtsverzorging', 'skincare', 'serum', 'haardroger', 'fohn', 'stijltang', 'krultang', 'haarstyler', 'airstyle', 'airwrap', 'scheerapparaat', 'epilator', 'beauty', 'maquillage', 'soin', 'cosmetica'],
        'fashion' => ['t-shirt', 'shirt', 'trui', 'hoodie', 'jas', 'jacket', 'jurk', 'dress', 'robe', 'broek', 'jeans', 'sneaker', 'schoen', 'shoe', 'chaussure', 'laars', 'sjaal', 'scarf', 'echarpe', 'riem', 'horloge', 'watch', 'montre', 'sieraad', 'sieraden', 'ketting', 'armband', 'oorbel', 'ring', 'zonnebril', 'sunglasses', 'handtas', 'portemonnee', 'wallet', 'sokken', 'women', 'men', 'damen', 'herren', 'kleding', 'vetement'],
        'tech' => ['smartphone', 'tablet', 'ipad', 'laptop', 'smartwatch', 'monitor', 'toetsenbord', 'keyboard', 'muis', 'mouse', 'webcam', 'smart home', 'slimme lamp', 'hue', 'chromecast', 'computers', 'computer', 'informatique', 'informatica', 'handys', 'cell phones', 'mobile phones', 'telefonia', 'powerbank', 'dashcam', 'dash cam', 'beveiligingscamera', 'e-bike'],
        'home' => ['wonen', 'interieur', 'decoratie', 'decoratief', 'woonaccessoire', 'kaars', 'geurkaars', 'plaid', 'kussen', 'vaas', 'wandklok', 'klok', 'lamp', 'verlichting', 'sfeerlicht', 'spiegel', 'poster', 'schilderij', 'beeld', 'home & garden', 'deco', 'bougie', 'coussen', 'wohnen'],
        'craft' => ['hobby', 'hobbypakket', 'knutsel', 'breien', 'breipakket', 'haken', 'naaimachine', 'borduur', 'diamond painting', 'kalligrafie', 'scrapbook', 'modelbouw', 'bricolage', 'basteln', 'creatief', 'klei'],
        'film' => ['film', 'dvd', 'blu-ray', 'blu ray', 'tv-serie', 'serie', 'beamer', 'projector', 'streaming', 'popcorn', 'cinema', 'movie'],
        'pets' => ['hond', 'honden', 'kat', 'katten', 'kattenkrabpaal', 'krabpaal', 'huisdier', 'dieren', 'voerbak', 'voerautomaat', 'hondenmand', 'kattenmand', 'speelgoed voor dieren', 'dog', 'cat', 'pet', 'chien', 'chat', 'hund', 'katze', 'aquarium', 'konijn', 'vogelkooi', 'reisrugzak voor huisdieren'],
        'wellness' => ['massage', 'massageapparaat', 'massagegun', 'aromadiffuser', 'diffuser', 'etherische olie', 'badjas', 'bad', 'sauna', 'spa', 'ontspanning', 'meditatie', 'lichttherapie', 'wellness', 'relax', 'bien-etre'],
        'kids' => ['speelgoed', 'toy', 'toys', 'jouet', 'spielzeug', 'giocattoli', 'knuffel', 'pluche', 'stuffed', 'peluche', 'pop', 'poppen', 'doll', 'poupee', 'speelfiguur', 'action figure', 'lego', 'duplo', 'playmobil', 'constructiespeelgoed', 'bouwset', 'rc voertuig', 'speelgoedvoertuig', 'loopfiets', 'step', 'verkleedkleding', 'kinder', 'baby', 'preschool', 'educatief spel'],
        'art' => ['schilder', 'acrylverf', 'aquarel', 'olieverf', 'penseel', 'penselen', 'ezel', 'canvas', 'tekenen', 'teken', 'tekenetui', 'schetsboek', 'potlood', 'kleurpotlood', 'kleurboek', 'stiften', 'art', 'peinture', 'dessin', 'malen', 'kunst'],
        'cycling' => ['fiets', 'fietshelm', 'fietslamp', 'fietscomputer', 'fietstas', 'wielren', 'mountainbike', 'bike', 'bicycle', 'cycling', 'velo', 'fahrrad', 'bicicletta'],
        'boardgames' => ['bordspel', 'gezelschapsspel', 'kaartspel', 'puzzel', 'puzzle', 'legpuzzel', 'strategiespel', 'partyspel', 'dobbelspel', 'board game', 'jeu de societe', 'brettspiel', 'escape room', 'catan', 'monopoly', 'trading card', 'pokemon'],
        'drinks' => ['wijn', 'wine', 'vin', 'wein', 'vino', 'bier', 'beer', 'biere', 'whisky', 'gin', 'rum', 'cocktail', 'karaf', 'decanteer', 'wijnglas', 'kurkentrekker', 'bierglas', 'sommelier', 'champagne', 'prosecco'],
        'baking' => ['bakken', 'bakvorm', 'springvorm', 'taartvorm', 'cakevorm', 'bakboek', 'keukenweegschaal', 'mixer', 'handmixer', 'staafmixer', 'keukenrobot', 'spuitzak', 'patisserie', 'baking', 'backen', 'kitchenaid', 'broodbakmachine'],
        'running' => ['hardloop', 'running', 'hardloopschoen', 'hardloophorloge', 'hartslagmeter', 'course a pied', 'laufen', 'marathon', 'garmin forerunner'],
        'yoga' => ['yoga', 'yogablok', 'yogamat', 'meditatiekussen', 'pilates', 'mindfulness'],
        'cars' => ['auto', 'car', 'voiture', 'wagen', 'dashcam', 'dash cam', 'autostofzuiger', 'modelauto', 'miniature', 'vehicules miniatures', 'cars, trucks', 'motor', 'motorfiets', 'formule 1', 'formula 1', 'autopoets'],
        'science' => ['telescoop', 'telescope', 'microscoop', 'microscope', 'experiment', 'wetenschap', 'science', 'sterrenkijker', 'planetarium', 'robotica', 'stem', 'chemie', 'globe', 'wereldbol'],
        'water' => ['zwem', 'zwembril', 'snorkel', 'duik', 'sup', 'surf', 'kayak', 'kajak', 'zeil', 'waterdicht', 'natation', 'plongee', 'schwimm', 'strand', 'beach'],
        'wintersports' => ['ski', 'skibril', 'skihelm', 'skihandschoen', 'snowboard', 'schaats', 'slee', 'winter sport', 'sports d hiver'],
        'football' => ['voetbal', 'football', 'soccer', 'fussball', 'calcio', 'keepershandschoen', 'scheenbeschermer', 'voetbalschoen', 'voetbalshirt', 'rode duivels'],
        'collecting' => ['verzamel', 'collector', 'collectible', 'verzamelkaart', 'trading card', 'funko', 'figurine', 'munt', 'postzegel', 'memorabilia', 'animation art', 'limited edition'],
        'nature' => ['natuur', 'nature', 'vogel', 'vogels', 'bird', 'verrekijker', 'insectenhotel', 'vogelvoer', 'natuurgids', 'boswandeling'],
        'fishing' => ['vissen', 'visserij', 'hengel', 'werphengel', 'molen visserij', 'fishing', 'peche', 'angel', 'karper'],
        'horses' => ['paard', 'paarden', 'ruiter', 'rijlaars', 'zadel', 'horse', 'cheval', 'equitation', 'pferd'],
        'hunting' => ['jacht', 'hunting', 'chasse', 'jagd'],
        'gadgets' => ['gadget', 'gizmo', 'fidget', 'multitool', 'slimme', 'tile tracker', 'airtag', 'chipolo', 'tracker'],
        'it' => ['ssd', 'nas', 'router', 'netwerk', 'networking', 'raspberry', 'arduino', 'programmeer', 'coding', 'mechanisch toetsenbord', 'mechanical keyboard', 'computer components'],
    ],

    'not_gifts' => [
        // Parts, refills and replacements.
        // Not "accessories" or "Zubehor": eBay's "Dolls & Accessories" and
        // "Puppen & Zubehor" are categories of dolls, not of spare parts.
        'accessoires', 'accessoire', 'onderdeel', 'onderdelen', 'reserve',
        'vervang', 'replacement', 'spare part', '=parts', '=filter', 'filters', 'navulling', 'refill', 'cartridge', 'toner',
        'inkt', 'stofzuigerzak', 'dampkap', 'afzuigkap', 'oorkussen', 'zuigmond', 'borstelkop', 'opzetborstel',
        // Cases, cables, power and storage for other things.
        'hoesje', 'hoes', 'case', 'coque', 'etui telephone', 'screenprotector', 'screen protector', 'beschermglas',
        'kabel', 'cable', 'oplader', 'charger', 'chargeur', 'adapter', 'adaptateur', 'voeding', 'batterij', 'battery',
        'geheugenkaart', 'memory card', 'harde schijf', 'hard drive', 'solid state drive', 'usb-stick', 'steun',
        '=houder', 'beugel', 'bracket', '=mount',
        // Household supplies nobody unwraps.
        'prullenbak', 'vuilnisbak', 'vershouddoos', 'kattenbak', 'strooisel', 'afvalzak', 'schoonmaak', 'wasmiddel',
        'toiletpapier', 'lunchbox', 'bloeddruk', 'thermometer', 'insectenverdelger', 'insecticide', 'insectenspray', 'ongedierte', 'muizenval', 'rookmelder',
        'lichtbron', 'gloeilamp', 'batterijen', 'everything else', 'verschiedenes',
    ],
];
