<?php

declare(strict_types=1);

/**
 * The list help pages, in Dutch. Serves be-nl and nl-nl.
 *
 * Kept out of site.php on purpose: that file is shipped whole to the browser
 * with every page, and nine pages of prose have no business riding along.
 * These are read on the server by ListHelpController and sent as props.
 *
 * A section body is paragraphs separated by a blank line. A paragraph whose
 * lines all start with "- " is a list; one whose lines all start with "1. "
 * is the numbered steps of an instruction. UI labels are quoted with curly
 * quotes and have to match the interface word for word, so a renamed button
 * is a renamed help line too. A link is written [words](path): the path is
 * relative to the market ("lists", "friends", "lists-help/sharing"), and
 * "cove" means the market's own Cove segment. The controller turns them
 * into real URLs. Link the words a person would search for, to the page
 * that answers them; that is what the owner asked for on 2026-09-08, and it
 * is also what a search engine reads. A section's "shot" names a picture
 * from scripts/help-screenshots.mjs; the key is looked up in
 * ListHelpController::SHOTS.
 */
return [
    'index' => [
        'title' => 'Hoe lijsten werken',
        'seo_title' => 'Hoe lijsten werken',
        'seo_description' => 'Alles wat je met een lijst kunt: bewaren, delen, samen kopen, Geheime Vriend, vrienden en herinneringen. Stap voor stap, met beeld.',
        'intro' => 'Een [verlanglijst](lists) bewaart wat je hier [vindt](search), voor jezelf of voor iemand anders. Hieronder staat per onderwerp wat er kan en hoe je het doet, met beeld. Begin bij het eerste als je nog nooit iets bewaard hebt.',
        'back' => 'Alle onderwerpen',
        'next' => 'Volgende',
        'cta_search' => 'Zoek iets om te bewaren',
        'cta_lists' => 'Naar mijn lijsten',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Bewaren en een lijst maken',
            'blurb' => 'Iets vinden, bewaren, je lijsten openen, en een lijst maken in drie stappen.',
            'seo_description' => 'Bewaar alles wat je hier vindt in een verlanglijst en maak er een in drie stappen. Met beeld.',
            'intro' => 'Je hoeft niet eerst een lijst te maken. Bij het bewaren wordt het aangeboden, en op de startpagina staat een knop die er in drie stappen een maakt.',
            'numbered' => true,
            'sections' => [
                [
                    'title' => 'Vind iets dat je wilt bewaren',
                    'body' => '[Zoek](search), of blader door een [Cove](cove). Op elke productkaart staat in de foto een bladwijzer.',
                    'shot' => 'find',
                    'alt' => 'Twee productkaarten, elk met een bladwijzerknop in de foto.',
                ],
                [
                    'title' => 'Bewaar het en kies een lijst',
                    'body' => 'Tik op de bladwijzer en het staat in je lijst. Tik nog eens om een andere lijst te kiezen of een nieuwe te beginnen. Op een computer staat naast de bladwijzer een pijltje dat dat venster meteen opent.',
                    'shot' => 'choose',
                    'alt' => 'Het geopende venster naast een product, met de lijsten om in te bewaren en de optie om een nieuwe lijst te beginnen.',
                ],
                [
                    'title' => 'Open je lijsten',
                    'body' => 'Alles wat je bewaarde staat onder [Mijn lijsten](lists). Je ziet per lijst wat erin zit, of het privé is en voor wie het bedoeld is. Zakt de prijs van iets, dan zie je dat op de kaart.',
                    'shot' => 'lists',
                    'alt' => 'De pagina met mijn lijsten, met twee lijsten en de knop waarmee je er een maakt.',
                ],
                [
                    'title' => 'Een lijst maken in drie stappen',
                    'body' => "1. Tik op “Nieuwe lijst” onder [Mijn lijsten](lists), of op “Maak een nieuwe lijst” op de startpagina.\n2. Kies voor wie het is: “Voor mezelf”, “Voor iemand anders” of “Samen, voor iemand”. Tik op “Volgende”.\n3. Geef de lijst een naam en een gelegenheid. Tik op “Volgende”.\n4. Kies “Privé (of deel later)” of “Delen via een link”, en tik op “Lijst maken”.\n\nOf sla dit over: tik bij het bewaren op de bladwijzer en kies daar voor een nieuwe lijst. Wat je aan het bewaren was staat er meteen in.\n\nEén keuze ligt daarna vast: voor wie het is. Al het andere verander je later nog. Wat de drie soorten kunnen, staat bij [Verlanglijst, cadeaulijst of groepscadeau](lists-help/kinds).",
                    'shot' => 'wizard',
                    'alt' => 'De eerste stap van een nieuwe lijst, met de drie keuzes voor wie het is.',
                ],
                [
                    'title' => 'Je hoeft niet ingelogd te zijn om te beginnen',
                    'body' => 'De drie stappen kun je zonder account doorlopen. Aan het eind log je in en de lijst staat er. Wat je invulde blijft een dag bewaard, dus even weglopen kan.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Verlanglijst, cadeaulijst of groepscadeau',
            'blurb' => 'De ene keuze die vastligt, en wat elk soort lijst kan.',
            'seo_description' => 'Drie soorten lijsten: een verlanglijst voor jezelf, een cadeaulijst voor iemand anders, of een groepscadeau. Wat elk kan en wat vastligt.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Eén keuze ligt vast',
                    'body' => 'De eerste stap van een [nieuwe lijst](lists-help/saving) vraagt voor wie het is. Dat bepaalt wat de lijst kan, en het is het enige wat je later niet meer verandert. Naam, gelegenheid en wie het ziet pas je altijd nog aan.',
                    'shot' => 'wizard',
                    'alt' => 'De eerste stap van een nieuwe lijst, met de drie soorten om uit te kiezen.',
                ],
                [
                    'title' => 'Voor mezelf: een verlanglijst',
                    'body' => 'Wat jij wilt hebben. [Deel je hem](lists-help/sharing), dan kunnen anderen aanvinken wat ze kopen, en jij ziet niet wat of wie. Zo blijft de verrassing. Wil je het toch weten, dan zet je dat per lijst aan.',
                ],
                [
                    'title' => 'Voor iemand anders: een cadeaulijst',
                    'body' => 'Ideeën voor iemand die de lijst zelf nooit opent. Wie je hem deelt, vinkt aan wat hij [koopt](lists-help/claiming), zodat niemand dubbel koopt. Jij ziet dat wel, want jij geeft mee.',
                ],
                [
                    'title' => 'Samen, voor iemand: een groepscadeau',
                    'body' => 'Eén cadeau, meerdere gevers. Iedereen met de link kan ideeën toevoegen, erop stemmen en zeggen wat hij bijdraagt. Er wordt hier geen geld verstuurd; dat regelen jullie onderling. Meer daarover bij [Samen een cadeau kopen](lists-help/group).',
                ],
                [
                    'title' => 'Een gelegenheid en een datum',
                    'body' => 'Elke lijst kan een gelegenheid dragen: verjaardag, kerst, huwelijk, geboorte, en nog tien andere. Bij een verjaardag, kerst en valentijn vult de datum zichzelf in. Met een datum krijg je op tijd een [herinnering](lists-help/friends).',
                    'shot' => 'occasion',
                    'alt' => 'Het venster Gelegenheid van een lijst, met de keuze van de gelegenheid en de datum.',
                ],
            ],
        ],

        'items' => [
            'title' => 'Wat er op een lijst kan',
            'blurb' => 'Producten van hier, eigen items met een link, kopiëren, aanpassen, en de prijs die zakt.',
            'seo_description' => 'Producten bewaren, eigen items toevoegen met een link en een prijs, kopiëren naar een andere lijst, en zien wanneer de prijs zakt.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'De bladwijzer',
                    'body' => 'Op elke productkaart, bij het [zoeken](search) en in elke [Cove](cove). Eén tik bewaart in de lijst waar je het laatst iets in bewaarde, anders in je standaardlijst. Nog een tik opent het venster: daar kies je een andere lijst, verplaats je het, of haal je het eraf. Op een computer staat naast de bladwijzer een pijltje dat het venster meteen opent.',
                ],
                [
                    'title' => 'Toevoegen vanuit de lijst',
                    'body' => "1. Open je lijst onder [Mijn lijsten](lists).\n2. Tik op “+ Product toevoegen”.\n3. Typ wat je zoekt en druk op Enter, of tik op het scan-icoon en richt je camera op de streepjescode.\n4. Tik op het product in de resultaten. Het staat meteen op je lijst.",
                    'shot' => 'add',
                    'alt' => 'Het zoekvak bovenaan een lijst om een product toe te voegen, met daaronder de link om het er zelf op te zetten.',
                ],
                [
                    'title' => 'Iets dat hier niet te vinden is',
                    'body' => "1. Tik op “+ Product toevoegen”.\n2. Kies onder het zoekvak “Zet het er zelf op”.\n3. Vul in wat het is. Een link, een prijs en een omschrijving zoals “maat M, in het blauw” mogen erbij.\n4. Bewaar het.\n\nEigen items kun je later aanpassen met “Aanpassen”. Producten uit de catalogus niet: hun titel en prijs komen van de winkel.",
                ],
                [
                    'title' => 'Kopiëren, niet verplaatsen',
                    'body' => 'Elk item heeft “Kopieer naar een andere lijst”. Op een [gedeelde lijst](lists-help/claiming) van iemand anders heet dat “Zet op mijn lijst”. Het origineel blijft staan; de omschrijving en de prijs gaan mee, wie het al koopt niet.',
                ],
                [
                    'title' => 'Als de prijs zakt',
                    'body' => 'Een bewaard product onthoudt de prijs van dat moment. Zakt hij, dan staat op de kaart de nieuwe prijs met de oude doorgestreept. Je hoeft er niets voor in te stellen. Meer bij [Prijzen en voorraad in de gaten houden](lists-help/alerts).',
                    'shot' => 'drop',
                    'alt' => 'Een item op een lijst waarvan de prijs zakte, met de nieuwe prijs en de oude doorgestreept.',
                ],
                [
                    'title' => 'Verwijderen',
                    'body' => 'Tik op het kruisje bij het item en bevestig. Verwijderen kan alleen wie de lijst beheert. Het nieuwste staat bovenaan.',
                ],
            ],
        ],

        'sharing' => [
            'title' => 'Een lijst delen',
            'blurb' => 'Met een link of met vrienden op naam, wie wat te zien krijgt, en hoe je het weer stopt.',
            'seo_description' => 'Een verlanglijst delen met een link of met vrienden, bepalen wie mag toevoegen en wie ziet wat er gekocht is, en het delen weer stoppen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Privé tot je het deelt',
                    'body' => 'Een nieuwe lijst ziet alleen jij. Niets deelt hem stilletjes: geen gelegenheid, geen vriend, geen quiz. Delen doe je zelf, met “Delen” op de lijst.',
                ],
                [
                    'title' => 'Met een link',
                    'body' => "1. Open je lijst en tik op “Delen”.\n2. Zet hem op “Delen via een link” als hij nog privé is.\n3. Tik op “Link kopiëren” en plak hem in een bericht. Of tik op “Kopieer bericht en link” voor een kant-en-klaar berichtje, of op “Delen” om WhatsApp, Telegram, e-mail of een andere app te kiezen.\n\nIedereen met de link ziet de lijst. “Stop met delen” maakt elke verstuurde link ongeldig; deel je opnieuw, dan krijg je een nieuwe.",
                    'shot' => 'share',
                    'alt' => 'Het deelvenster van een lijst, met de link, de knop om hem te kopiëren en de knop om te stoppen met delen.',
                ],
                [
                    'title' => 'Met vrienden op naam',
                    'body' => "1. Tik op “Delen” en dan op “Delen met vrienden”.\n2. Kies de [vrienden](friends) die hem mogen zien.\n3. Tik op “Versturen”.\n\nZij krijgen een mailtje met de link, zonder de inhoud, en de lijst staat op hun [vriendenpagina](friends). “Niet meer delen met …” haalt het daar weg; een link die ze al hadden, blijft werken tot je stopt met delen. Hoe je vrienden wordt, staat bij [Vrienden, verjaardagen en herinneringen](lists-help/friends).",
                    'shot' => 'friends-share',
                    'alt' => 'Het deel van het deelvenster waar je vrienden kiest en de link naar hen verstuurt.',
                ],
                [
                    'title' => 'Wie mag toevoegen',
                    'body' => 'Staat “Iedereen kan cadeaus toevoegen” aan, dan zet wie de link heeft meteen iets op de lijst. Staat het uit, dan komen voorstellen bij jou terecht en beslis jij. Zelfgeschreven items wachten altijd op jou.',
                ],
                [
                    'title' => 'Wie ziet wat er gekocht is',
                    'body' => 'Op een [verlanglijst](lists-help/kinds) zie jij niet wat er gereserveerd is. Dat staat standaard uit en zet je per lijst aan met “Laat mij zien wat er gereserveerd is”. Op een cadeaulijst staat het aan, want jij geeft mee. Namen van wie wat koopt zijn standaard verborgen; zet je ze aan, dan geldt dat alleen voor nieuwe reserveringen.',
                ],
                [
                    'title' => 'Bezorgadres',
                    'body' => 'Op je eigen verlanglijst kun je een bezorgadres bewaren. Het wordt versleuteld opgeslagen en verschijnt alleen voor wie iets [gereserveerd](lists-help/claiming) heeft. Laat die persoon de reservering los, dan verdwijnt het weer.',
                ],
            ],
        ],

        'claiming' => [
            'title' => 'Iets kopen van een gedeelde lijst',
            'blurb' => 'Reserveren, loslaten, gekocht melden, iets voorstellen, en de quiz.',
            'seo_description' => 'Wat je kunt op een verlanglijst die iemand met je deelde: reserveren wat je koopt, iets voorstellen, en de quiz spelen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Reserveren',
                    'body' => "1. Open de link naar de [gedeelde lijst](lists-help/sharing) die je kreeg.\n2. Tik bij het cadeau dat je koopt op “Ik koop dit”.\n3. Log in als daarom gevraagd wordt; je klik wordt daarna alsnog uitgevoerd.\n\nZo koopt niemand anders het ook. Degene voor wie de lijst is, ziet er niets van.",
                    'shot' => 'shared',
                    'alt' => 'Twee cadeaus op een gedeelde lijst, elk met de knop “Ik koop dit”.',
                ],
                [
                    'title' => 'Toch niet, of gekocht',
                    'body' => '“Toch niet” laat het weer los, wanneer je maar wilt. “Ik heb het gekocht” zet het op gekocht. Bovenaan zie je hoeveel er al gereserveerd is.',
                ],
                [
                    'title' => 'Zelf iets voorstellen',
                    'body' => "1. Zoek onderaan de lijst naar wat je wilt voorstellen, of omschrijf het zelf.\n2. Tik op “Stel iets voor”, of op “Aan de lijst toevoegen” waar dat meteen mag.\n\nWie de lijst beheert, ziet je voorstel en beslist. Of het meteen mag, staat bij [Een lijst delen](lists-help/sharing).",
                ],
                [
                    'title' => 'Bewaar het ook voor jezelf',
                    'body' => 'Elk item heeft een bladwijzer en “Zet op mijn lijst”. Wat je kopieert, komt zonder reservering op [jouw lijst](lists).',
                ],
            ],
        ],

        'quiz' => [
            'title' => 'De quiz: hoe goed ken je ze?',
            'blurb' => 'Een spelletje van je gedeelde lijst: vier producten, één staat er echt op.',
            'seo_description' => 'Maak een quiz van je verlanglijst: wie kent je het best? Vijf rondes, een score om te delen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Wat de quiz is',
                    'body' => 'Een spelletje van een gedeelde [verlanglijst](lists-help/kinds). Vijf rondes, telkens vier producten waarvan er één echt op de lijst staat, en aan het eind een score om te delen. Wie de lijst beheert, maakt de quiz; wie de link krijgt, speelt.',
                ],
                [
                    'title' => 'Een quiz maken',
                    'body' => '1. Open je lijst. Het moet [gedeeld](lists-help/sharing) zijn en minstens vijf items hebben.
2. Tik op “Quiz”.
3. Tik op “Maak een quiz van deze lijst”.
4. Stuur de link rond.',
                    'shot' => 'quiz',
                    'alt' => 'Het quizvenster van een lijst, met de knop om er een quiz van te maken.',
                ],
                [
                    'title' => 'Meespelen',
                    'body' => '1. Open de quizlink.
2. Kies in elke ronde welk van de vier producten echt op de lijst staat.
3. Tik op “Bekijk je score”, en daarna op “Deel je score” als je wilt.

Iedereen speelt één keer.',
                ],
                [
                    'title' => 'Wat jij ziet als maker',
                    'body' => 'Jij speelt niet mee; dat zou valsspelen zijn. Je ziet hoeveel mensen speelden en de gemiddelde score, niet wie wat antwoordde.',
                ],
            ],
        ],

        'group' => [
            'title' => 'Samen een cadeau kopen',
            'blurb' => 'Groepscadeau, stemmen, bijdragen, en overleggen met wie meedoet.',
            'seo_description' => 'Met meerdere mensen één cadeau kopen: ideeën verzamelen, stemmen, bijdragen afspreken en overleggen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Een groepscadeau',
                    'body' => "1. Maak een [nieuwe lijst](lists-help/saving) en kies in de eerste stap “Samen, voor iemand”.\n2. Zeg voor wie het is en geef hem een naam.\n3. Deel de link met wie meedoet.\n\nIedereen met de link kan ideeën toevoegen en erop stemmen. Er valt niets te reserveren: het is één [groepscadeau](lists-help/kinds) van jullie samen. Lootjes trekken is iets anders; dat staat bij [Geheime Vriend](lists-help/santa).",
                    'shot' => 'group',
                    'alt' => 'De pagina van een groepscadeau, met de ideeën om op te stemmen en het vak om bij te dragen.',
                ],
                [
                    'title' => 'Stemmen',
                    'body' => 'Elk idee heeft “Stem hierop” en een teller. De volgorde verandert niet terwijl je kijkt; de tellers wel.',
                ],
                [
                    'title' => 'Bijdragen',
                    'body' => 'Bij “Hoe iedereen bijdraagt” kies je: iedereen kiest zelf een bedrag, of iedereen evenveel. Wie meedoet, tikt “Ik doe mee” en ziet zijn eigen deel en het totaal. Alleen de organisator ziet wie wat bijdraagt, tenzij die “Iedereen ziet wie bijdraagt” aanzet. Er wordt hier geen geld verstuurd; dat regelen jullie onderling.',
                ],
                [
                    'title' => 'Overleggen',
                    'body' => 'Naast de lijst staat “Overleg”, een gesprek voor iedereen met de link. Degene voor wie de lijst is, leest niet mee. Je plaatst een bericht met je naam; je eigen berichten kun je weghalen, de beheerder alle. Op een privé lijst is er geen overleg.',
                ],
            ],
        ],

        'santa' => [
            'title' => 'Geheime Vriend',
            'blurb' => 'Lootjes trekken zonder briefjes: een groep, een budget, een datum, en iedereen krijgt één naam.',
            'seo_description' => 'Lootjes trekken voor Geheime Vriend of Secret Santa: start een groep, nodig iedereen uit met een link, trek, en koppel een verlanglijst.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Een groep starten',
                    'body' => "1. Ga naar [Geheime Vriend](santa).\n2. Tik op “Start een groep”.\n3. Geef de groep een naam, een budget en de datum waarop jullie de cadeaus geven.\n\nJij bent de organisator.",
                    'shot' => 'santa',
                    'alt' => 'De pagina Geheime Vriend, met de knop om een groep te starten.',
                ],
                [
                    'title' => 'Iedereen uitnodigen',
                    'body' => "1. Kopieer de uitnodigingslink van de groep.\n2. Stuur hem naar iedereen die meedoet.\n3. Wie de link opent, vult een naam en een e-mailadres in. Een account is niet nodig.",
                ],
                [
                    'title' => 'Trekken',
                    'body' => "1. Wacht tot iedereen erbij staat, minstens twee mensen.\n2. Tik op “Trekken”.\n\nIedereen krijgt per mail één naam. De organisator ziet de koppels niet, dus ook jij blijft verrast.",
                ],
                [
                    'title' => 'Als er iemand afvalt',
                    'body' => 'Haal die persoon uit de groep, of kies “Opnieuw trekken voor deze persoon”. Alleen de koppels die het raakt worden opnieuw getrokken, en alleen die mensen krijgen een nieuwe mail.',
                ],
                [
                    'title' => 'Mijn verlanglijst koppelen aan de groep',
                    'body' => "Kies je lijst onder “Je verlanglijst” als je de groep start, of daarna op de groepspagina. Andersom kan ook: open je [verlanglijst](lists) en tik op “Gebruik deze lijst” bij de groep.\n\nWie jou trok, ziet zo wat je graag hebt, zonder dat jij ziet wie het is. Nog geen lijst? [Maak er een](lists-help/saving) in drie stappen.",
                ],
                [
                    'title' => 'Een herinnering vooraf',
                    'body' => 'Dertig, vijftien en twee dagen voor de datum krijg je een seintje, hier en per mail. Meer bij [Vrienden, verjaardagen en herinneringen](lists-help/friends).',
                ],
            ],
        ],

        'friends' => [
            'title' => 'Vrienden, verjaardagen en herinneringen',
            'blurb' => 'Wie je vrienden zijn, wat zij zien, en wanneer je een seintje krijgt.',
            'seo_description' => 'Vrienden toevoegen, verjaardagen bewaren, en op tijd een herinnering krijgen voor een verjaardag of een gelegenheid.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Vrienden worden',
                    'body' => "Opent iemand jouw deellink terwijl hij ingelogd is, dan zijn jullie [vrienden](friends). Iemand zelf toevoegen gaat zo:\n\n1. Ga naar [Vrienden](friends).\n2. Vul onder “Iemand toevoegen” een e-mailadres in, en als je wilt de verjaardag.\n3. Tik op “Toevoegen”.\n\nDie persoon krijgt daar geen mail van. Heeft hij al een account, dan zijn jullie meteen verbonden; anders zodra hij inlogt.",
                    'shot' => 'friends',
                    'alt' => 'De vriendenpagina, met het formulier om iemand toe te voegen op e-mailadres.',
                ],
                [
                    'title' => 'Wat een vriend ziet',
                    'body' => 'Op de [vriendenpagina](friends) staat per vriend zijn verjaardag, de lijsten die hij met jou deelde en welke van jouw lijsten hij ziet. Wat er gereserveerd is, staat daar nooit. Een vriend verwijderen haalt de band aan beide kanten weg; lijsten en reserveringen blijven staan.',
                ],
                [
                    'title' => 'Verjaardagen',
                    'body' => 'Bij een vriend bewaar je dag en maand, nooit een jaar. Het is jouw notitie. Je eigen verjaardag vul je in bij je account, met de keuze of vrienden hem mogen zien.',
                ],
                [
                    'title' => 'Herinneringen',
                    'body' => 'Dertig, vijftien en twee dagen vooraf krijg je een seintje bij een verjaardag, de datum van een [Geheime Vriend](lists-help/santa) en de [gelegenheid](lists-help/kinds) van een lijst. Hier en per mail. De mail noemt de datum en de link, nooit wat er op de lijst staat.',
                ],
                [
                    'title' => 'Meldingen',
                    'body' => 'Onder [Meldingen](notifications) zie je wat er gebeurde: iemand deelde een lijst met je, voegde iets toe of stelde iets voor, er is een nieuw bericht in het overleg, iets is weer op voorraad, een zoekopdracht die je volgt heeft iets nieuws. Openen zet alles op gelezen.',
                ],
            ],
        ],

        'alerts' => [
            'title' => 'Prijzen en voorraad in de gaten houden',
            'blurb' => 'De prijs die zakt, een product dat terugkomt, en een zoekopdracht die je volgt.',
            'seo_description' => 'Zien wanneer een prijs zakt, een seintje krijgen als een product weer op voorraad is, en een zoekopdracht volgen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Bewaar het, en de prijs volgt vanzelf',
                    'body' => 'Een product op je [lijst](lists) onthoudt de prijs van dat moment. Zakt hij, dan zie je het op de kaart, met de oude prijs doorgestreept. Meer hoeft niet.',
                ],
                [
                    'title' => 'Weer op voorraad',
                    'body' => "1. Open de productpagina van iets dat nergens meer te koop is.\n2. Tik op “Laat het weten als hij er weer is”.\n\nJe krijgt een seintje zodra een winkel het weer heeft. Stoppen kan op dezelfde plek met “Niet meer volgen”.",
                ],
                [
                    'title' => 'Een zoekopdracht volgen',
                    'body' => "1. [Zoek](search) wat je wilt volgen.\n2. Tik boven de resultaten op “Hou me op de hoogte”.\n3. Vul eventueel een maximumprijs in en tik op “Deze zoekopdracht volgen”.\n\nElke ochtend kijken we of er iets nieuws bij is dat erop past, en dat zie je bij [Meldingen](notifications). Stoppen doe je op dezelfde zoekpagina met “Stoppen”.",
                    'shot' => 'watch',
                    'alt' => 'De knop om een zoekopdracht te volgen, met daaronder het veld voor een maximumprijs en de knop om te bevestigen.',
                ],
            ],
        ],
    ],
];
