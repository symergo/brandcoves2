<?php

/*
 * The words GiftIntentParser reads a gift search with, in English.
 * See lang/nl/intent.php for how the lists are used.
 */

return [
    'triggers' => ['gift for', 'gifts for', 'present for', 'presents for', 'something for', 'gift ideas for', 'gift', 'present'],

    'recipients' => [
        'sibling' => ['sister', 'brother', 'sis', 'bro', 'siblings'],
        'mother' => ['mum', 'mom', 'mother', 'mommy', 'mummy', 'mother-in-law'],
        'father' => ['dad', 'father', 'daddy', 'father-in-law'],
        'partner' => ['girlfriend', 'boyfriend', 'wife', 'husband', 'partner', 'fiancé', 'fiancee', 'fiance'],
        'grandparent' => ['grandma', 'grandpa', 'grandmother', 'grandfather', 'granny', 'grandparents'],
        'child' => ['son', 'daughter', 'kid', 'kids', 'child', 'children', 'baby', 'toddler', 'teen', 'teenager', 'nephew', 'niece', 'godchild'],
        'friend' => ['best friend', 'friend', 'friends', 'neighbour', 'neighbor', 'mate'],
        'colleague' => ['colleague', 'coworker', 'co-worker', 'boss'],
        'teacher' => ['teacher', 'tutor', 'coach'],
        'host' => ['host', 'hostess', 'hosts'],
    ],

    'occasions' => [
        'birthday' => ['birthday', 'bday'],
        'christmas' => ['christmas', 'xmas'],
        'wedding' => ['wedding'],
        'anniversary' => ['anniversary'],
        'baby' => ['new baby', 'baby shower', 'newborn'],
        'housewarming' => ['housewarming', 'new home', 'new house'],
        'graduation' => ['graduation', 'graduating'],
        'retirement' => ['retirement', 'retiring'],
        'farewell' => ['farewell', 'leaving'],
        'valentines' => ['valentine', 'valentines', 'valentine\'s'],
        'mothers_day' => ['mother\'s day', 'mothers day'],
        'fathers_day' => ['father\'s day', 'fathers day'],
        'thank_you' => ['thank you', 'thanks'],
    ],

    'interests' => [
        'cooking' => ['cooking', 'cook', 'chef', 'kitchen', 'cooks'],
        'coffee' => ['coffee'],
        'photography' => ['photography', 'photographer', 'photos', 'camera'],
        'music' => ['music', 'musician', 'guitar', 'piano'],
        'gaming' => ['gaming', 'gamer', 'games', 'video games'],
        'reading' => ['reading', 'books', 'reader', 'bookworm'],
        'fitness' => ['fitness', 'gym', 'sport', 'sports', 'workout'],
        'outdoors' => ['outdoors', 'camping', 'hiking', 'hiker'],
        'travel' => ['travel', 'travelling', 'traveling', 'traveller', 'traveler'],
        'gardening' => ['gardening', 'garden', 'gardener', 'plants'],
        'diy' => ['diy', 'tools', 'woodworking'],
        'beauty' => ['beauty', 'makeup', 'make-up', 'skincare'],
        'fashion' => ['fashion', 'clothes'],
        'tech' => ['tech', 'technology'],
        'home' => ['interior', 'home decor'],
        'craft' => ['crafts', 'crafting', 'knitting', 'sewing', 'crochet'],
        'film' => ['films', 'movies', 'series', 'film', 'cinema'],
        'pets' => ['dog', 'cat', 'pets', 'pet'],
        'wellness' => ['relaxing', 'wellness', 'spa', 'self-care'],
        'art' => ['drawing', 'painting', 'art', 'artist'],
        'cycling' => ['cycling', 'cyclist', 'bike', 'biking'],
        'boardgames' => ['board games', 'boardgames', 'tabletop'],
        'drinks' => ['wine', 'beer', 'whisky', 'whiskey', 'gin', 'cocktails'],
        'baking' => ['baking', 'baker'],
        'running' => ['running', 'runner', 'jogging'],
        'yoga' => ['yoga', 'meditation'],
        'cars' => ['cars', 'car'],
        'science' => ['science', 'space', 'astronomy'],
        'water' => ['swimming', 'surfing', 'sailing'],
        'wintersports' => ['skiing', 'snowboarding'],
        'football' => ['football', 'soccer'],
        'collecting' => ['collecting', 'collector'],
        'nature' => ['nature', 'birds', 'birdwatching'],
        'fishing' => ['fishing', 'angling'],
        'horses' => ['horses', 'horse riding', 'riding'],
        'gadgets' => ['gadgets', 'gadget'],
        'it' => ['computers', 'computer', 'programming', 'coding'],
    ],

    'under' => ['under', 'below', 'less than', 'up to', 'max', 'maximum', 'no more than'],
    'between' => ['between'],
    'and' => ['and', 'to'],

    'filler' => ['my', 'our', 'who', 'that', 'loves', 'likes', 'love', 'like', 'into', 'for', 'a', 'an', 'the', 'with', 'and', 'euro', 'euros', 'eur', 'is', 'of', 'on'],
];
