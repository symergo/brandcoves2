---
name: The Discover page (/discover-cove)
area: Core / Discovery
status: Active, rebuilt 2026-09-26
date_added: 2026-09-26
---

# The Discover page

`/{market}/discover-cove`, "Ontdek" in the header. The page for somebody without a goal: things to
look at, and ways to find out what somebody likes. Controller `DiscoverCoveController`, page
`Pages/DiscoverCove.tsx`, test `DiscoverCoveHubTest`.

## The order, and why (owner's review, 2026-09-26)

The page had grown to eight bands, nine phone screens long. It opened with a search card and the
Gift Finder, both already in the header and both for somebody who already knows what they want.
Five explainer tiles then repeated the sections below them word for word. The rebuild:

1. **Title and one line.** "Ontdek", not "Ontdek - manieren om iets te vinden".
2. **Jump links**, one per band actually on the page, replacing the tiles.
3. **Today's Cove** with four of its products: the thing that changes every day.
4. **This or that** ([taste-discovery.md](taste-discovery.md)), shown with two real products side
   by side so it reads as a choice before a word is read. A secondary link goes to the Gift
   Finder for somebody who would rather answer questions. The two products are drawn with the
   surprises (six at once) and never repeat one; with fewer than two pictures, or a picture that
   fails to load, the band stands as words and a button.
5. **Surprise** (4), **gift ideas per person** (6 personas, plus the gift landing pages per person
   as a row of words, [gift-landing-pages.md](gift-landing-pages.md)), **Shop Smarter** (6, was 12).
6. **Ask others** and **earlier editions**, each only with three or more: one lonely question or
   one earlier edition under its own heading read as an empty shelf.

Result on local data: 2,245 px on a desktop (was 3,603), about 4,200 px on a phone (was 7,800).

Still no counts or totals, as [homepage.md](homepage.md) decided for the front page.
