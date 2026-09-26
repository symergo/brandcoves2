<?php

declare(strict_types=1);

/*
 * What to give someone who has everything: things that get used up or done
 * rather than kept. A tasting box, a refill, a workshop, a subscription, a
 * box of good chocolate.
 *
 * Used by App\Services\Gift\HasEverything (2026-09-26, the owner's request 8),
 * in two ways, and that is why there are two lists:
 *
 * - 'search' is what the suggestion engine retrieves on, per language: whole
 *   words the catalogue's titles use, fed to the full-text search of the
 *   market's language. Short and common on purpose: every word here is one OR
 *   in a search, and the engine stops being selective past about 24.
 * - 'words' is what decides that a product IS one of these, read from its
 *   title and category with the same rules as interest-words.php (lowercase,
 *   accents off; five letters or more match at the start of a word, so
 *   "proeverij" finds "proeverijpakket"; shorter words and any written with a
 *   leading "=" only as the whole word). Every language together, because a
 *   feed's category arrives in whatever language the feed was written in.
 *
 * A product the words match is preferred; one they do not is only used when
 * there are not enough of the first kind. So a word that is too broad here
 * ("box", "set") makes the has-everything persona a shelf of ordinary things,
 * and they have been left out for that reason. So have "the" and "te" (the
 * French and Spanish for tea, and an English article and a Spanish pronoun)
 * and "gourmet" (in Dutch a gourmetstel is a table grill). Write words as they begin,
 * singular, without accents.
 */

return [
    'search' => [
        'nl' => ['proeverij', 'proefpakket', 'proefdoos', 'workshop', 'abonnement', 'navulling', 'belevenis', 'ervaring', 'cadeaubon', 'chocolade', 'pralines', 'thee', 'koffiebonen', 'wijnpakket', 'bierpakket', 'gin', 'whisky', 'delicatessen', 'geurkaars', 'badbruisbal', 'cursus', 'masterclass', 'tickets'],
        'en' => ['tasting', 'workshop', 'subscription', 'refill', 'experience', 'voucher', 'chocolate', 'truffles', 'tea', 'coffee beans', 'wine', 'gin', 'whisky', 'hamper', 'candle', 'bath bomb', 'masterclass', 'course', 'tickets', 'delicacies'],
        'fr' => ['degustation', 'atelier', 'abonnement', 'recharge', 'experience', 'bon cadeau', 'chocolat', 'pralines', 'infusion', 'cafe en grains', 'vin', 'gin', 'whisky', 'coffret gourmand', 'bougie', 'masterclass', 'cours', 'billets'],
        'es' => ['degustacion', 'taller', 'suscripcion', 'recarga', 'experiencia', 'cheque regalo', 'chocolate', 'bombones', 'infusion', 'cafe en grano', 'vino', 'ginebra', 'whisky', 'cesta gourmet', 'vela', 'masterclass', 'curso', 'entradas'],
    ],

    'words' => [
        // Tasting and trying.
        'proeverij', 'proefpakket', 'proefdoos', 'proefset', 'tasting', 'degustation', 'degustacion', 'degustazione', 'verkostung',
        // Done rather than kept.
        'workshop', 'masterclass', 'cursus', 'course', '=cours', 'curso', 'atelier', 'taller', 'belevenis', 'ervaring', 'experience', 'experiencia', 'erlebnis', 'dagje uit', 'ticket', 'billet', 'entrada',
        // Refills and subscriptions.
        'navulling', 'navul', 'refill', 'recharge', 'recarga', 'nachfull', 'abonnement', 'subscription', 'suscripcion',
        // Vouchers.
        'cadeaubon', 'cadeaukaart', 'giftcard', 'gift card', 'voucher', 'bon cadeau', 'cheque regalo', 'gutschein',
        // Consumable treats.
        'chocolade', 'chocolate', 'chocolat', 'schokolade', 'praline', 'bonbon', 'bombon', 'truffel', 'truffle', 'truffe',
        '=thee', '=tea', 'theeset', 'theedoos', 'thee selectie', 'koffiebonen', 'coffee beans', 'cafe en grain', 'cafe en grano',
        'wijnpakket', 'wijnkist', 'wine box', 'bierpakket', 'beer box', '=gin', 'ginebra', 'whisky', 'whiskey', 'likeur', 'liqueur',
        'delicatesse', 'delicatessen', 'delicacies', 'hamper', 'kerstpakket', 'foodbox', 'snackbox', 'borrelpakket', 'coffret gourmand', 'cesta gourmet',
        'geurkaars', 'scented candle', 'bougie parfumee', 'badbruis', 'bath bomb', 'badzout', 'bath salt',
    ],
];
