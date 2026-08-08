<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Cove calendar — named days
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
| WHICH DAYS ARE IN HERE, AND WHICH ARE NOT
|
| The obvious source for "a theme for every day" is the UN's international-day
| list. It is deliberately not used. A large share of it is atrocity
| remembrance and disease awareness — Holocaust Memorial Day, the Srebrenica
| genocide, victims of enforced disappearances, World AIDS Day. Those are real
| dates and they are not shopping occasions; putting "today's finds" under a
| genocide remembrance banner is the kind of mistake that ends up in a
| screenshot. So this list is drawn from the commercial and playful calendars
| instead: food days, fandom days, hobby days, retail moments.
|
| The test for an entry is one question: *would a reader be pleased, rather
| than appalled, to be sold something today?* Anything that fails it is left
| out, however well-known the date.
|
| Keyed by `MM-DD`, so an entry recurs every year. The queries bias which finds
| are chosen; they do not filter, because an edition that can only show pet
| products on a thin catalogue day is an edition that fails to publish. The
| theme is a translation key so five markets do not need five config files.
|
| Queries are Dutch because the live catalogue is Dutch-language; in a market
| whose index does not stem Dutch they simply match nothing and the edition
| falls through to the model's own picks, which is the correct degradation.
|
| `markets` limits an observance to where it means something — Sinterklaas is
| not a Spanish event, and pretending otherwise is the kind of detail that
| makes a site feel machine-made.
|
| Days this calendar does NOT cover fall through to the evergreen rotation in
| config/cove_themes.php, so every date of the year has a theme.
*/

