<?php

declare(strict_types=1);

/**
 * The list help pages, in English. See lang/nl/help_lists.php for the shape,
 * the [words](path) link syntax, and why this is not in site.php.
 */
return [
    'index' => [
        'title' => 'How lists work',
        'seo_title' => 'How lists work',
        'seo_description' => 'Everything a list can do: saving, sharing, buying together, Secret Santa, friends and reminders. Explained by topic.',
        'intro' => 'A list keeps what you find here, for yourself or for someone else. Below, what you can do with one, by topic. Start with the first if you have never saved anything.',
        'back' => 'All topics',
        'next' => 'Next',
        'cta_search' => 'Find something to save',
        'cta_lists' => 'Go to my lists',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Saving and making a list',
            'blurb' => 'Three steps, with pictures: find something, save it, open your lists.',
            'seo_description' => 'Save anything you find here to a wish list and make one in three steps. With pictures.',
            'intro' => 'You do not have to make a list first. Saving offers to create one, and the home page has a button that makes one in three steps.',
            'numbered' => true,
            'sections' => [
                [
                    'title' => 'Find something you want to keep',
                    'body' => '[Search](search), or browse a [Cove](cove). Every product card carries a bookmark on its picture.',
                    'shot' => 'find',
                    'alt' => 'Two product cards, each with a bookmark button on its picture.',
                ],
                [
                    'title' => 'Save it and pick a list',
                    'body' => 'Tap the bookmark and it is on your list. Tap again to pick another list or start a new one. On a computer a small arrow next to the bookmark opens that panel straight away.',
                    'shot' => 'choose',
                    'alt' => 'The panel open beside a product, listing the lists to save to and the option to start a new one.',
                ],
                [
                    'title' => 'Open your lists',
                    'body' => 'Everything you saved is under [My lists](lists). Each list shows what is on it, whether it is private and who it is for. When a price drops, the card says so.',
                    'shot' => 'lists',
                    'alt' => 'The My lists page, with two lists and the button that makes one.',
                ],
                [
                    'title' => 'Making a list',
                    'body' => "Two ways, and nearly everyone uses the first.\n\n- While saving: tap the bookmark and choose a new list. What you were saving goes straight onto it.\n- With the “Make a new list” button on the home page or “New list” under [My lists](lists): three steps, who it is for, name and occasion, and whether it stays private or you share it later.\n\nOne choice is then fixed: who it is for. Everything else you can still change. What the three kinds can do is under [Wish list, gift list or group gift](lists-help/kinds).",
                ],
                [
                    'title' => 'You do not need to be signed in to start',
                    'body' => 'The three steps work without an account. At the end you sign in and the list is there. What you filled in is kept for a day, so stepping away is fine.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Wish list, gift list or group gift',
            'blurb' => 'The one choice that is fixed, and what each kind of list can do.',
            'seo_description' => 'Three kinds of list: a wish list for yourself, a gift list for someone else, or a group gift. What each can do and what is fixed.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'One choice is fixed',
                    'body' => 'The first step of a [new list](lists-help/saving) asks who it is for. That decides what the list can do, and it is the only thing you cannot change later. Name, occasion and who sees it can always be changed.',
                ],
                [
                    'title' => 'For myself: a wish list',
                    'body' => 'What you would like. [Share it](lists-help/sharing) and others can tick what they are buying, and you see neither what nor who. That keeps the surprise. If you would rather know, switch it on for that list.',
                ],
                [
                    'title' => 'For someone else: a gift list',
                    'body' => 'Ideas for someone who never opens the list themselves. Whoever you share it with ticks what they are [buying](lists-help/claiming), so nobody buys twice. You do see that, because you are giving too.',
                ],
                [
                    'title' => 'Together, for someone: a group gift',
                    'body' => 'One gift, several givers. Everyone with the link can add ideas, vote on them and say what they will chip in. No money moves here; you settle that between you. More under [Buying a gift together](lists-help/group).',
                ],
                [
                    'title' => 'An occasion and a date',
                    'body' => 'Any list can carry an occasion: a birthday, Christmas, a wedding, a birth, and ten more. For a birthday, Christmas and Valentine the date fills itself in. With a date, you get a [reminder](lists-help/friends) in time.',
                ],
            ],
        ],

        'items' => [
            'title' => 'What goes on a list',
            'blurb' => 'Products from here, your own items with a link, copying, editing, and the price that drops.',
            'seo_description' => 'Save products, add your own items with a link and a price, copy to another list, and see when a price drops.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'The bookmark',
                    'body' => 'On every product card, in [search](search) and in every [Cove](cove). One tap saves to the list you last saved to, otherwise to your default list. Another tap opens the panel: there you pick another list, move it, or take it off. On a computer a small arrow next to the bookmark opens the panel straight away.',
                ],
                [
                    'title' => 'Adding from the list itself',
                    'body' => 'A list page has “Add a product”. Search there, or scan a barcode, and what you pick goes straight onto that list.',
                ],
                [
                    'title' => 'Something that is not on this site',
                    'body' => 'Under “Add a product”, choose “Put it on yourself”. A name is enough; a link, a price and a note such as “size M, in blue” may go with it. Your own items can be edited later. Catalogue products cannot: their title and price come from the shop.',
                ],
                [
                    'title' => 'Copying, not moving',
                    'body' => 'Every item has “Copy to another list”. On someone else’s [shared list](lists-help/claiming) it is “Put on my list”. The original stays; the note and the price come along, whoever is buying it does not.',
                ],
                [
                    'title' => 'When the price drops',
                    'body' => 'A saved product remembers the price at that moment. When it drops, the card shows the new price with the old one struck through. There is nothing to set up. More under [Keeping an eye on prices and stock](lists-help/alerts).',
                ],
                [
                    'title' => 'Removing',
                    'body' => 'Only whoever manages the list can remove an item, and it asks first. The newest is at the top.',
                ],
            ],
        ],

        'sharing' => [
            'title' => 'Sharing a list',
            'blurb' => 'With a link or with friends by name, who gets to see what, and how to stop again.',
            'seo_description' => 'Share a wish list with a link or with friends, decide who may add and who sees what has been bought, and stop sharing again.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Private until you share it',
                    'body' => 'A new list is seen by you alone. Nothing shares it quietly: not an occasion, not a friend, not a quiz. You share it yourself, with “Share” on the list.',
                ],
                [
                    'title' => 'With a link',
                    'body' => 'Copy the link, or a short message with the link in it, or send it through WhatsApp, Telegram, email and more. Everyone with the link sees the list. “Stop sharing” makes every link you sent invalid; share again and you get a new one.',
                ],
                [
                    'title' => 'With friends by name',
                    'body' => 'Pick [friends](friends) and send. They get an email with the link, without the contents, and the list appears on their [friends page](friends). “Stop sharing with …” takes it off there; a link they already had keeps working until you stop sharing. How you become friends is under [Friends, birthdays and reminders](lists-help/friends).',
                ],
                [
                    'title' => 'Who may add',
                    'body' => 'With “Anyone can add gifts” on, whoever has the link puts something on the list straight away. With it off, suggestions come to you and you decide. Hand-written items always wait for you.',
                ],
                [
                    'title' => 'Who sees what has been bought',
                    'body' => 'On a [wish list](lists-help/kinds) you do not see what has been reserved. That is off by default and you switch it on per list with “Show me what has been reserved”. On a gift list it is on, because you are giving too. Names of who is buying what are hidden by default; switch them on and it applies to new reservations only.',
                ],
                [
                    'title' => 'Delivery address',
                    'body' => 'On your own wish list you can keep a delivery address. It is stored encrypted and shown only to someone who has [reserved](lists-help/claiming) something. If they let the reservation go, it disappears again.',
                ],
            ],
        ],

        'claiming' => [
            'title' => 'Buying from a shared list',
            'blurb' => 'Reserving, letting go, marking bought, suggesting something, and the quiz.',
            'seo_description' => 'What you can do on a wish list someone shared with you: reserve what you buy, suggest something, and play the quiz.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Reserving',
                    'body' => '“I’ll get this” reserves it for you, so nobody else buys it too. You need an account for that; sign in and your tap is carried out anyway. The person the list is for sees nothing of it.',
                ],
                [
                    'title' => 'Changed your mind, or bought',
                    'body' => '“Not after all” lets it go again, whenever you like. “I have bought it” marks it bought. At the top you see how much is already reserved.',
                ],
                [
                    'title' => 'Suggesting something yourself',
                    'body' => 'Search at the foot of the list and choose “Suggest it”, or “Add to the list” where that is allowed straight away. You can also describe something yourself. Whoever manages the list sees your suggestion and decides. Whether it is allowed straight away is under [Sharing a list](lists-help/sharing).',
                ],
                [
                    'title' => 'Keep it for yourself too',
                    'body' => 'Every item has a bookmark and “Put on my list”. What you copy lands on [your list](lists) without the reservation.',
                ],
                [
                    'title' => 'The quiz: how well do you know them?',
                    'body' => 'On a shared list with at least five items, whoever manages it can make a quiz. Five rounds, four products each, one of them really on the list, and a score to share. The list’s manager does not play and sees only how many people played and the average score.',
                ],
            ],
        ],

        'group' => [
            'title' => 'Buying a gift together',
            'blurb' => 'Group gift, voting, chipping in, and talking it over with everyone in.',
            'seo_description' => 'Buy one gift with several people: gather ideas, vote, agree who chips in what, and talk it over.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'A group gift',
                    'body' => 'When [making](lists-help/saving) the list, choose “Together, for someone”. Everyone with the link can add ideas and vote on them. There is nothing to reserve: it is one gift from all of you. Drawing names is something else; that is under [Secret Santa](lists-help/santa).',
                ],
                [
                    'title' => 'Voting',
                    'body' => 'Every idea has “Vote for this” and a count. The order does not change while you look; the counts do.',
                ],
                [
                    'title' => 'Chipping in',
                    'body' => 'Under “How everyone contributes” you choose: everyone picks their own amount, or everyone the same. Whoever joins taps “Count me in” and sees their own share and the total. Only the organiser sees who gives what, unless they switch on “Everyone sees who contributes”. No money moves here; you settle that between you.',
                ],
                [
                    'title' => 'Talking it over',
                    'body' => 'Beside the list is “Discussion”, a thread for everyone with the link. The person the list is for does not read along. You post with your name; you can remove your own posts, the manager any. A private list has no discussion.',
                ],
            ],
        ],

        'santa' => [
            'title' => 'Secret Santa',
            'blurb' => 'Drawing names without slips of paper: a group, a budget, a date, and everyone gets one name.',
            'seo_description' => 'Draw names for Secret Santa: start a group, invite everyone with a link, draw, and attach a wish list.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Starting a group',
                    'body' => 'Go to [Secret Santa](santa) and choose “Start a group”. Give it a name, a budget and the date you give the gifts. You are the organiser.',
                ],
                [
                    'title' => 'Inviting everyone',
                    'body' => 'Pass the invite link round. Joining takes a name and an email address; no account needed.',
                ],
                [
                    'title' => 'Drawing',
                    'body' => 'Once there are at least two people, choose “Draw”. Everyone gets one name by email. The organiser never sees the pairs, so you stay surprised too.',
                ],
                [
                    'title' => 'If someone drops out',
                    'body' => 'Remove them from the group, or redraw for one person. Only the pairs it touches are redrawn, and only those people get a new email.',
                ],
                [
                    'title' => 'A list to go with it',
                    'body' => 'Anyone with a [wish list](lists) attaches it to the group with “Use this list” on the list itself. That way whoever drew you knows what you would like. How to share it is under [Sharing a list](lists-help/sharing).',
                ],
                [
                    'title' => 'A reminder ahead',
                    'body' => 'Thirty, fifteen and two days before the date you get a nudge, here and by email. More under [Friends, birthdays and reminders](lists-help/friends).',
                ],
            ],
        ],

        'friends' => [
            'title' => 'Friends, birthdays and reminders',
            'blurb' => 'Who your friends are, what they see, and when you get a nudge.',
            'seo_description' => 'Add friends, keep birthdays, and get a reminder in time for a birthday or an occasion.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Becoming friends',
                    'body' => 'When someone opens your share link while signed in, you are [friends](friends). You can also add someone by email address; they get no email about it.',
                ],
                [
                    'title' => 'What a friend sees',
                    'body' => 'The [friends page](friends) shows, per friend, their birthday, the lists they shared with you and which of your lists they see. What has been reserved never appears there. Removing a friend removes the connection on both sides; lists and reservations stay.',
                ],
                [
                    'title' => 'Birthdays',
                    'body' => 'For a friend you keep the day and the month, never a year. It is your note. Your own birthday goes in your account, with the choice whether friends may see it.',
                ],
                [
                    'title' => 'Reminders',
                    'body' => 'Thirty, fifteen and two days ahead you get a nudge for a birthday, a [Secret Santa](lists-help/santa) date and a list’s [occasion](lists-help/kinds). Here and by email. The email names the date and the link, never what is on the list.',
                ],
                [
                    'title' => 'Notifications',
                    'body' => 'Under [Notifications](notifications) you see what happened: someone shared a list with you, added or suggested something, there is a new post in a discussion, something is back in stock, a search you follow has something new. Opening the page marks it all read.',
                ],
            ],
        ],

        'alerts' => [
            'title' => 'Keeping an eye on prices and stock',
            'blurb' => 'The price that drops, a product that comes back, and a search you follow.',
            'seo_description' => 'See when a price drops, get a nudge when a product is back in stock, and follow a search.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Save it, and the price follows by itself',
                    'body' => 'A product on your [list](lists) remembers the price at that moment. When it drops, you see it on the card, with the old price struck through. Nothing more is needed.',
                ],
                [
                    'title' => 'Back in stock',
                    'body' => 'When no shop has a product any more, its page says “Tell me when it is back”. You get a nudge as soon as a shop has it again. Stop in the same place.',
                ],
                [
                    'title' => 'Following a search',
                    'body' => '[Search](search) for something and choose “Keep me posted”, with a maximum price if you like. Every morning we check whether something new matches, and you see it under [Notifications](notifications). Stop on the same search page.',
                ],
            ],
        ],
    ],
];
