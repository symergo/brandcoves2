<?php

/*
 * The words GiftIntentParser reads a gift search with, in Dutch.
 *
 * Every value maps onto the closed gift vocabulary (App\Enums\RecipientType,
 * Interest, EventType), so the parser can only produce what the suggestion
 * engine understands. An interest's own label (site.gift.interests.*) is
 * always recognised as well; the lists below add the other ways people say it.
 * Lower case; longer phrases are tried before shorter ones.
 */

return [
    // Words that say "this is a gift search".
    'triggers' => ['cadeau voor', 'cadeautje voor', 'kado voor', 'kadootje voor', 'geschenk voor', 'geschenkje voor', 'iets voor', 'cadeau', 'cadeautje', 'kado', 'geschenk', 'cadeaus voor'],

    'recipients' => [
        'sibling' => ['zus', 'zusje', 'zussen', 'broer', 'broertje', 'broers'],
        'mother' => ['mama', 'moeder', 'mam', 'schoonmoeder'],
        'father' => ['papa', 'vader', 'pa', 'schoonvader'],
        'partner' => ['mijn man', 'mijn vrouw', 'partner', 'lief', 'lieverd', 'echtgenoot', 'echtgenote'],
        'grandparent' => ['oma', 'opa', 'grootmoeder', 'grootvader', 'grootouders', 'bomma', 'bompa'],
        'child' => ['zoon', 'dochter', 'kind', 'kinderen', 'zoontje', 'dochtertje', 'baby', 'peuter', 'kleuter', 'tiener', 'neefje', 'nichtje', 'petekind'],
        'friend' => ['beste vriend', 'beste vriendin', 'vriendin', 'vriend', 'vrienden', 'kameraad', 'buurman', 'buurvrouw'],
        'colleague' => ['collega', 'collega\'s', 'baas', 'chef'],
        'teacher' => ['juf', 'meester', 'leraar', 'lerares', 'leerkracht', 'juffrouw'],
        'host' => ['gastvrouw', 'gastheer', 'gastgever', 'gastgevers'],
    ],

    'occasions' => [
        'birthday' => ['verjaardag', 'jarig', 'jarige', 'verjaardagscadeau'],
        'christmas' => ['kerst', 'kerstmis', 'kerstcadeau', 'kerstpakket'],
        'wedding' => ['huwelijk', 'bruiloft', 'trouw', 'trouwfeest'],
        'anniversary' => ['jubileum', 'verjaardag huwelijk', 'trouwdag'],
        'baby' => ['geboorte', 'kraambezoek', 'babyshower', 'kraamcadeau'],
        'housewarming' => ['housewarming', 'nieuwe woning', 'verhuis', 'verhuizing', 'nieuw huis'],
        'graduation' => ['diploma', 'afstuderen', 'geslaagd', 'proclamatie'],
        'retirement' => ['pensioen', 'met pensioen'],
        'farewell' => ['afscheid', 'vertrek'],
        'valentines' => ['valentijn', 'valentijnsdag'],
        'mothers_day' => ['moederdag'],
        'fathers_day' => ['vaderdag'],
        'thank_you' => ['bedankje', 'bedankt', 'dankjewel', 'om te bedanken'],
    ],

    'interests' => [
        'cooking' => ['koken', 'kok', 'keuken', 'kookt'],
        'coffee' => ['koffie', 'koffieliefhebber'],
        'photography' => ['foto', 'fotografie', 'fotograferen', 'fotograaf'],
        'music' => ['muziek', 'muzikant', 'gitaar', 'piano'],
        'gaming' => ['gamen', 'gamer', 'games', 'gaming', 'playstation', 'nintendo', 'xbox'],
        'reading' => ['lezen', 'boeken', 'lezer', 'leest'],
        'fitness' => ['sporten', 'sport', 'fitness', 'fitnessen'],
        'outdoors' => ['buiten', 'kamperen', 'wandelen', 'hiken', 'kampeerder'],
        'travel' => ['reizen', 'reiziger', 'op reis'],
        'gardening' => ['tuinieren', 'tuin', 'planten', 'tuinier', 'moestuin'],
        'diy' => ['klussen', 'klusser', 'doe-het-zelf', 'gereedschap'],
        'beauty' => ['verzorging', 'make-up', 'beauty', 'huidverzorging'],
        'fashion' => ['mode', 'kleding', 'fashion'],
        'tech' => ['techniek', 'technologie', 'tech'],
        'home' => ['interieur', 'wonen'],
        'craft' => ['knutselen', 'breien', 'haken', 'naaien', 'creatief', 'zelf maken'],
        'film' => ['films', 'series', 'film', 'cinema'],
        'pets' => ['hond', 'kat', 'huisdier', 'huisdieren'],
        'wellness' => ['ontspannen', 'wellness', 'relaxen', 'spa'],
        'art' => ['tekenen', 'schilderen', 'kunst', 'kunstenaar'],
        'cycling' => ['fietsen', 'fietser', 'wielrennen', 'wielrenner', 'mountainbike'],
        'boardgames' => ['bordspellen', 'gezelschapsspellen', 'spelletjes'],
        'drinks' => ['wijn', 'bier', 'whisky', 'gin', 'cocktails'],
        'baking' => ['bakken', 'taarten'],
        'running' => ['hardlopen', 'lopen', 'loper', 'joggen'],
        'yoga' => ['yoga', 'meditatie', 'mediteren'],
        'cars' => ['auto', 'auto\'s', 'autos'],
        'science' => ['wetenschap', 'ruimte', 'sterren'],
        'water' => ['zwemmen', 'surfen', 'zeilen', 'watersport'],
        'wintersports' => ['skiën', 'skien', 'snowboarden', 'wintersport'],
        'football' => ['voetbal', 'voetballer'],
        'collecting' => ['verzamelen', 'verzamelaar'],
        'nature' => ['natuur', 'vogels', 'vogelspotten'],
        'fishing' => ['vissen', 'visser', 'hengelen'],
        'horses' => ['paarden', 'paardrijden', 'paard'],
        'gadgets' => ['gadgets', 'gadget'],
        'it' => ['computers', 'computer', 'programmeren'],
    ],

    // "Someone who has everything": the brief then prefers what gets used up
    // or done over more things to keep (docs/features/has-everything.md).
    // Also a sign that this is a gift search.
    'has_everything' => ['die alles al heeft', 'dat alles al heeft', 'die al alles heeft', 'heeft alles al', 'heeft al alles', 'alles al heeft', 'al alles heeft', 'die alles heeft', 'heeft alles', 'alles heeft'],

    // Budget words: "onder de 50", "tussen 30 en 50".
    'under' => ['onder de', 'onder', 'minder dan', 'tot', 'maximaal', 'max', 'hooguit', 'niet meer dan'],
    'between' => ['tussen'],
    'and' => ['en', 'tot'],

    // Filler around the intent, removed before what is left becomes a search term.
    'filler' => ['mijn', 'm\'n', 'mn', 'onze', 'die', 'dat', 'van', 'houdt van', 'graag', 'houdt', 'wie', 'voor', 'een', 'de', 'het', 'met', 'en', 'euro', 'eur', 'leuk', 'leuke', 'vindt', 'dol op', 'is', 'op', 'aan'],
];
