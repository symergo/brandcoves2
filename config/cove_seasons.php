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
| `queries` are the member queries the builder retrieves from. Dutch, like the
| rest of the calendar, because the live catalogue is Dutch-language; markets
| whose index does not stem Dutch fall through to the miner's own topics.
*/

return [

    /*
     * Spring.
     */
    [
        'topic' => 'schoonmaken',
        'queries' => ['schoonmaakset', 'raamwisser', 'stoomreiniger', 'robotstofzuiger'],
        // Opens in February: the searches start with the first mild weekend, and
        // that is three weeks before anyone expects it.
        'window' => ['from' => '02-10', 'to' => '05-15'],
    ],
    [
        'topic' => 'hardlopen',
        'queries' => ['hardloopschoenen', 'sporthorloge', 'hardloopjack', 'hardlooprugzak'],
        // The single biggest new-runner month is April, and the decision is made
        // in March.
        'window' => ['from' => '02-15', 'to' => '05-31'],
    ],
    [
        'topic' => 'tuinieren',
        'queries' => ['tuingereedschap', 'snoeischaar', 'plantenbak', 'kweekkas'],
        'window' => ['from' => '02-20', 'to' => '06-30'],
    ],
    [
        'topic' => 'fietsen',
        'queries' => ['fietstas', 'fietsslot', 'fietscomputer', 'fietshelm'],
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
        'queries' => ['gasbarbecue', 'houtskoolbarbecue', 'kamado', 'vleesthermometer'],
        'window' => ['from' => '03-15', 'to' => '07-31'],
    ],
    [
        'topic' => 'zwembad',
        'queries' => ['opblaasbaar zwembad', 'zwembadpomp', 'zwembadtrap', 'poolvacuum'],
        'window' => ['from' => '04-01', 'to' => '07-31'],
    ],
    [
        'topic' => 'zonbescherming',
        'queries' => ['parasol', 'zonnescherm', 'schaduwdoek', 'zonnehoed'],
        'window' => ['from' => '04-15', 'to' => '08-15'],
    ],
    [
        'topic' => 'tuinmeubelen',
        'queries' => ['tuinset', 'loungeset', 'hangmat', 'tuinkussens'],
        'window' => ['from' => '03-15', 'to' => '07-15'],
    ],
    [
        'topic' => 'kamperen',
        'queries' => ['tent', 'slaapzak', 'campingstoel', 'kampeerkooktoestel'],
        'window' => ['from' => '03-15', 'to' => '08-15'],
    ],
    [
        'topic' => 'ventilatie',
        'queries' => ['ventilator', 'airco', 'verduisterende gordijnen', 'luchtkoeler'],
        // Panic-bought in the first heatwave. Written before it, this is the page
        // that is already ranking when the heatwave arrives.
        'window' => ['from' => '04-20', 'to' => '08-31'],
    ],
    [
        'topic' => 'strand',
        'queries' => ['strandlaken', 'strandtas', 'snorkelset', 'bodyboard'],
        'window' => ['from' => '04-15', 'to' => '08-31'],
    ],

    /*
     * Autumn.
     */
    [
        'topic' => 'schoolspullen',
        'queries' => ['rugzak', 'broodtrommel', 'schoolagenda', 'etui'],
        // Written in June, ranking in August. The demand curve is a cliff on
        // 1 September.
        'window' => ['from' => '06-15', 'to' => '09-10'],
    ],
    [
        'topic' => 'halloween',
        'queries' => ['halloween verkleedkleding', 'halloween decoratie', 'pompoen snijset'],
        // Deliberately long: three weeks of demand needs three months of
        // indexing to arrive in time.
        'window' => ['from' => '08-01', 'to' => '10-31'],
    ],
    [
        'topic' => 'luchtkwaliteit',
        'queries' => ['luchtreiniger', 'luchtbevochtiger', 'ontvochtiger', 'hygrometer'],
        // Windows close, and the searches start.
        'window' => ['from' => '08-15', 'to' => '12-31'],
    ],
    [
        'topic' => 'verlichting',
        'queries' => ['daglichtlamp', 'lichtsnoer', 'tafellamp', 'sfeerverlichting'],
        'window' => ['from' => '08-20', 'to' => '01-31'],
    ],

    /*
     * Winter.
     */
    [
        'topic' => 'wintersport',
        'queries' => ['skibril', 'skihelm', 'thermokleding', 'skihandschoenen'],
        // Bought in November and December for a January trip.
        'window' => ['from' => '09-15', 'to' => '02-15'],
    ],
    [
        'topic' => 'cadeaus',
        'queries' => ['cadeau', 'kerstcadeau', 'cadeauverpakking'],
        'window' => ['from' => '09-01', 'to' => '12-23'],
    ],
    [
        'topic' => 'sinterklaas',
        'queries' => ['speelgoed', 'bouwset', 'strooigoed', 'sinterklaascadeau'],
        'markets' => ['be-nl', 'nl-nl'],
        'window' => ['from' => '09-15', 'to' => '12-05'],
    ],
    [
        'topic' => 'kerstverlichting',
        'queries' => ['kerstboom', 'kerstverlichting', 'kerstversiering'],
        'window' => ['from' => '09-20', 'to' => '12-24'],
    ],
    [
        'topic' => 'fitness',
        'queries' => ['hometrainer', 'halterset', 'roeitrainer', 'weerstandsbanden'],
        // January, and the searches begin the day after Christmas.
        'window' => ['from' => '11-15', 'to' => '02-28'],
    ],

    /*
     * Occasion-driven, which is not the same as seasonal.
     */
    [
        'topic' => 'paascadeau',
        'queries' => ['paasdecoratie', 'chocolade eieren', 'paasmand'],
        // Easter moves and this window does not, because it is wide enough to
        // contain every date Easter can fall on. Computing the actual date would
        // be more precise and buy nothing: the topic is written months earlier.
        'window' => ['from' => '02-01', 'to' => '04-25'],
    ],
    [
        'topic' => 'moederdagcadeau',
        'queries' => ['moederdag cadeau', 'sieraden', 'geurkaars'],
        'window' => ['from' => '03-15', 'to' => '05-31'],
    ],
    [
        'topic' => 'vaderdagcadeau',
        'queries' => ['vaderdag cadeau', 'multitool', 'gereedschapskoffer'],
        'window' => ['from' => '04-15', 'to' => '06-30'],
    ],
    [
        'topic' => 'valentijnscadeau',
        'queries' => ['valentijn cadeau', 'sieraden', 'parfum'],
        'window' => ['from' => '12-27', 'to' => '02-14'],
    ],
];
