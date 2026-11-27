# Tagging brief: GiftCoves products

The judging rules. Also used, word for word, by the whole-catalogue pass of 2026-09-28, so a
product tagged from the admin queue is judged the same way as the 343,000 before it. The line
format below (`x`, `-`, `i:… r:… o:… g:… p:…`) is shorthand for a verdict; SKILL.md says how
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

Groups are space-separated and each is optional: `i:` interests, `r:` recipients, `o:` occasions, `g:` gender,
`p:` preference, `a:` age,
values comma-separated with no spaces. Use ONLY the values below, spelled exactly. Nothing else on
the line, no header, no commentary.

## Values

- **i:** cooking, coffee, photography, music, gaming, reading, fitness, outdoors, travel, gardening,
  diy, beauty, fashion, tech, home, craft, film, pets, wellness, kids, art, cycling, boardgames,
  drinks, baking, running, yoga, cars, science, water, wintersports, football, collecting, nature,
  fishing, horses, hunting, gadgets, it, sweets, stationery, pubgames, adult, perfume, golf, racket,
  puzzles, needlework, delicacies, motorcycles
- **r:** partner, mother, father, grandparent, child, friend, colleague, sibling, teacher, host
- **o:** birthday, christmas, wedding, anniversary, baby, housewarming, graduation, retirement,
  farewell, valentines, mothers_day, fathers_day, thank_you, sinterklaas, easter, new_year,
  halloween, communion, christening, engagement, get_well, new_job, secret_santa
- **p:** practical, design, modern, vintage, minimal, colourful, natural, technical, manual, powered,
  everyday, luxurious, classic, quirky
- **a:** 0-2, 3-5, 6-9, 10-12, 13-17, 18-29, 30-49, 50-64, 65+, 18+ (posted as `age:6-9`, `age:18+`)

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
  `baking` for bakeware; `drinks` for wine, beer, spirits, bar tools, glassware for drinks. Tea, teapots
  and tea sets are `coffee` (shown as "Koffie en thee" since 2026-11-26), never `drinks`, which the
  wizard shows as wine.
- `golf` for clubs, balls, bags, trolleys, rangefinders; `racket` for padel, tennis, badminton and
  squash rackets, balls, shuttles, racket bags and grip tape (both added 2026-11-26).
- `puzzles` for jigsaws, 3D puzzles, brain teasers and puzzle mats, never `boardgames` (split off
  2026-11-26: a puzzle is done alone, a board game with others). `needlework` for yarn, knitting and
  crochet sets and needles, embroidery and sewing machines, together with `craft` (added the same day).
- `delicacies` for the food itself: sauces, oil and vinegar, spices, tapas, delicatessen boxes
  (`cooking` stays the gear); `motorcycles` for motorcycle helmets, clothing, gloves, intercoms
  (`cars` stays cars). Both added 2026-11-26.
- `sweets` for chocolate, pralines and sweets; `stationery` for pens, notebooks, writing sets;
  `pubgames` for darts, poker and card games, table football, billiards; `adult` for erotic gifts
  (added 2026-09-30); `perfume` for perfume, eau de toilette and fragrance sets, together with
  `beauty` (added the same day). Bulk stock of sweets ("Voorraad", "Horeca", a 24-pack) is still `x`.
- `beauty` cosmetics, skincare, hair tools, perfume; `fashion` clothing, bags, jewellery, watches;
  `wellness` relaxation, massage, sleep; `fitness` gym and training gear.
