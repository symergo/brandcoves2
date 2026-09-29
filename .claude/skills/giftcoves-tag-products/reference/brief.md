# Tagging brief: GiftCoves products

The judging rules. Also used, word for word, by the whole-catalogue pass of 2026-09-28, so a
product tagged from the admin queue is judged the same way as the 343,000 before it. The line
format below (`x`, `-`, `i:… r:… o:… p:…`) is shorthand for a verdict; SKILL.md says how
each one becomes an entry for `POST /products/tags`.

You are judging products from a gift-finding website's catalogue. For each product decide (1) is it a
present someone could give, and if so (2) which interests, recipients and occasions it suits, and which way it leans in taste (preference).
Markets: be-nl and nl-nl titles are Dutch, be-fr French, en English. Brand and category may be empty.

## Input line (tab-separated)

    id  title  brand  category  price-in-euros

## Output line (tab-separated, exactly one per input line, same order, same id)

    id<TAB>x                                   not a gift
    id<TAB>-                                   a gift, but no tag fits
    id<TAB>i:coffee,home r:partner o:christmas p:design,luxurious   a gift, with tags

Groups are space-separated and each is optional: `i:` interests, `r:` recipients, `o:` occasions,
`p:` preference,
values comma-separated with no spaces. Use ONLY the values below, spelled exactly. Nothing else on
the line, no header, no commentary.

## Values

- **i:** cooking, coffee, photography, music, gaming, reading, fitness, outdoors, travel, gardening,
  diy, beauty, fashion, tech, home, craft, film, pets, wellness, kids, art, cycling, boardgames,
  drinks, baking, running, yoga, cars, science, water, wintersports, football, collecting, nature,
  fishing, horses, hunting, gadgets, it
- **r:** partner, mother, father, colleague, grandmother, grandfather, son, daughter, brother,
  sister, male_friend, female_friend, female_teacher, male_teacher, male_host, female_host
- **o:** birthday, christmas, wedding, anniversary, baby, housewarming, graduation, retirement,
  farewell, valentines, mothers_day, fathers_day, thank_you, sinterklaas, easter, new_year,
  halloween, communion, christening, engagement, get_well, new_job, secret_santa
- **p:** practical, design, modern, vintage, minimal, colourful, natural, technical, manual, powered,
  everyday, luxurious, classic, quirky

## x: not a gift

Mark `x` when nobody would be pleased to unwrap it, whatever the price:
- spare and replacement parts, filters, refills, cartridges, batteries, bulbs and LED spots
- accessories that only fit one specific device or model (a case for one phone model, a charger,
  cables, adapters, mounts, screen protectors, replacement straps for one watch)
- consumables and household staples (detergent, bin bags, laminating pouches, office paper, screws)
- building, installation and repair materials (tiles, pipes, fittings, paint by the litre, sealant)
- medical, incontinence and hygiene supplies; tyres and car parts
- professional and business infrastructure (network switches, server and rack gear, POS
  equipment, industrial tools sold to trades)
- large built-in or white-goods appliances (washing machine, dishwasher, boiler, built-in oven,
  fridge-freezer), and purely functional storage (laundry basket, shoe rack)
- software licences, subscriptions, warranties, services; anything sold in bulk quantities

When in doubt between gift and `x`, choose gift: the site's rules already removed the obvious
non-gifts, and a wrong `x` hides a real present. Phones, TVs, laptops, headphones, books, toys,
tools, kitchen gadgets, decor, clothing, cosmetics, perfume, games and sports gear are gifts.

## i: interests (1 to 3, almost every gift gets at least one)

