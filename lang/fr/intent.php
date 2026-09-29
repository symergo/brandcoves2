<?php

/*
 * The words GiftIntentParser reads a gift search with, in French.
 * See lang/nl/intent.php for how the lists are used.
 */

return [
    'triggers' => ['cadeau pour', 'cadeaux pour', 'idée cadeau pour', 'idées cadeaux pour', 'quelque chose pour', 'présent pour', 'cadeau'],

    'recipients' => [
        'brother' => ['frère', 'frere', 'petit frère'],
        'sister' => ['sœur', 'soeur', 'petite sœur'],
        'mother' => ['maman', 'mère', 'mere', 'belle-mère'],
        'father' => ['papa', 'père', 'pere', 'beau-père'],
        'partner' => ['ma femme', 'mon mari', 'ma copine', 'mon copain', 'chérie', 'chéri', 'compagne', 'compagnon', 'épouse', 'époux', 'partenaire'],
        'grandmother' => ['mamie', 'grand-mère', 'grand-mere'],
        'grandfather' => ['papi', 'papy', 'grand-père', 'grand-pere'],
        'son' => ['fils'],
        'daughter' => ['fille'],
        'male_friend' => ['meilleur ami', 'ami'],
        'female_friend' => ['meilleure amie', 'amie'],
        'colleague' => ['collègue', 'collegue', 'patron', 'patronne', 'chef'],
        'female_teacher' => ['maîtresse', 'maitresse', 'institutrice'],
        'male_teacher' => ['maître', 'maitre', 'instituteur'],
        'male_host' => ['hôte', 'hote'],
        'female_host' => ['hôtesse', 'hotesse'],
    ],

    'occasions' => [
        'birthday' => ['anniversaire', 'anniv'],
        'christmas' => ['noël', 'noel'],
        'wedding' => ['mariage'],
        'anniversary' => ['anniversaire de mariage', 'noces'],
        'baby' => ['naissance', 'baby shower'],
        'housewarming' => ['crémaillère', 'cremaillere', 'nouvelle maison', 'déménagement'],
        'graduation' => ['diplôme', 'diplome', 'remise des diplômes'],
        'retirement' => ['retraite', 'départ à la retraite'],
        'farewell' => ['départ', 'pot de départ'],
        'valentines' => ['saint-valentin', 'saint valentin'],
        'mothers_day' => ['fête des mères', 'fete des meres'],
        'fathers_day' => ['fête des pères', 'fete des peres'],
        'thank_you' => ['remerciement', 'merci', 'pour remercier'],
    ],

    'interests' => [
        'cooking' => ['cuisine', 'cuisiner', 'cuisinier', 'cuisinière'],
        'coffee' => ['café', 'cafe'],
        'photography' => ['photo', 'photographie', 'photographe'],
        'music' => ['musique', 'musicien', 'guitare', 'piano'],
        'gaming' => ['jeux vidéo', 'jeux video', 'gamer', 'gaming'],
        'reading' => ['lecture', 'livres', 'lire', 'lecteur', 'lectrice'],
        'fitness' => ['sport', 'fitness', 'musculation'],
        'outdoors' => ['plein air', 'camping', 'randonnée', 'randonnee'],
        'travel' => ['voyage', 'voyages', 'voyager', 'voyageur', 'voyageuse'],
        'gardening' => ['jardinage', 'jardin', 'plantes', 'jardiner', 'jardinier', 'jardinière'],
        'diy' => ['bricolage', 'bricoler', 'bricoleur', 'outils'],
        'beauty' => ['beauté', 'beaute', 'maquillage', 'soins'],
        'fashion' => ['mode', 'vêtements', 'vetements'],
        'tech' => ['technologie', 'tech', 'high-tech'],
        'home' => ['déco', 'deco', 'intérieur', 'maison'],
        'craft' => ['loisirs créatifs', 'tricot', 'couture', 'crochet', 'bricolage créatif'],
        'film' => ['films', 'séries', 'series', 'cinéma', 'cinema'],
        'pets' => ['chien', 'chat', 'animaux', 'animal'],
        'wellness' => ['détente', 'detente', 'bien-être', 'spa'],
        'art' => ['dessin', 'peinture', 'art', 'artiste'],
        'cycling' => ['vélo', 'velo', 'cyclisme', 'cycliste'],
        'boardgames' => ['jeux de société', 'jeux de societe'],
        'drinks' => ['vin', 'bière', 'biere', 'whisky', 'gin', 'cocktails'],
        'baking' => ['pâtisserie', 'patisserie', 'gâteaux'],
        'running' => ['course à pied', 'running', 'jogging', 'coureur'],
        'yoga' => ['yoga', 'méditation', 'meditation'],
        'cars' => ['voitures', 'voiture', 'auto'],
        'science' => ['science', 'sciences', 'espace', 'astronomie'],
        'water' => ['natation', 'surf', 'voile'],
        'wintersports' => ['ski', 'snowboard', 'sports d\'hiver'],
        'football' => ['foot', 'football'],
        'collecting' => ['collection', 'collectionneur'],
        'nature' => ['nature', 'oiseaux'],
        'fishing' => ['pêche', 'peche', 'pêcheur'],
        'horses' => ['chevaux', 'équitation', 'equitation', 'cheval'],
        'gadgets' => ['gadgets', 'gadget'],
        'it' => ['informatique', 'ordinateur', 'programmation'],
    ],

    // "Someone who has everything": the brief then prefers what gets used up
    // or done over more things to keep (docs/features/has-everything.md).
    // Also a sign that this is a gift search.
    'has_everything' => ['qui a déjà tout', 'qui a deja tout', 'qui ont déjà tout', 'qui ont deja tout', 'a déjà tout', 'a deja tout', 'qui a tout'],

    'under' => ['moins de', 'à moins de', 'jusqu\'à', 'max', 'maximum', 'pas plus de', 'sous'],
    'between' => ['entre'],
    'and' => ['et', 'à'],

    'filler' => ['ma', 'mon', 'mes', 'notre', 'qui', 'aime', 'adore', 'le', 'la', 'les', 'l\'', 'un', 'une', 'de', 'du', 'des', 'pour', 'avec', 'et', 'euro', 'euros', 'eur', 'est', 'passionné', 'passionnée', 'fan'],
];
