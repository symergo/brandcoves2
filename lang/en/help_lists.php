<?php

declare(strict_types=1);

/**
 * The list help pages, in English. See lang/nl/help_lists.php for the shape,
 * the [words](path) link syntax, the "1. " steps, and why this is not in
 * site.php.
 */
return [
    'index' => [
        'title' => 'How lists work',
        'seo_title' => 'How lists work',
        'seo_description' => 'Everything a list can do: saving, sharing, buying together, Secret Santa, friends and reminders. Step by step, with pictures.',
        'intro' => 'A [wish list](lists) keeps what you [find](search) here, for yourself or for someone else. Below, what you can do with one and how, by topic, with pictures. Start with the first if you have never saved anything.',
        'back' => 'All topics',
        'next' => 'Next',
        'cta_search' => 'Find something to save',
        'cta_lists' => 'Go to my lists',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Saving and making a list',
            'blurb' => 'Find something, save it, open your lists, and make a list in three steps.',
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
                    'title' => 'Making a list in three steps',
                    'body' => "1. Tap “New list” under [My lists](lists), or “Make a new list” on the home page.\n2. Choose who it is for: “For me”, “For someone else” or “Together, for someone”. Tap “Next”.\n3. Give the list a name and an occasion. Tap “Next”.\n4. Choose “Private (or share later)” or “Share with a link”, and tap “Create list”.\n\nOr skip this: while saving, tap the bookmark and choose a new list there. What you were saving goes straight onto it.\n\nOne choice is then fixed: who it is for. Everything else you can still change. What the three kinds can do is under [Wish list, gift list or group gift](lists-help/kinds).",
                    'shot' => 'wizard',
                    'alt' => 'The first step of a new list, with the three choices of who it is for.',
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
                    'shot' => 'wizard',
                    'alt' => 'The first step of a new list, with the three kinds to choose from.',
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
                    'shot' => 'occasion',
                    'alt' => 'The Occasion panel of a list, with the choice of occasion and the date.',
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
                    'body' => "1. Open your list under [My lists](lists).\n2. Tap “+ Add a product”.\n3. Type what you are looking for and press Enter, or tap the scan icon and point your camera at the barcode.\n4. Tap the product in the results. It is on your list straight away.",
                    'shot' => 'add',
                    'alt' => 'The search field at the top of a list for adding a product, with the link below it to write something in yourself.',
                ],
                [
                    'title' => 'Something that is not on this site',
                    'body' => "1. Tap “+ Add a product”.\n2. Under the search field, choose “Write it in yourself”.\n3. Fill in what it is. A link, a price and a note such as “size M, in blue” may go with it.\n4. Save it.\n\nYour own items can be changed later with “Edit”. Catalogue products cannot: their title and price come from the shop.",
                ],
                [
                    'title' => 'Copying, not moving',
                    'body' => 'Every item has “Copy to another list”. On someone else’s [shared list](lists-help/claiming) it is “Add to my list”. The original stays; the note and the price come along, whoever is buying it does not.',
                ],
                [
                    'title' => 'When the price drops',
                    'body' => 'A saved product remembers the price at that moment. When it drops, the card shows the new price with the old one struck through. There is nothing to set up. More under [Keeping an eye on prices and stock](lists-help/alerts).',
                    'shot' => 'drop',
                    'alt' => 'An item on a list whose price dropped, with the new price and the old one struck through.',
                ],
                [
                    'title' => 'Removing',
                    'body' => 'Tap the cross on the item and confirm. Only whoever manages the list can remove an item. The newest is at the top.',
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
                    'body' => "1. Open your list and tap “Share”.\n2. Set it to “Share with a link” if it is still private.\n3. Tap “Copy link” and paste it into a message. Or tap “Copy message and link” for a ready-made message, or “Share” to pick WhatsApp, Telegram, email or another app.\n\nEveryone with the link sees the list. “Stop sharing” makes every link you sent invalid; share again and you get a new one.",
                    'shot' => 'share',
                    'alt' => 'The share panel of a list, with the link, the button to copy it and the button to stop sharing.',
                ],
                [
                    'title' => 'With friends by name',
                    'body' => "1. Tap “Share”, then “Share with friends”.\n2. Pick the [friends](friends) who may see it.\n3. Tap “Send”.\n\nThey get an email with the link, without the contents, and the list appears on their [friends page](friends). “Stop sharing with …” takes it off there; a link they already had keeps working until you stop sharing. How you become friends is under [Friends, birthdays and reminders](lists-help/friends).",
                    'shot' => 'friends-share',
                    'alt' => 'The part of the share panel where you pick friends and send them the link.',
                ],
                [
                    'title' => 'Who may add',
                    'body' => 'With “Anyone can add gifts” on, whoever has the link puts something on the list straight away. With it off, suggestions come to you and you decide. Hand-written items always wait for you.',
                ],
                [
                    'title' => 'Who sees what has been bought',
                    'body' => 'On a [wish list](lists-help/kinds) you do not see what has been reserved. That is off by default and you switch it on per list with “Show me what has been claimed”. On a gift list it is on, because you are giving too. Names of who is buying what are hidden by default; switch them on and it applies to new reservations only.',
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
                    'body' => "1. Open the link to the [shared list](lists-help/sharing) you were sent.\n2. Tap “I'll get this” on the gift you are buying.\n3. Sign in if asked; your tap is carried out afterwards.\n\nThat way nobody else buys it too. The person the list is for sees nothing of it.",
                    'shot' => 'shared',
                    'alt' => 'Two gifts on a shared list, each with the button to say you will get it.',
                ],
                [
                    'title' => 'Changed your mind, or bought',
                    'body' => '“Actually, no” lets it go again, whenever you like. “I have bought it” marks it bought. At the top you see how much is already reserved.',
                ],
                [
                    'title' => 'Suggesting something yourself',
                    'body' => "1. Search at the foot of the list for what you want to suggest, or describe it yourself.\n2. Tap “Suggest something”, or “Add to the list” where that is allowed straight away.\n\nWhoever manages the list sees your suggestion and decides. Whether it is allowed straight away is under [Sharing a list](lists-help/sharing).",
                ],
                [
                    'title' => 'Keep it for yourself too',
                    'body' => 'Every item has a bookmark and “Add to my list”. What you copy lands on [your list](lists) without the reservation.',
                ],
            ],
        ],

        'quiz' => [
            'title' => 'The quiz: how well do you know them?',
            'blurb' => 'A game made from your shared list: four products, one of them really on it.',
            'seo_description' => 'Make a quiz from your wish list: who knows you best? Five rounds, a score to share.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'What the quiz is',
                    'body' => 'A game made from a shared [wish list](lists-help/kinds). Five rounds, four products each, one of them really on the list, and a score to share at the end. Whoever manages the list makes the quiz; whoever gets the link plays.',
                ],
                [
                    'title' => 'Making a quiz',
                    'body' => '1. Open your list. It has to be [shared](lists-help/sharing) and have at least five items.
2. Tap “Quiz”.
3. Tap “Make a quiz from this list”.
4. Pass the link round.',
                    'shot' => 'quiz',
                    'alt' => 'The quiz panel of a list, with the button to make a quiz from it.',
                ],
                [
                    'title' => 'Playing',
                    'body' => '1. Open the quiz link.
2. In each round, pick which of the four products is really on the list.
3. Tap “See how you did”, then “Share your score” if you like.

Everyone plays once.',
                ],
                [
                    'title' => 'What you see as the maker',
                    'body' => 'You do not play; that would be cheating. You see how many people played and the average score, not who answered what.',
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
                    'body' => "1. Make a [new list](lists-help/saving) and choose “Together, for someone” in the first step.\n2. Say who it is for and give it a name.\n3. Share the link with everyone in.\n\nEveryone with the link can add ideas and vote on them. There is nothing to reserve: it is one [group gift](lists-help/kinds) from all of you. Drawing names is something else; that is under [Secret Santa](lists-help/santa).",
                    'shot' => 'group',
                    'alt' => 'The page of a group gift, with the ideas to vote on and the box to chip in.',
                ],
                [
                    'title' => 'Voting',
                    'body' => 'Every idea has “Vote for this” and a count. The order does not change while you look; the counts do.',
                ],
                [
                    'title' => 'Chipping in',
                    'body' => 'Under “How everyone contributes” you choose: everyone picks their own amount, or everyone the same. Whoever joins taps “I am in” and sees their own share and the total. Only the organiser sees who gives what, unless they switch on “Everyone sees who is chipping in”. No money moves here; you settle that between you.',
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
                    'body' => "1. Go to [Secret Santa](santa).\n2. Tap “Start a group”.\n3. Give the group a name, a budget and the date you give the gifts.\n\nYou are the organiser.",
                    'shot' => 'santa',
                    'alt' => 'The Secret Santa page, with the button to start a group.',
                ],
                [
                    'title' => 'Inviting everyone',
                    'body' => "1. Copy the group’s invite link.\n2. Send it to everyone taking part.\n3. Whoever opens it fills in a name and an email address. No account needed.",
                ],
                [
                    'title' => 'Drawing',
                    'body' => "1. Wait until everyone is in, at least two people.\n2. Tap “Do the draw”.\n\nEveryone gets one name by email. The organiser never sees the pairs, so you stay surprised too.",
                ],
                [
                    'title' => 'If someone drops out',
                    'body' => 'Remove them from the group, or choose “Redraw this person”. Only the pairs it touches are redrawn, and only those people get a new email.',
                ],
                [
                    'title' => 'Attaching my wish list to the group',
                    'body' => "Choose your list under “Your wish list” when you start the group, or on the group page afterwards. It also works the other way round: open your [wish list](lists) and tap “Use this list” next to the group.\n\nWhoever drew you then sees what you would like, without you seeing who it is. No list yet? [Make one](lists-help/saving) in three steps.",
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
                    'body' => "When someone opens your share link while signed in, you are [friends](friends). To add someone yourself:\n\n1. Go to [Friends](friends).\n2. Under “Add a person”, fill in an email address, and the birthday if you like.\n3. Tap “Add”.\n\nThey get no email about it. If they already have an account you are connected at once; otherwise as soon as they sign in.",
                    'shot' => 'friends',
                    'alt' => 'The friends page, with the form to add someone by email address.',
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
                    'body' => "1. Open the product page of something no shop has any more.\n2. Tap “Tell me when it is back”.\n\nYou get a nudge as soon as a shop has it again. Stop in the same place with “Stop watching”.",
                ],
                [
                    'title' => 'Following a search',
                    'body' => "1. [Search](search) for what you want to follow.\n2. Above the results, tap “Tell me about new finds”.\n3. Fill in a maximum price if you like and tap “Watch this search”.\n\nEvery morning we check whether something new matches, and you see it under [Notifications](notifications). Stop on the same search page with “Stop”.",
                    'shot' => 'watch',
                    'alt' => 'The button to follow a search, with the field for a maximum price and the button to confirm below it.',
                ],
            ],
        ],
    ],
];