What the product is *for*, not everything it could touch. Most specific first.
- `kids` for anything made for children (toys, children's books, baby items) plus the topic if any.
- `tech` for general electronics (TVs, speakers, phones); `it` for computers, laptops, peripherals,
  networking; `gadgets` for clever small devices and novelties; `gaming` for consoles and games.
- `home` for decor, textiles, furniture, lighting; `cooking` for kitchen tools and appliances;
  `baking` for bakeware; `drinks` for wine, beer, spirits, bar tools, glassware for drinks.
- `beauty` cosmetics, skincare, hair tools, perfume; `fashion` clothing, bags, jewellery, watches;
  `wellness` relaxation, massage, sleep; `fitness` gym and training gear.
- `reading` for books (plus the book's subject if clear, e.g. a cookbook is reading,cooking).
- `diy` tools and workshop; `gardening` garden tools, plants, outdoor furniture; `outdoors` camping,
  hiking; `travel` luggage and travel accessories; `craft` for making things (sewing, knitting).
- Use `-` only when truly nothing fits.

## r: recipients (only when the product plausibly suits that person)

Leave `r:` off for products that suit anyone equally: an untagged product scores neutral, a product
tagged for the wrong person scores lower for everyone else. Good uses:
- `son`/`daughter` for products made for children. `partner` for romantic, intimate or
  luxury-personal items (jewellery, perfume, lingerie, couples' things). `grandmother`/`grandfather`
  for products made for older people or family keepsakes. `male_host`/`female_host` for gifts you
  bring when invited (wine, chocolates, flowers, candles, serving pieces). `colleague` and
  `female_teacher`/`male_teacher` for small, neutral, affordable gifts (under about 30 euros: a mug,
  a nice notebook, chocolates). `male_friend`/`female_friend` and `brother`/`sister` for fun, social,
  everyday gifts.
- **The relations come in pairs, and there is no "either" value** (split by gender on 2026-09-29).
  When a product suits both of a pair, which is nearly always, tag both: `r:grandmother,grandfather`,
  `r:son,daughter`, `r:male_host,female_host`. Tag one of the pair only when the product is clearly
  for that one: it names them ("beste oma" mug → `grandmother`, "thank you juf" card →
  `female_teacher`, "best brother" keyring → `brother`), or it is genuinely made for one gender and
  the relation fits as well. A men's razor is not a `brother` gift by default; it simply gets no
  `r:`. `grandparent`, `child`, `sibling`, `friend`, `teacher` and `host` are refused.
- `mother`/`father` only where the product is about being a parent (a baby-photo frame, a "best
  dad" mug), not because it is for a woman or a man: a dress is not for "mother".
- At most three.

## o: occasions (only when the product is tied to the occasion)

- `christmas` for Christmas decor, advent calendars, Christmas jumpers, festive gift sets.
- `sinterklaas` (be-nl, nl-nl, be-fr) for toys, chocolate letters, children's gifts under about 50
  euros; `easter` for Easter items; `halloween` for costumes and Halloween decor.
- `baby`, `christening` for baby gifts; `communion` for communion gifts; `wedding`, `engagement`,
  `anniversary`, `valentines` for romantic and couple items; `housewarming` for home decor, plants,
  kitchen and serving items; `get_well` for comfort and pampering; `thank_you` for small treats;
  `graduation`, `new_job`, `retirement`, `farewell` for items that fit a milestone (a quality pen,
  a laptop bag, a hobby starter set); `secret_santa` for fun gifts under about 25 euros.
- `birthday` fits nearly everything, so do NOT tag it except for birthday-specific items (party,
  "happy birthday" products).
- At most three.

<!-- No v: (practical / playful / beautiful, "Handig / Leuk / Mooi") since 2026-09-29: removed
site-wide, and the tag API refuses `vibe:` tags. The pairs below are the vibe now: to the owner "the
vibe" means these pairs (docs/features/taste-pairs.md). -->

## p: preference (0 to 3 poles; only the ones the product clearly sits at)

Seven pairs of opposites. A product never takes both ends of one pair. Leave a pair out when the
product sits in the middle or the title does not tell you.

| Pair | One end | Other end |
|---|---|---|
| purpose | practical (function first) | design (form first, a design object) |
| era | modern (contemporary, smart) | vintage (retro, classic-styled, nostalgic) |
| tone | minimal (plain, neutral, sober) | colourful (bright, bold, patterned) |
| material | natural (wood, wool, leather, linen, stone, plants) | technical (electronic, synthetic, engineered) |
| power | manual (works by hand) | powered (plug, battery, motor) |
| spend | everyday (ordinary use, modest) | luxurious (premium, indulgent, a treat) |
| character | classic (timeless, safe) | quirky (odd, funny, unusual) |

Most useful: `powered`/`manual` whenever the product has a hand and an electric version (grinders,
toothbrushes, whisks, screwdrivers); `luxurious` for premium brands and indulgent items; `natural`
for wooden, woollen, leather goods; `quirky` for novelty; `vintage` for retro-styled items (a record
player, a retro radio). `design` only for things bought for how they look (Alessi, Hay, a designer
lamp), not every nice-looking product.

## Examples

    1	SoundLink Flex Bluetooth speaker (2nd Gen)	Bose	Speakers	164
    1	i:music,tech,outdoors r:male_friend,female_friend p:powered,modern

    5	Fellowes Admire A3 Lamineerhoezen Stylish Matt	Fellowes		18
    5	x

    7	LEGO Harry Potter Kasteel Zweinstein	LEGO	Bouwsets	169
    7	i:kids,collecting,film r:son,daughter o:sinterklaas,christmas

    9	Étui My Case pour iPhone 17 MagSafe Transparent		Étui pour téléphone portable	12
    9	x

    11	Delonghi Magnifica S espressomachine	De'Longhi	Koffiemachines	349
    11	i:coffee,cooking o:housewarming p:powered

    13	Yankee Candle Christmas Cookie Large Jar	Yankee Candle	Kaarsen	29
    13	i:home r:male_host,female_host,colleague o:christmas,thank_you p:everyday

    15	Mok "Liefste oma van de wereld"	Mug Design	Mokken	14
    15	i:home r:grandmother o:mothers_day p:everyday,quirky
