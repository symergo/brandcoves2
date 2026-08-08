<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Cove calendar — evergreen themes
|--------------------------------------------------------------------------
|
| config/observances.php covers roughly a hundred named days. This file covers
| the other two-thirds of the year, so that **every date has a theme** and an
| edition never opens with "Today's picks" and nothing else.
|
| WHY NOT INVENT MORE NAMED DAYS
|
| The temptation is to pad the observance list until it reaches 365. Resist it.
| A named day is a factual claim — say "It's National Sock Day" on the wrong
| date and you are simply wrong, in public, once a year, forever. An evergreen
| theme claims nothing about the date. "The desk reset" is true on any Tuesday.
|
| So the two lists degrade differently on purpose: observances are few and
| checked; themes are many and unfalsifiable.
|
| HOW A DATE GETS ONE
|
| ThemeRotation shuffles the themes eligible for a month using a seed derived
| from the year, the month and the market, then hands them out by day. Two
| consequences worth knowing:
|
|  - The same date always yields the same theme for a given market and year, so
|    a plan drafted in January still matches the edition built in June.
|  - Every month must have at least 31 eligible themes, or a month would repeat
|    a theme within itself. `ThemeRotationTest` asserts this — if you add a
|    seasonal theme and remove an all-year one, that test is what will tell you.
|
| `months` is the list of month numbers a theme may appear in; omit it for
| all year. A ski theme in July reads as a machine picking at random, which is
| exactly the impression this whole feature exists to avoid.
|
| Queries bias which finds are chosen; they never filter. A theme whose queries
| match nothing still publishes, with the model's own picks under a title that
| happens not to describe them very tightly — mildly disappointing, where a
| blank page is a bug.
*/