return [

    // Fixed dates, MM-DD.
    'dates' => [

        /*
         * January — the recovery month. Nobody wants to be sold a luxury on
         * 2 January, so the early entries lean domestic and cheap.
         */
        '01-01' => ['key' => 'new_year', 'queries' => ['agenda', 'notitieboek', 'weegschaal'], 'markets' => ['*']],
        '01-03' => ['key' => 'sleep', 'queries' => ['slaapmasker', 'matras topper', 'wekkerradio'], 'markets' => ['*']],
        '01-06' => ['key' => 'three_kings', 'queries' => ['speelgoed', 'bouwset', 'kinderboek'], 'markets' => ['es']],
        '01-10' => ['key' => 'houseplants', 'queries' => ['plantenpot', 'kweeklamp', 'gieter'], 'markets' => ['*']],
        '01-14' => ['key' => 'pet_style', 'queries' => ['hondentrui', 'halsband', 'hondenmand'], 'markets' => ['*']],
        '01-15' => ['key' => 'hats', 'queries' => ['muts', 'pet', 'hoed'], 'markets' => ['*']],
        '01-17' => ['key' => 'hot_tea', 'queries' => ['theepot', 'theeblik', 'waterkoker'], 'markets' => ['*']],
        '01-19' => ['key' => 'popcorn', 'queries' => ['popcornmachine', 'beamer', 'filmposter'], 'markets' => ['*']],
        '01-20' => ['key' => 'cheese', 'queries' => ['kaasplank', 'fonduepan', 'kaasschaaf'], 'markets' => ['*']],
        '01-21' => ['key' => 'hugs', 'queries' => ['plaid', 'geurkaars', 'knuffel'], 'markets' => ['*']],
        '01-23' => ['key' => 'pie', 'queries' => ['taartvorm', 'springvorm', 'handmixer'], 'markets' => ['*']],
        '01-25' => ['key' => 'burns_night', 'queries' => ['whiskyglas', 'karaf', 'ijsblokjesvorm'], 'markets' => ['en']],
        '01-28' => ['key' => 'lego_day', 'queries' => ['lego', 'bouwset', 'modelbouw'], 'markets' => ['*']],
        '01-29' => ['key' => 'puzzles', 'queries' => ['puzzel', 'legpuzzel', 'bordspel'], 'markets' => ['*']],

        // February — Valentine's is the anchor; the rest is comfort and kit.
        '02-09' => ['key' => 'pizza', 'queries' => ['pizzasteen', 'pizza oven', 'deegroller'], 'markets' => ['*']],
        '02-11' => ['key' => 'science', 'queries' => ['microscoop', 'telescoop', 'experimenteerdoos'], 'markets' => ['*']],
        '02-13' => ['key' => 'radio', 'queries' => ['dab radio', 'platenspeler', 'wekkerradio'], 'markets' => ['*']],
        '02-14' => ['key' => 'valentines', 'queries' => ['sieraden', 'parfum', 'chocolade'], 'markets' => ['*']],
        '02-17' => ['key' => 'kindness', 'queries' => ['bloemenvaas', 'geurkaars', 'theeset'], 'markets' => ['*']],
        '02-18' => ['key' => 'wine', 'queries' => ['wijnglazen', 'kurkentrekker', 'wijnkoeler'], 'markets' => ['*']],
        '02-20' => ['key' => 'love_your_pet', 'queries' => ['kattenkruid', 'hondenspeelgoed', 'voerautomaat'], 'markets' => ['*']],
        '02-22' => ['key' => 'cocktails', 'queries' => ['cocktailshaker', 'cocktailglazen', 'ijsblokjesvorm'], 'markets' => ['*']],
        '02-27' => ['key' => 'pokemon', 'queries' => ['pokemon', 'ruilkaarten', 'verzamelmap'], 'markets' => ['*']],

        // March — the ground thaws and the hobby budget reopens.
        '03-03' => ['key' => 'wildlife', 'queries' => ['verrekijker', 'vogelhuisje', 'wildcamera'], 'markets' => ['*']],
        '03-08' => ['key' => 'womens_day', 'queries' => ['gereedschapsset', 'sportfiets', 'boormachine'], 'markets' => ['*']],
        '03-10' => ['key' => 'mario_day', 'queries' => ['nintendo switch', 'mario', 'gamecontroller'], 'markets' => ['*']],
        '03-14' => ['key' => 'pi_day', 'queries' => ['taartvorm', 'rekenmachine', 'quiche vorm'], 'markets' => ['*']],
        '03-20' => ['key' => 'happiness', 'queries' => ['lichttherapielamp', 'aromadiffuser', 'bordspel'], 'markets' => ['*']],
        '03-21' => ['key' => 'poetry', 'queries' => ['vulpen', 'notitieboek', 'boekensteun'], 'markets' => ['*']],
        '03-22' => ['key' => 'water', 'queries' => ['waterfles', 'waterfilter', 'regenton'], 'markets' => ['*']],
        '03-25' => ['key' => 'tolkien', 'queries' => ['fantasy', 'landkaart poster', 'boekenkast'], 'markets' => ['*']],
        '03-30' => ['key' => 'pencils', 'queries' => ['potloden', 'schetsboek', 'tekenset'], 'markets' => ['*']],
        '03-31' => ['key' => 'backup', 'queries' => ['externe harde schijf', 'ssd', 'nas'], 'markets' => ['*']],

        // April.
        '04-01' => ['key' => 'april_fools', 'queries' => ['fopartikelen', 'gezelschapsspel', 'gadget'], 'markets' => ['*']],
        '04-02' => ['key' => 'childrens_books', 'queries' => ['kinderboek', 'voorleesboek', 'nachtlampje'], 'markets' => ['*']],
        '04-07' => ['key' => 'health', 'queries' => ['sporthorloge', 'yogamat', 'foam roller'], 'markets' => ['*']],
        '04-11' => ['key' => 'pets', 'queries' => ['hondenmand', 'kattenkrabpaal', 'voerautomaat'], 'markets' => ['*']],
        '04-12' => ['key' => 'space', 'queries' => ['telescoop', 'sterrenkijker', 'planetarium'], 'markets' => ['*']],
        '04-22' => ['key' => 'earth', 'queries' => ['herbruikbaar', 'kweekkas', 'zonnepaneel'], 'markets' => ['*']],
        '04-23' => ['key' => 'books', 'queries' => ['e-reader', 'leeslamp', 'boekensteun'], 'markets' => ['*']],
        '04-27' => ['key' => 'kingsday', 'queries' => ['feestartikelen', 'koelbox', 'bluetooth speaker'], 'markets' => ['nl-nl']],
        '04-29' => ['key' => 'dance', 'queries' => ['bluetooth speaker', 'discolamp', 'dansmat'], 'markets' => ['*']],
        '04-30' => ['key' => 'jazz', 'queries' => ['platenspeler', 'vinyl', 'koptelefoon'], 'markets' => ['*']],

        // May.
        '05-01' => ['key' => 'makers', 'queries' => ['gereedschapskoffer', 'werkbank', 'accuboormachine'], 'markets' => ['*']],
        '05-04' => ['key' => 'star_wars', 'queries' => ['star wars', 'lego star wars', 'verzamelfiguur'], 'markets' => ['*']],
        '05-11' => ['key' => 'eat_what_you_want', 'queries' => ['airfryer', 'snackmaker', 'frituurpan'], 'markets' => ['*']],
        '05-15' => ['key' => 'family', 'queries' => ['bordspel', 'picknickkleed', 'gezelschapsspel'], 'markets' => ['*']],
        '05-20' => ['key' => 'bees', 'queries' => ['bijenhotel', 'bloemenzaad', 'insectenhotel'], 'markets' => ['*']],
        '05-21' => ['key' => 'tea', 'queries' => ['theeset', 'matcha', 'theekopjes'], 'markets' => ['*']],
        '05-25' => ['key' => 'geek_pride', 'queries' => ['verzamelfiguur', 'sci-fi', 'gaming'], 'markets' => ['*']],
        '05-29' => ['key' => 'mountains', 'queries' => ['wandelschoenen', 'rugzak', 'thermosfles'], 'markets' => ['*']],

        // June.
        '06-03' => ['key' => 'bicycle', 'queries' => ['fietstas', 'fietshelm', 'fietscomputer'], 'markets' => ['*']],
        '06-05' => ['key' => 'environment', 'queries' => ['thermosfles', 'gerecycled', 'compostbak'], 'markets' => ['*']],
        '06-08' => ['key' => 'oceans', 'queries' => ['snorkelset', 'strandlaken', 'waterdichte tas'], 'markets' => ['*']],
        '06-18' => ['key' => 'sushi', 'queries' => ['sushiset', 'rijstkoker', 'koksmes'], 'markets' => ['*']],
        '06-21' => ['key' => 'music', 'queries' => ['platenspeler', 'koptelefoon', 'ukelele'], 'markets' => ['*']],
        '06-30' => ['key' => 'skateboarding', 'queries' => ['skateboard', 'helm', 'beschermset'], 'markets' => ['*']],

        // July — holiday month, so it leans outdoors and travel.
        '07-07' => ['key' => 'chocolate', 'queries' => ['chocolade', 'bonbons', 'chocoladevorm'], 'markets' => ['*']],
        '07-17' => ['key' => 'emoji', 'queries' => ['telefoonhoesje', 'stickers', 'kussen'], 'markets' => ['*']],
        '07-20' => ['key' => 'moon', 'queries' => ['telescoop', 'maanlamp', 'sterrenkaart'], 'markets' => ['*']],
        '07-21' => ['key' => 'belgian_national', 'queries' => ['bbq', 'friteuse', 'koelbox'], 'markets' => ['be-nl', 'be-fr']],
        '07-30' => ['key' => 'friendship', 'queries' => ['bordspel', 'fotolijst', 'picknickmand'], 'markets' => ['*']],
        '07-31' => ['key' => 'wizarding', 'queries' => ['toverstaf', 'fantasy', 'bouwset'], 'markets' => ['*']],

        // August.
        '08-08' => ['key' => 'cats', 'queries' => ['kattenkrabpaal', 'kattenmand', 'kattenspeelgoed'], 'markets' => ['*']],
        '08-09' => ['key' => 'book_lovers', 'queries' => ['boekensteun', 'leeslamp', 'boekenkast'], 'markets' => ['*']],
        '08-13' => ['key' => 'lefthanders', 'queries' => ['linkshandig', 'schaar', 'muis'], 'markets' => ['*']],
        '08-19' => ['key' => 'photography', 'queries' => ['statief', 'cameratas', 'instantcamera'], 'markets' => ['*']],
        '08-26' => ['key' => 'dogs', 'queries' => ['hondenriem', 'hondenbench', 'hondenspeelgoed'], 'markets' => ['*']],
        '08-31' => ['key' => 'back_to_school', 'queries' => ['rugzak', 'etui', 'bureaustoel'], 'markets' => ['*']],

        // September.
        '09-05' => ['key' => 'coffee', 'queries' => ['espressomachine', 'koffiemolen', 'aeropress'], 'markets' => ['*']],
        '09-08' => ['key' => 'literacy', 'queries' => ['e-reader', 'woordenboek', 'leeslamp'], 'markets' => ['*']],
        '09-13' => ['key' => 'programmers', 'queries' => ['mechanisch toetsenbord', 'monitor', 'raspberry pi'], 'markets' => ['*']],
        '09-19' => ['key' => 'pirates', 'queries' => ['verkleedkleding', 'verrekijker', 'kompas'], 'markets' => ['*']],
        '09-21' => ['key' => 'peace_quiet', 'queries' => ['ruisonderdrukking', 'oordoppen', 'witte ruis'], 'markets' => ['*']],
        '09-27' => ['key' => 'travel', 'queries' => ['koffer', 'reisadapter', 'nekkussen'], 'markets' => ['*']],

        // October.
        '10-01' => ['key' => 'coffee_intl', 'queries' => ['koffiebonen', 'french press', 'melkopschuimer'], 'markets' => ['*']],
        '10-04' => ['key' => 'animals', 'queries' => ['hondenspeelgoed', 'huisdier fontein', 'dierenmand'], 'markets' => ['*']],
        '10-05' => ['key' => 'teachers', 'queries' => ['thermosbeker', 'planner', 'whiteboard'], 'markets' => ['*']],
        '10-16' => ['key' => 'food', 'queries' => ['kookboek', 'koksmes', 'pannenset'], 'markets' => ['*']],
        '10-20' => ['key' => 'chefs', 'queries' => ['koksmes', 'snijplank', 'keukenweegschaal'], 'markets' => ['*']],
        '10-29' => ['key' => 'internet', 'queries' => ['wifi router', 'mesh systeem', 'netwerkkabel'], 'markets' => ['*']],
        '10-31' => ['key' => 'halloween', 'queries' => ['halloween', 'verkleedkleding', 'lichtsnoer'], 'markets' => ['*']],

        // November — the loudest retail month, so the calendar stays quiet
        // around it and lets Black Friday do its own shouting.
        '11-11' => ['key' => 'singles_day', 'queries' => ['koptelefoon', 'smartwatch', 'powerbank'], 'markets' => ['*']],
        '11-13' => ['key' => 'world_kindness', 'queries' => ['cadeauverpakking', 'wenskaarten', 'geurkaars'], 'markets' => ['*']],
        '11-19' => ['key' => 'mens_health', 'queries' => ['scheerapparaat', 'baardtrimmer', 'sporthorloge'], 'markets' => ['*']],
        '11-21' => ['key' => 'television', 'queries' => ['soundbar', 'tv beugel', 'streaming stick'], 'markets' => ['*']],
        '11-30' => ['key' => 'digital_tidy', 'queries' => ['usb stick', 'externe harde schijf', 'documentvernietiger'], 'markets' => ['*']],

        // December.
        '12-04' => ['key' => 'wildlife_conservation', 'queries' => ['verrekijker', 'wildcamera'], 'markets' => ['*']],

        // Regional. Sinterklaas is a Dutch and Belgian event and nothing at all
        // in Spain or the UK; Saint-Nicolas in Wallonia is the day after.
        '12-05' => ['key' => 'sinterklaas', 'queries' => ['speelgoed', 'bouwset', 'kinderboek'], 'markets' => ['be-nl', 'nl-nl']],
        '12-06' => ['key' => 'saint_nicolas', 'queries' => ['speelgoed', 'bouwset', 'kinderboek'], 'markets' => ['be-fr']],

        '12-21' => ['key' => 'solstice', 'queries' => ['lichtsnoer', 'geurkaars', 'elektrische deken'], 'markets' => ['*']],
        '12-24' => ['key' => 'christmas_eve', 'queries' => ['inpakpapier', 'cadeaulint', 'kerstverlichting'], 'markets' => ['*']],
        // Nobody is shopping on Christmas Day, so this edition is written for
        // the person who is looking anyway: the batteries nobody bought, the
        // game that needs a fourth player.
        '12-25' => ['key' => 'christmas_day', 'queries' => ['batterijen', 'bordspel', 'kaartspel'], 'markets' => ['*']],
        '12-26' => ['key' => 'boxing_day', 'queries' => ['bordspel', 'puzzel', 'plaid'], 'markets' => ['en']],
        '12-31' => ['key' => 'new_years_eve', 'queries' => ['champagneglazen', 'feestartikelen', 'oliebollenpan'], 'markets' => ['*']],
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
            // The third Monday of January, sold as the year's bleakest day. The
            // science is nonsense and the retail calendar does not care.
            'key' => 'blue_monday',
            'month' => 1, 'nth' => 3, 'day' => 1,
            'queries' => ['daglichtlamp', 'plaid', 'geurkaars'],
            'markets' => ['*'],
        ],
        [
            'key' => 'mothers_day',
            'month' => 5, 'nth' => 2, 'day' => 7,
            'queries' => ['sieraden', 'parfum', 'geurkaars', 'plantenbak'],
            'markets' => ['be-nl', 'nl-nl', 'be-fr'],
        ],
        [
            'key' => 'record_store_day',
            'month' => 4, 'nth' => 3, 'day' => 6,
            'queries' => ['platenspeler', 'vinyl', 'platenkast'],
            'markets' => ['*'],
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
