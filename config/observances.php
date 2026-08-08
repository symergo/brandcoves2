<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Cove calendar
|--------------------------------------------------------------------------
|
| Days worth building an edition around. "International Pet Day" is a better
| reason to open a shopping page than "Tuesday", and a themed day gives the
| edition a shape the Serendipity Engine cannot invent on its own.
|
| DAILY COVES ONLY. This calendar is read by EditionBuilder and by nothing
| else. A themed Cove is an evergreen page — it earns its traffic over years,
| and dating it to one day in April would make it read as stale for the other
| 364. The two kinds of Cove differ in exactly this way: one is an occasion,
| the other is a reference.
|
| Keyed by `MM-DD`, so an entry recurs every year. The queries bias which finds
| are chosen; they do not filter, because an edition that can only show pet
| products on a thin catalogue day is an edition that fails to publish. The
| theme is a translation key so five markets do not need five config files.
|
| `markets` limits an observance to where it means something — Sinterklaas is
| not a Spanish event, and pretending otherwise is the kind of detail that
| makes a site feel machine-made.
|
| Commercial dates (Black Friday, Christmas) are deliberately sparse here. They
| do not need our help to be noticed, and the interesting thing this feature can
| do is the opposite: give someone a reason to look on a day nobody else is
| shouting about.
*/

return [

    // Fixed dates, MM-DD.
    'dates' => [
        '01-21' => ['key' => 'hugs', 'queries' => ['plaid', 'geurkaars', 'knuffel'], 'markets' => ['*']],
        '02-09' => ['key' => 'pizza', 'queries' => ['pizzasteen', 'pizza oven', 'deegroller'], 'markets' => ['*']],
        '03-03' => ['key' => 'wildlife', 'queries' => ['verrekijker', 'vogelhuisje', 'wildcamera'], 'markets' => ['*']],
        '03-20' => ['key' => 'happiness', 'queries' => ['lichttherapielamp', 'aromadiffuser', 'bordspel'], 'markets' => ['*']],
        '04-07' => ['key' => 'health', 'queries' => ['sporthorloge', 'yogamat', 'foam roller'], 'markets' => ['*']],
        '04-11' => ['key' => 'pets', 'queries' => ['hondenmand', 'kattenkrabpaal', 'voerautomaat'], 'markets' => ['*']],
        '04-22' => ['key' => 'earth', 'queries' => ['herbruikbaar', 'kweekkas', 'zonnepaneel'], 'markets' => ['*']],
        '04-23' => ['key' => 'books', 'queries' => ['e-reader', 'leeslamp', 'boekensteun'], 'markets' => ['*']],
        '06-03' => ['key' => 'bicycle', 'queries' => ['fietstas', 'fietshelm', 'fietscomputer'], 'markets' => ['*']],
        '06-05' => ['key' => 'environment', 'queries' => ['thermosfles', 'gerecycled', 'compostbak'], 'markets' => ['*']],
        '06-21' => ['key' => 'music', 'queries' => ['platenspeler', 'koptelefoon', 'ukelele'], 'markets' => ['*']],
        '08-08' => ['key' => 'cats', 'queries' => ['kattenkrabpaal', 'kattenmand', 'kattenspeelgoed'], 'markets' => ['*']],
        '08-19' => ['key' => 'photography', 'queries' => ['statief', 'cameratas', 'instantcamera'], 'markets' => ['*']],
        '09-05' => ['key' => 'coffee', 'queries' => ['espressomachine', 'koffiemolen', 'aeropress'], 'markets' => ['*']],
        '09-21' => ['key' => 'peace_quiet', 'queries' => ['ruisonderdrukking', 'oordoppen', 'witte ruis'], 'markets' => ['*']],
        '10-01' => ['key' => 'coffee_intl', 'queries' => ['koffiebonen', 'french press', 'melkopschuimer'], 'markets' => ['*']],
        '10-04' => ['key' => 'animals', 'queries' => ['hondenspeelgoed', 'huisdier fontein', 'dierenmand'], 'markets' => ['*']],
        '10-16' => ['key' => 'food', 'queries' => ['kookboek', 'koksmes', 'pannenset'], 'markets' => ['*']],
        '11-19' => ['key' => 'mens_health', 'queries' => ['scheerapparaat', 'baardtrimmer', 'sporthorloge'], 'markets' => ['*']],
        '12-04' => ['key' => 'wildlife_conservation', 'queries' => ['verrekijker', 'wildcamera'], 'markets' => ['*']],

        // Regional. Sinterklaas is a Dutch and Belgian event and nothing at all
        // in Spain or the UK.
        '12-05' => ['key' => 'sinterklaas', 'queries' => ['speelgoed', 'bouwset', 'kinderboek'], 'markets' => ['be-nl', 'nl-nl']],
    ],

    /*
     * Moving dates, resolved per year.
     *
     * `nth` is the occurrence within the month (1-indexed), `day` is the ISO
     * weekday. Mother's Day is the classic trap: it is the second Sunday of May
     * in Belgium and the Netherlands, and a different date entirely elsewhere,
     * so it is defined per market rather than once.
     */
    'moving' => [
        [
            'key' => 'mothers_day',
            'month' => 5, 'nth' => 2, 'day' => 7,
            'queries' => ['sieraden', 'parfum', 'geurkaars', 'plantenbak'],
            'markets' => ['be-nl', 'nl-nl', 'be-fr'],
        ],
        [
            'key' => 'fathers_day',
            'month' => 6, 'nth' => 3, 'day' => 7,
            'queries' => ['gereedschapskoffer', 'multitool', 'bbq'],
            'markets' => ['be-nl', 'nl-nl', 'be-fr', 'en'],
        ],
        [
            'key' => 'black_friday',
            'month' => 11, 'nth' => 4, 'day' => 5,
            'queries' => [],
            'markets' => ['*'],
        ],
    ],
];
