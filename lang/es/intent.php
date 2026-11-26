<?php

/*
 * The words GiftIntentParser reads a gift search with, in Spanish.
 * See lang/nl/intent.php for how the lists are used.
 */

return [
    'triggers' => ['regalo para', 'regalos para', 'ideas de regalo para', 'algo para', 'detalle para', 'obsequio para', 'regalo', 'regalos', 'obsequio'],

    'recipients' => [
        'sibling' => ['hermana', 'hermano', 'hermanos'],
        'mother' => ['mamá', 'mama', 'madre', 'suegra'],
        'father' => ['papá', 'papa', 'padre', 'suegro'],
        'partner' => ['novia', 'novio', 'mi mujer', 'marido', 'esposa', 'esposo', 'pareja'],
        'grandparent' => ['abuela', 'abuelo', 'abuelos', 'abuelita', 'abuelito', 'yaya', 'yayo'],
        'child' => ['hijo', 'hija', 'niño', 'niña', 'niños', 'bebé', 'bebe', 'adolescente', 'sobrino', 'sobrina', 'ahijado', 'ahijada', 'nieto', 'nieta'],
        'friend' => ['mejor amiga', 'mejor amigo', 'amiga', 'amigo', 'amigos', 'vecino', 'vecina'],
        'colleague' => ['compañera', 'compañero', 'colega', 'jefe', 'jefa'],
        'teacher' => ['profesora', 'profesor', 'profe', 'maestra', 'maestro'],
        'host' => ['anfitriona', 'anfitrión', 'anfitrion', 'anfitriones'],
    ],

    'occasions' => [
        'birthday' => ['cumpleaños', 'cumple', 'regalo de cumpleaños'],
        'christmas' => ['navidad', 'navidades', 'reyes', 'reyes magos', 'regalo de navidad', 'papá noel'],
        'wedding' => ['boda', 'bodas', 'casamiento', 'regalo de boda'],
        'anniversary' => ['aniversario', 'bodas de oro', 'bodas de plata'],
        'baby' => ['nacimiento', 'baby shower', 'recién nacido', 'recien nacido', 'bautizo'],
        'housewarming' => ['casa nueva', 'mudanza', 'inauguración'],
        'graduation' => ['graduación', 'graduacion'],
        'retirement' => ['jubilación', 'jubilacion'],
        'farewell' => ['despedida'],
        'valentines' => ['san valentín', 'san valentin'],
        'mothers_day' => ['día de la madre', 'dia de la madre'],
        'fathers_day' => ['día del padre', 'dia del padre'],
        'thank_you' => ['agradecimiento', 'gracias', 'para agradecer'],
    ],

    'interests' => [
        'cooking' => ['cocina', 'cocinar', 'cocinero', 'cocinera'],
        'coffee' => ['café', 'cafe', 'té'],
        'photography' => ['fotografía', 'fotografia', 'fotos', 'fotógrafo'],
        'music' => ['música', 'musica', 'músico', 'guitarra', 'piano'],
        'gaming' => ['videojuegos', 'gamer', 'gaming', 'consola', 'playstation', 'nintendo', 'xbox'],
        'reading' => ['lectura', 'libros', 'leer', 'lector', 'lectora'],
        'fitness' => ['deporte', 'gimnasio', 'fitness'],
        'outdoors' => ['aire libre', 'acampar', 'senderismo', 'montaña', 'camping'],
        'travel' => ['viajes', 'viajar', 'viajero', 'viajera'],
        'gardening' => ['jardinería', 'jardineria', 'jardín', 'jardin', 'plantas'],
        'diy' => ['bricolaje', 'herramientas', 'carpintería', 'carpinteria'],
        'beauty' => ['belleza', 'maquillaje', 'cosmética', 'cosmetica', 'cuidado de la piel'],
        'fashion' => ['moda', 'ropa'],
        'tech' => ['tecnología', 'tecnologia', 'tecnológico'],
        'home' => ['decoración', 'decoracion', 'hogar'],
        'craft' => ['manualidades', 'punto', 'costura', 'ganchillo'],
        'film' => ['películas', 'peliculas', 'series', 'cine'],
        'pets' => ['perro', 'gato', 'mascotas', 'mascota'],
        'wellness' => ['relax', 'bienestar', 'spa'],
        'art' => ['dibujo', 'pintura', 'arte', 'artista'],
        'cycling' => ['ciclismo', 'bici', 'bicicleta', 'ciclista', 'bicicleta de montaña'],
        'boardgames' => ['juegos de mesa', 'juegos de cartas'],
        'puzzles' => ['puzles', 'rompecabezas'],
        'delicacies' => ['gastronomía', 'gastronomia', 'gourmet'],
        'motorcycles' => ['moto', 'motos', 'motero', 'motera'],
        'needlework' => ['punto', 'ganchillo', 'costura', 'bordado'],
        'drinks' => ['vino', 'cerveza', 'whisky', 'gin', 'cócteles'],
        'baking' => ['repostería', 'reposteria', 'hornear'],
        'running' => ['correr', 'running', 'corredor', 'corredora'],
        'yoga' => ['yoga', 'meditación', 'meditacion'],
        'cars' => ['coches', 'coche'],
        'science' => ['ciencia', 'espacio', 'astronomía'],
        'water' => ['natación', 'natacion', 'surf', 'vela'],
        'wintersports' => ['esquí', 'esqui', 'snowboard', 'deportes de invierno'],
        'football' => ['fútbol', 'futbol'],
        'golf' => ['golf', 'golfista'],
        'racket' => ['pádel', 'padel', 'tenis', 'bádminton'],
        'collecting' => ['coleccionar', 'coleccionista'],
        'nature' => ['naturaleza', 'pájaros', 'aves'],
        'fishing' => ['pesca', 'pescar', 'pescador'],
        'horses' => ['caballos', 'equitación', 'caballo'],
        'gadgets' => ['gadgets', 'gadget'],
        'it' => ['informática', 'informatica', 'ordenador', 'programación'],
    ],

    // "Someone who has everything": the brief then prefers what gets used up
    // or done over more things to keep (docs/features/has-everything.md).
    // Also a sign that this is a gift search.
    'has_everything' => ['que ya lo tiene todo', 'que lo tiene todo', 'que ya tiene todo', 'ya lo tiene todo', 'lo tiene todo', 'que tiene de todo', 'tiene de todo'],

    'under' => ['menos de', 'hasta', 'máximo', 'maximo', 'max', 'no más de', 'por debajo de'],
    'between' => ['entre'],
    'and' => ['y', 'a'],

    'filler' => ['mi', 'mis', 'nuestra', 'nuestro', 'que', 'le', 'gusta', 'encanta', 'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'para', 'con', 'y', 'euro', 'euros', 'eur', 'es', 'a', 'al', 'fan'],
];