- `reading` for books (plus the book's subject if clear, e.g. a cookbook is reading,cooking).
- `diy` tools and workshop; `gardening` garden tools, plants, outdoor furniture; `outdoors` camping,
  hiking; `travel` luggage and travel accessories; `craft` for making things (sewing, knitting).
- Use `-` only when truly nothing fits.

## g: gender (almost never)

`g:male` or `g:female` only when the product is genuinely for one: a men's razor, a dress, a
bra, a beard kit, a "best grandma" mug. A giver who chooses "for her" never sees a product tagged
`g:male`, so a wrong gender tag hides a good gift. Most products suit both and get no `g:`; never
tag both. Relations have no gender (since 2026-09-29, docs/features/gift-gender.md): tag
`r:grandparent`, not a grandmother, and add `g:female` only when the product itself is hers.

## r: recipients (only when the product plausibly suits that person)

Leave `r:` off for products that suit any adult equally: an untagged product scores neutral, a product
tagged for the wrong person scores lower for everyone else. "Anyone" rarely includes a child, though: a
television suits any adult and no child, and that is what `a:18+` is for (below). Good uses:
- `child` for products made for children. `partner` for romantic, intimate or luxury-personal items
  (jewellery, perfume, lingerie, couples' things). `grandparent` for products made for older people
  or family keepsakes. `host` for gifts you bring when invited (wine, chocolates, flowers, candles,
  serving pieces). `colleague` and `teacher` for small, neutral, affordable gifts (under about 30
  euros: a mug, a nice notebook, chocolates). `friend` and `sibling` for fun, social, everyday gifts.
- Never tag by gender: a razor or a dress is not for "father" or "mother". `mother`/`father` only
  where the product is about being a parent (a baby-photo frame, a "best dad" mug).
- At most three.

## a: age (2026-11-25)

- `a:6-9`, `a:10-12` and the other bands when the product is made for that age (a toy's box says so).
  A child's landing page takes only products with a band up to 12, `i:kids`, or `r:child` without an
  older band, so tag a children's product with its band.
- `a:18+` on anything nobody gives a child or a young teenager, when its category does not already say
  so: televisions and home cinema, network, office and security equipment, power tools and machinery,
  large kitchen appliances, knives, alcohol and bar gear, erotica and lingerie, adult fragrance and
  skincare, clothing in adult sizes, motorcycle gear. Never next to a band under 18.
- The engine keeps a list of adults-only categories (TVs, knives, saws, wine glasses, motorcycle
  helmets...), so `a:18+` matters most for the product filed somewhere neutral, like a television in
  "Elektronica" or a whisky set in "Cadeaus".

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

Sharpened 2026-11-26, after a count of be-nl: powered 12,158 against manual 1,356, colourful 7,314
against minimal 768, and practical and design 1,271 each. A pole that almost nothing carries does
nothing for the person who picks it in the gift finder.

- `colourful` only when colour is the point: a rainbow lamp, a bold pattern, a toy in bright
  colours. Not because the product comes in several colours, has a coloured logo, or is not black.
- `minimal` for plain, sober, single-colour things with nothing added: a white ceramic mug, a black
  leather wallet, a plain steel watch. Most good Scandinavian and Japanese design is `minimal`.
- `practical` for anything bought to do a job well: a multitool, a good kitchen scale, a power
  bank, a thermos, a rain jacket, a tool set, an organiser. This is the larger end of the pair:
  most gifts with a use are `practical`. `design` stays narrow (bought for how it looks).
- `manual` for every product that works without power where the electric kind exists or is
  common: a hand coffee grinder, a manual toothbrush gift set, a hand whisk, a mechanical watch,
  a fountain pen, a board game, a jigsaw. When you tag `powered`, ask whether the other
  end would have fitted the products next to it.
- Neither `manual` nor `powered` for things that do no job: books, clothing, food and drink,
  cosmetics. Nobody picks "works by hand" in the gift finder hoping for a novel. (2026-11-27: the
  example "a book" put `manual` on 17 of 24 judged French books, and the category rule then put
  it on all 257,438 books in be-fr.)
- The pairs `manual`/`powered` and `natural`/`technical` are passed on to untagged products in the
  same category (AutoTagger, since 2026-11-26). Tag them every time they apply, even when it feels
  obvious: that is what lets a category earn the rule.

## Examples

    1	SoundLink Flex Bluetooth speaker (2nd Gen)	Bose	Speakers	164
    1	i:music,tech,outdoors r:friend p:powered,modern

    5	Fellowes Admire A3 Lamineerhoezen Stylish Matt	Fellowes		18
    5	x

    7	LEGO Harry Potter Kasteel Zweinstein	LEGO	Bouwsets	169
    7	i:kids,collecting,film r:child o:sinterklaas,christmas

    9	Étui My Case pour iPhone 17 MagSafe Transparent		Étui pour téléphone portable	12
    9	x

    11	Delonghi Magnifica S espressomachine	De'Longhi	Koffiemachines	349
    11	i:coffee,cooking o:housewarming p:powered

    13	Yankee Candle Christmas Cookie Large Jar	Yankee Candle	Kaarsen	29
    13	i:home r:host,colleague o:christmas,thank_you p:everyday
