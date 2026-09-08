<?php

declare(strict_types=1);

/**
 * The list help pages, in Dutch. Serves be-nl and nl-nl.
 *
 * Kept out of site.php on purpose: that file is shipped whole to the browser
 * with every page, and nine pages of prose have no business riding along.
 * These are read on the server by ListHelpController and sent as props.
 *
 * A section body is paragraphs separated by a blank line; a paragraph whose
 * lines all start with "- " is a list. UI labels are quoted with curly
 * quotes. A link is written [words](path): the path is relative to the
 * market ("lists", "friends", "lists-help/sharing"), and "cove" means the
 * market's own Cove segment. The controller turns them into real URLs. Link
 * the words a person would search for, to the page that answers them; that
 * is what the owner asked for on 2026-09-08, and it is also what a search
 * engine reads.
 */
return [
    'index' => [
        'title' => 'Hoe lijstjes werken',
        'seo_title' => 'Hoe lijstjes werken',
        'seo_description' => 'Alles wat je met een lijstje kunt: bewaren, delen, samen kopen, Geheime Vriend, vrienden en herinneringen. Per onderwerp uitgelegd.',
        'intro' => 'Een lijstje bewaart wat je hier vindt, voor jezelf of voor iemand anders. Hieronder staat per onderwerp wat er kan. Begin bij het eerste als je nog nooit iets bewaard hebt.',
        'back' => 'Alle onderwerpen',
        'next' => 'Volgende',
        'cta_search' => 'Zoek iets om te bewaren',
        'cta_lists' => 'Naar mijn lijstjes',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Bewaren en een lijstje maken',
            'blurb' => 'Drie stappen, met beeld: iets vinden, bewaren, en je lijstjes openen.',
            'seo_description' => 'Bewaar alles wat je hier vindt in een verlanglijstje en maak er een in drie stappen. Met beeld.',
            'intro' => 'Je hoeft niet eerst een lijstje te maken. Bij het bewaren wordt het aangeboden, en op de startpagina staat een knop die er in drie stappen een maakt.',
            'numbered' => true,
            'sections' => [
                [
                    'title' => 'Vind iets dat je wilt bewaren',
                    'body' => '[Zoek](search), of blader door een [Cove](cove). Op elke productkaart staat in de foto een bladwijzer.',
                    'shot' => 'find',
                    'alt' => 'Twee productkaarten, elk met een bladwijzerknop in de foto.',
                ],
                [
                    'title' => 'Bewaar het en kies een lijstje',
                    'body' => 'Tik op de bladwijzer en het staat in je lijstje. Tik nog eens om een ander lijstje te kiezen of een nieuw te beginnen. Op een computer staat naast de bladwijzer een pijltje dat dat venster meteen opent.',
                    'shot' => 'choose',
                    'alt' => 'Het geopende venster naast een product, met de lijstjes om in te bewaren en de optie om een nieuw lijstje te beginnen.',
                ],
                [
                    'title' => 'Open je lijstjes',
                    'body' => 'Alles wat je bewaarde staat onder [Mijn lijstjes](lists). Je ziet per lijstje wat erin zit, of het privé is en voor wie het bedoeld is. Zakt de prijs van iets, dan zie je dat op de kaart.',
                    'shot' => 'lists',
                    'alt' => 'De pagina met mijn lijstjes, met twee lijstjes en de knop waarmee je er een maakt.',
                ],
                [
                    'title' => 'Een lijstje maken',
                    'body' => "Op twee manieren, en de eerste gebruikt bijna iedereen.\n\n- Tijdens het bewaren: tik op de bladwijzer en kies voor een nieuw lijstje. Wat je aan het bewaren was staat er meteen in.\n- Met de knop “Maak een nieuw lijstje” op de startpagina of “Nieuw lijstje” onder [Mijn lijstjes](lists): drie stappen, voor wie het is, naam en gelegenheid, en of het privé blijft of je het later deelt.\n\nEén keuze ligt daarna vast: voor wie het is. Al het andere verander je later nog. Wat de drie soorten kunnen, staat bij [Verlanglijst, cadeaulijst of groepscadeau](lists-help/kinds).",
                ],
                [
                    'title' => 'Je hoeft niet ingelogd te zijn om te beginnen',
                    'body' => 'De drie stappen kun je zonder account doorlopen. Aan het eind log je in en het lijstje staat er. Wat je invulde blijft een dag bewaard, dus even weglopen kan.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Verlanglijst, cadeaulijst of groepscadeau',
            'blurb' => 'De ene keuze die vastligt, en wat elk soort lijstje kan.',
            'seo_description' => 'Drie soorten lijstjes: een verlanglijst voor jezelf, een cadeaulijst voor iemand anders, of een groepscadeau. Wat elk kan en wat vastligt.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Eén keuze ligt vast',
                    'body' => 'De eerste stap van een [nieuw lijstje](lists-help/saving) vraagt voor wie het is. Dat bepaalt wat het lijstje kan, en het is het enige wat je later niet meer verandert. Naam, gelegenheid en wie het ziet pas je altijd nog aan.',
                ],
                [
                    'title' => 'Voor mezelf: een verlanglijst',
                    'body' => 'Wat jij wilt hebben. [Deel je het](lists-help/sharing), dan kunnen anderen aanvinken wat ze kopen, en jij ziet niet wat of wie. Zo blijft de verrassing. Wil je het toch weten, dan zet je dat per lijstje aan.',
                ],
                [
                    'title' => 'Voor iemand anders: een cadeaulijst',
                    'body' => 'Ideeën voor iemand die het lijstje zelf nooit opent. Wie je het deelt, vinkt aan wat hij [koopt](lists-help/claiming), zodat niemand dubbel koopt. Jij ziet dat wel, want jij geeft mee.',
                ],
                [
                    'title' => 'Samen, voor iemand: een groepscadeau',
                    'body' => 'Eén cadeau, meerdere gevers. Iedereen met de link kan ideeën toevoegen, erop stemmen en zeggen wat hij bijdraagt. Er wordt hier geen geld verstuurd; dat regelen jullie onderling. Meer daarover bij [Samen een cadeau kopen](lists-help/group).',
                ],
                [
                    'title' => 'Een gelegenheid en een datum',
                    'body' => 'Elk lijstje kan een gelegenheid dragen: verjaardag, kerst, huwelijk, geboorte, en nog tien andere. Bij een verjaardag, kerst en valentijn vult de datum zichzelf in. Met een datum krijg je op tijd een [herinnering](lists-help/friends).',
                ],
            ],
        ],

        'items' => [
            'title' => 'Wat er op een lijstje kan',
            'blurb' => 'Producten van hier, eigen items met een link, kopiëren, aanpassen, en de prijs die zakt.',
            'seo_description' => 'Producten bewaren, eigen items toevoegen met een link en een prijs, kopiëren naar een ander lijstje, en zien wanneer de prijs zakt.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'De bladwijzer',
                    'body' => 'Op elke productkaart, bij het [zoeken](search) en in elke [Cove](cove). Eén tik bewaart in het lijstje waar je het laatst iets in bewaarde, anders in je standaardlijstje. Nog een tik opent het venster: daar kies je een ander lijstje, verplaats je het, of haal je het eraf. Op een computer staat naast de bladwijzer een pijltje dat het venster meteen opent.',
                ],
                [
                    'title' => 'Toevoegen vanuit het lijstje',
                    'body' => 'Op de pagina van een lijstje staat “Product toevoegen”. Zoek daar, of scan een streepjescode, en wat je kiest staat meteen op dat lijstje.',
                ],
                [
                    'title' => 'Iets dat hier niet te vinden is',
                    'body' => 'Kies bij “Product toevoegen” voor “Zet het er zelf op”. Een naam is genoeg; een link, een prijs en een omschrijving zoals “maat M, in het blauw” mogen erbij. Eigen items kun je later aanpassen. Producten uit de catalogus niet: hun titel en prijs komen van de winkel.',
                ],
                [
                    'title' => 'Kopiëren, niet verplaatsen',
                    'body' => 'Elk item heeft “Kopieer naar een ander lijstje”. Op een [gedeeld lijstje](lists-help/claiming) van iemand anders heet dat “Zet op mijn lijstje”. Het origineel blijft staan; de omschrijving en de prijs gaan mee, wie het al koopt niet.',
                ],
                [
                    'title' => 'Als de prijs zakt',
                    'body' => 'Een bewaard product onthoudt de prijs van dat moment. Zakt hij, dan staat op de kaart de nieuwe prijs met de oude doorgestreept. Je hoeft er niets voor in te stellen. Meer bij [Prijzen en voorraad in de gaten houden](lists-help/alerts).',
                ],
                [
                    'title' => 'Verwijderen',
                    'body' => 'Verwijderen kan alleen wie het lijstje beheert, en vraagt eerst om een bevestiging. Het nieuwste staat bovenaan.',
                ],
            ],
        ],

        'sharing' => [
            'title' => 'Een lijstje delen',
            'blurb' => 'Met een link of met vrienden op naam, wie wat te zien krijgt, en hoe je het weer stopt.',
            'seo_description' => 'Een verlanglijstje delen met een link of met vrienden, bepalen wie mag toevoegen en wie ziet wat er gekocht is, en het delen weer stoppen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Privé tot je het deelt',
                    'body' => 'Een nieuw lijstje ziet alleen jij. Niets deelt het stilletjes: geen gelegenheid, geen vriend, geen quiz. Delen doe je zelf, met “Delen” op het lijstje.',
                ],
                [
                    'title' => 'Met een link',
                    'body' => 'Kopieer de link, of meteen een berichtje met de link erin, of stuur hem via WhatsApp, Telegram, e-mail en meer. Iedereen met de link ziet het lijstje. “Stop met delen” maakt elke verstuurde link ongeldig; deel je opnieuw, dan krijg je een nieuwe.',
                ],
                [
                    'title' => 'Met vrienden op naam',
                    'body' => 'Kies [vrienden](friends) en verstuur. Zij krijgen een mailtje met de link, zonder de inhoud, en het lijstje staat op hun [vriendenpagina](friends). “Niet meer delen met …” haalt het daar weg; een link die ze al hadden, blijft werken tot je stopt met delen. Hoe je vrienden wordt, staat bij [Vrienden, verjaardagen en herinneringen](lists-help/friends).',
                ],
                [
                    'title' => 'Wie mag toevoegen',
                    'body' => 'Staat “Iedereen kan cadeaus toevoegen” aan, dan zet wie de link heeft meteen iets op het lijstje. Staat het uit, dan komen voorstellen bij jou terecht en beslis jij. Zelfgeschreven items wachten altijd op jou.',
                ],
                [
                    'title' => 'Wie ziet wat er gekocht is',
                    'body' => 'Op een [verlanglijst](lists-help/kinds) zie jij niet wat er gereserveerd is. Dat staat standaard uit en zet je per lijstje aan met “Laat mij zien wat er gereserveerd is”. Op een cadeaulijst staat het aan, want jij geeft mee. Namen van wie wat koopt zijn standaard verborgen; zet je ze aan, dan geldt dat alleen voor nieuwe reserveringen.',
                ],
                [
                    'title' => 'Bezorgadres',
                    'body' => 'Op je eigen verlanglijst kun je een bezorgadres bewaren. Het wordt versleuteld opgeslagen en verschijnt alleen voor wie iets [gereserveerd](lists-help/claiming) heeft. Laat die persoon de reservering los, dan verdwijnt het weer.',
                ],
            ],
        ],

        'claiming' => [
            'title' => 'Iets kopen van een gedeeld lijstje',
            'blurb' => 'Reserveren, loslaten, gekocht melden, iets voorstellen, en de quiz.',
            'seo_description' => 'Wat je kunt op een verlanglijstje dat iemand met je deelde: reserveren wat je koopt, iets voorstellen, en de quiz spelen.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Reserveren',
                    'body' => '“Ik koop dit” reserveert het voor jou, zodat niemand anders het ook koopt. Daarvoor heb je een account nodig; log je in, dan wordt je klik alsnog uitgevoerd. Degene voor wie het lijstje is, ziet er niets van.',
                ],
                [
                    'title' => 'Toch niet, of gekocht',
                    'body' => '“Toch niet” laat het weer los, wanneer je maar wilt. “Ik heb het gekocht” zet het op gekocht. Bovenaan zie je hoeveel er al gereserveerd is.',
                ],
                [
                    'title' => 'Zelf iets voorstellen',
                    'body' => 'Zoek onderaan het lijstje en kies “Stel iets voor”, of “Aan de lijst toevoegen” waar dat meteen mag. Je kunt ook iets zelf omschrijven. Wie het lijstje beheert, ziet je voorstel en beslist. Of dat meteen mag, staat bij [Een lijstje delen](lists-help/sharing).',
                ],
                [
                    'title' => 'Bewaar het ook voor jezelf',
                    'body' => 'Elk item heeft een bladwijzer en “Zet op mijn lijstje”. Wat je kopieert, komt zonder reservering op [jouw lijstje](lists).',
                ],
                [
                    'title' => 'De quiz: hoe goed ken je ze?',
                    'body' => 'Op een gedeeld lijstje met minstens vijf items kan de beheerder een quiz maken. Vijf rondes, telkens vier producten waarvan er één echt op het lijstje staat, en een score om te delen. Wie het lijstje beheert, speelt niet mee en ziet alleen hoeveel mensen speelden en de gemiddelde score.',
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
                    'body' => 'Kies bij het [maken](lists-help/saving) “Samen, voor iemand”. Iedereen met de link kan ideeën toevoegen en erop stemmen. Er valt niets te reserveren: het is één cadeau van jullie samen. Lootjes trekken is iets anders; dat staat bij [Geheime Vriend](lists-help/santa).',
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
                    'body' => 'Naast het lijstje staat “Overleg”, een gesprek voor iedereen met de link. Degene voor wie het lijstje is, leest niet mee. Je plaatst een bericht met je naam; je eigen berichten kun je weghalen, de beheerder alle. Op een privé lijstje is er geen overleg.',
                ],
            ],
        ],

        'santa' => [
            'title' => 'Geheime Vriend',
            'blurb' => 'Lootjes trekken zonder briefjes: een groep, een budget, een datum, en iedereen krijgt één naam.',
            'seo_description' => 'Lootjes trekken voor Geheime Vriend of Secret Santa: start een groep, nodig iedereen uit met een link, trek, en koppel een verlanglijstje.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Een groep starten',
                    'body' => 'Ga naar [Geheime Vriend](santa) en kies “Start een groep”. Geef de groep een naam, een budget en de datum waarop jullie de cadeaus geven. Jij bent de organisator.',
                ],
                [
                    'title' => 'Iedereen uitnodigen',
                    'body' => 'Stuur de uitnodigingslink rond. Meedoen kan met een naam en een e-mailadres; een account is niet nodig.',
                ],
                [
                    'title' => 'Trekken',
                    'body' => 'Zodra er minstens twee mensen zijn, kies je “Trekken”. Iedereen krijgt per mail één naam. De organisator ziet de koppels niet, dus ook jij blijft verrast.',
                ],
                [
                    'title' => 'Als er iemand afvalt',
                    'body' => 'Haal die persoon uit de groep, of trek voor één persoon opnieuw. Alleen de koppels die het raakt worden opnieuw getrokken, en alleen die mensen krijgen een nieuwe mail.',
                ],
                [
                    'title' => 'Mijn verlanglijst koppelen aan de groep',
                    'body' => 'Heb je een [verlanglijst](lists), koppel hem dan aan de groep: open je lijstje en kies “Gebruik dit lijstje”. Wie jou trok, ziet zo wat je graag hebt, zonder dat jij ziet wie het is. Nog geen lijstje? [Maak er een](lists-help/saving) in drie stappen.',
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
                    'body' => 'Opent iemand jouw deellink terwijl hij ingelogd is, dan zijn jullie [vrienden](friends). Je kunt ook iemand toevoegen op e-mailadres; die persoon krijgt daar geen mail van.',
                ],
                [
                    'title' => 'Wat een vriend ziet',
                    'body' => 'Op de [vriendenpagina](friends) staat per vriend zijn verjaardag, de lijstjes die hij met jou deelde en welke van jouw lijstjes hij ziet. Wat er gereserveerd is, staat daar nooit. Een vriend verwijderen haalt de band aan beide kanten weg; lijstjes en reserveringen blijven staan.',
                ],
                [
                    'title' => 'Verjaardagen',
                    'body' => 'Bij een vriend bewaar je dag en maand, nooit een jaar. Het is jouw notitie. Je eigen verjaardag vul je in bij je account, met de keuze of vrienden hem mogen zien.',
                ],
                [
                    'title' => 'Herinneringen',
                    'body' => 'Dertig, vijftien en twee dagen vooraf krijg je een seintje bij een verjaardag, de datum van een [Geheime Vriend](lists-help/santa) en de [gelegenheid](lists-help/kinds) van een lijstje. Hier en per mail. De mail noemt de datum en de link, nooit wat er op het lijstje staat.',
                ],
                [
                    'title' => 'Meldingen',
                    'body' => 'Onder [Meldingen](notifications) zie je wat er gebeurde: iemand deelde een lijstje met je, voegde iets toe of stelde iets voor, er is een nieuw bericht in het overleg, iets is weer op voorraad, een zoekopdracht die je volgt heeft iets nieuws. Openen zet alles op gelezen.',
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
                    'body' => 'Een product op je [lijstje](lists) onthoudt de prijs van dat moment. Zakt hij, dan zie je het op de kaart, met de oude prijs doorgestreept. Meer hoeft niet.',
                ],
                [
                    'title' => 'Weer op voorraad',
                    'body' => 'Is een product nergens meer te koop, dan staat op de productpagina “Laat het weten als hij er weer is”. Je krijgt een seintje zodra een winkel het weer heeft. Stoppen kan op dezelfde plek.',
                ],
                [
                    'title' => 'Een zoekopdracht volgen',
                    'body' => '[Zoek](search) iets en kies “Hou me op de hoogte”, eventueel met een maximumprijs. Elke ochtend kijken we of er iets nieuws bij is dat erop past, en dat zie je bij [Meldingen](notifications). Stoppen doe je op dezelfde zoekpagina.',
                ],
            ],
        ],
    ],
];