return [

    /*
     * All year.
     *
     * Each of these is a *situation* rather than a category: "the desk reset"
     * suggests why you would look, where "office supplies" only says what is
     * on the shelf.
     */
    ['key' => 'desk_reset', 'queries' => ['bureaulamp', 'monitorarm', 'kabelgoot']],
    ['key' => 'coffee_ritual', 'queries' => ['koffiemolen', 'french press', 'melkopschuimer']],
    ['key' => 'tea_corner', 'queries' => ['theepot', 'theeblik', 'theekopjes']],
    ['key' => 'one_good_knife', 'queries' => ['koksmes', 'slijpsteen', 'snijplank']],
    ['key' => 'slow_cooking', 'queries' => ['braadpan', 'slowcooker', 'gietijzeren pan']],
    ['key' => 'baking', 'queries' => ['keukenmachine', 'bakvorm', 'keukenweegschaal']],
    ['key' => 'sound', 'queries' => ['koptelefoon', 'bluetooth speaker', 'versterker']],
    ['key' => 'vinyl', 'queries' => ['platenspeler', 'vinyl', 'platenkast']],
    ['key' => 'gaming_night', 'queries' => ['gamecontroller', 'gaming headset', 'gaming muis']],
    ['key' => 'board_games', 'queries' => ['bordspel', 'kaartspel', 'puzzel']],
    ['key' => 'reading_nook', 'queries' => ['leeslamp', 'e-reader', 'boekensteun']],
    ['key' => 'better_sleep', 'queries' => ['slaapmasker', 'matras topper', 'verzwaringsdeken']],
    ['key' => 'bathroom', 'queries' => ['badjas', 'handdoeken', 'badkamerrek']],
    ['key' => 'skincare', 'queries' => ['gezichtsreiniger', 'serum', 'gezichtsborstel']],
    ['key' => 'hair', 'queries' => ['föhn', 'stijltang', 'haarborstel']],
    ['key' => 'shaving', 'queries' => ['scheerapparaat', 'baardtrimmer', 'scheerkwast']],
    ['key' => 'running', 'queries' => ['hardloopschoenen', 'sporthorloge', 'hardloopjack']],
    ['key' => 'yoga', 'queries' => ['yogamat', 'foam roller', 'weerstandsbanden']],
    ['key' => 'home_gym', 'queries' => ['dumbbells', 'halterset', 'hometrainer']],
    ['key' => 'cycling', 'queries' => ['fietstas', 'fietsslot', 'fietscomputer']],
    ['key' => 'travel_kit', 'queries' => ['koffer', 'reisadapter', 'packing cubes']],
    ['key' => 'small_flying_things', 'queries' => ['drone', 'actiecamera', 'gimbal']],
    ['key' => 'smart_home', 'queries' => ['slimme lamp', 'slimme stekker', 'slimme thermostaat']],
    ['key' => 'clean_house', 'queries' => ['robotstofzuiger', 'steelstofzuiger', 'stoomreiniger']],
    ['key' => 'laundry', 'queries' => ['wasmand', 'strijkijzer', 'droogrek']],
    ['key' => 'storage', 'queries' => ['opbergbox', 'kledinghoes', 'vacuümzak']],
    ['key' => 'plants', 'queries' => ['plantenpot', 'kweeklamp', 'plantensproeier']],
    ['key' => 'tools', 'queries' => ['accuboormachine', 'gereedschapskoffer', 'multitool']],
    ['key' => 'car_care', 'queries' => ['autostofzuiger', 'telefoonhouder auto', 'startkabels']],
    ['key' => 'the_dog', 'queries' => ['hondenriem', 'hondenbench', 'hondenmand']],
    ['key' => 'the_cat', 'queries' => ['kattenbak', 'krabpaal', 'kattenspeelgoed']],
    ['key' => 'kids_making', 'queries' => ['knutselset', 'kleurpotloden', 'boetseerklei']],
    ['key' => 'bricks', 'queries' => ['lego', 'modelbouw', 'bouwset']],
    ['key' => 'writing', 'queries' => ['vulpen', 'notitieboek', 'bullet journal']],
    ['key' => 'drawing', 'queries' => ['schetsboek', 'aquarelverf', 'tekentablet']],
    ['key' => 'sewing', 'queries' => ['naaimachine', 'breinaalden', 'wol']],
    ['key' => 'photography_kit', 'queries' => ['statief', 'cameratas', 'objectief']],
    ['key' => 'the_hallway', 'queries' => ['kapstok', 'schoenenrek', 'deurmat']],
    ['key' => 'phone_life', 'queries' => ['powerbank', 'telefoonhoesje', 'draadloze oplader']],
    ['key' => 'first_flat', 'queries' => ['pannenset', 'bestekset', 'strijkplank']],

    /*
     * Seasonal.
     *
     * Northern-hemisphere seasons, because all five markets are in it. The day
     * a Spanish market goes live south of the equator, this comment becomes a
     * bug report.
     */
    ['key' => 'grilling', 'queries' => ['bbq', 'houtskool', 'vleesthermometer'], 'months' => [4, 5, 6, 7, 8, 9]],
    ['key' => 'picnic', 'queries' => ['picknickmand', 'koelbox', 'picknickkleed'], 'months' => [4, 5, 6, 7, 8, 9]],
    ['key' => 'beach', 'queries' => ['strandlaken', 'snorkelset', 'parasol'], 'months' => [5, 6, 7, 8, 9]],
    ['key' => 'keeping_cool', 'queries' => ['ventilator', 'airco', 'verduisterende gordijnen'], 'months' => [5, 6, 7, 8, 9]],
    ['key' => 'camping', 'queries' => ['tent', 'slaapzak', 'campingstoel'], 'months' => [4, 5, 6, 7, 8, 9]],
    ['key' => 'garden', 'queries' => ['tuinslang', 'snoeischaar', 'plantenbak'], 'months' => [3, 4, 5, 6, 7, 8, 9, 10]],
    ['key' => 'hiking', 'queries' => ['wandelschoenen', 'rugzak', 'wandelstokken'], 'months' => [3, 4, 5, 6, 7, 8, 9, 10]],
    ['key' => 'cosy', 'queries' => ['plaid', 'geurkaars', 'elektrische deken'], 'months' => [1, 2, 3, 10, 11, 12]],
    ['key' => 'hot_drinks', 'queries' => ['thermosbeker', 'waterkoker', 'chocolademelk'], 'months' => [1, 2, 3, 10, 11, 12]],
    ['key' => 'rain', 'queries' => ['regenjas', 'paraplu', 'regenlaarzen'], 'months' => [1, 2, 3, 4, 9, 10, 11, 12]],
    ['key' => 'indoor_air', 'queries' => ['luchtreiniger', 'luchtbevochtiger', 'ontvochtiger'], 'months' => [1, 2, 3, 4, 9, 10, 11, 12]],
    ['key' => 'winter_sports', 'queries' => ['skibril', 'thermokleding', 'skihandschoenen'], 'months' => [1, 2, 3, 11, 12]],
    ['key' => 'dark_evenings', 'queries' => ['lichtsnoer', 'daglichtlamp', 'tafellamp'], 'months' => [1, 2, 10, 11, 12]],
    ['key' => 'spring_clean', 'queries' => ['raamwisser', 'schoonmaakset', 'opbergsysteem'], 'months' => [3, 4, 5]],

    /*
     * Run-ups.
     *
     * `months` is too coarse for the thing that actually sells: the weeks
     * *before* an event, when people are still deciding. Nobody buys a
     * Halloween costume on 31 October, and the first warm weekend in May is
     * when a paddling pool stops being a silly idea.
     *
     * A window makes its themes eligible only inside a date range, and
     * ThemeRotation gives them roughly one day in three while the window is
     * open — enough that the season is unmistakable, not so much that the site
     * turns into a single shop for a fortnight. Named days still win outright,
     * so 31 October is Halloween itself and not its run-up.
     *
     * `to` may be earlier than `from`; the window then wraps the year end.
     */
    ['key' => 'early_summer', 'queries' => ['zwembad', 'tuinmeubelen', 'partytent'], 'window' => ['from' => '05-01', 'to' => '06-21']],
    ['key' => 'pool_side', 'queries' => ['opblaasbaar zwembad', 'zwembadpomp', 'luchtbed'], 'window' => ['from' => '05-15', 'to' => '07-31']],
    ['key' => 'grilling_season', 'queries' => ['gasbarbecue', 'kamado', 'rookoven'], 'window' => ['from' => '04-15', 'to' => '06-30']],
    ['key' => 'holiday_packing', 'queries' => ['koffer', 'reistas', 'strandtas'], 'window' => ['from' => '06-15', 'to' => '08-15']],
    ['key' => 'school_run_up', 'queries' => ['rugzak', 'broodtrommel', 'schoolagenda'], 'window' => ['from' => '08-01', 'to' => '09-05']],
    ['key' => 'pre_halloween', 'queries' => ['halloween', 'verkleedkleding', 'pompoen'], 'window' => ['from' => '10-05', 'to' => '10-30']],
    ['key' => 'autumn_indoors', 'queries' => ['haardvuur', 'wollen deken', 'sloffen'], 'window' => ['from' => '09-20', 'to' => '11-15']],
    ['key' => 'sinterklaas_run_up', 'queries' => ['speelgoed', 'bouwset', 'strooigoed'], 'window' => ['from' => '11-11', 'to' => '12-04'], 'markets' => ['be-nl', 'nl-nl']],
    ['key' => 'gift_season', 'queries' => ['cadeau', 'kerstcadeau', 'inpakpapier'], 'window' => ['from' => '11-20', 'to' => '12-23']],
    ['key' => 'new_year_reset', 'queries' => ['hometrainer', 'opbergsysteem', 'planner'], 'window' => ['from' => '12-27', 'to' => '01-20']],
];
