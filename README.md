# War on Image Manager

SEO image command center for WordPress fleets. War on Image reads your client sites' sitemaps, understands
what every page is for, then arms each page with the right image — **AI-generated** (DALL-E 3, brand-aware
prompts) or **real stock company photos** (GPT-4o Vision analysis + semantic matching) — complete with SEO
filename, ALT text, title, caption and description, exported straight into the WordPress media library.

## Features

- **Sitemap ingestion** — index-aware recursive sitemap parsing; GPT-4o extracts each page's intent, niche,
  CPT, city and a content summary (heuristic fallback without an API key); pages embedded with
  `text-embedding-3-small`.
- **AI image engine** — style library with prompt templates (`{niche}`, `{city}`, `{primary_color}` …),
  brand colors/fonts injected per site, 3 variants per page, WebP + responsive sizes (1280/800/400w),
  match score via embedding cosine similarity.
- **Stock photo manager** — bulk drag-and-drop upload, GPT-4o Vision analysis (subjects, setting, quality
  1-10, tags, suggested ALT), auto-assignment of the best-scoring image per page/position, page-specific
  ALT text on assignment.
- **Analytics dashboard** — coverage by niche/CPT, weakest pages, quality histogram, no-image alerts.
- **WordPress export** — media upload + alt/title/caption/description + featured image via the WP REST API.
- **Bull + Redis queue** for generation jobs, with an inline fallback so everything works without Redis.

## Stack

Next.js 14 (App Router) · TypeScript · Tailwind CSS · PostgreSQL (Supabase) + Prisma · Supabase Storage ·
OpenAI (GPT-4o, DALL-E 3, text-embedding-3-small) · Bull + Redis · Zustand

## Getting started

```bash
npm install
cp .env.example .env.local   # fill in DATABASE_URL + OPENAI_API_KEY (+ Supabase, Redis)
npx prisma db push           # create tables
npm run dev                  # app on http://localhost:3000
npm run worker               # optional: Bull generation worker (needs REDIS_URL)
```

The app degrades gracefully: without `OPENAI_API_KEY` page analysis falls back to URL heuristics; without
`REDIS_URL` generation runs inline; without Supabase, files are stored under `public/uploads` (local only).

## SEO metadata standards

| Field       | Max | Rule                                              |
| ----------- | --- | ------------------------------------------------- |
| Filename    | —   | kebab/underscore, ASCII, no accents, `.webp`      |
| ALT text    | 125 | descriptive, includes niche + location + action   |
| Title       | 60  | keyword-rich, human-readable                      |
| Caption     | 160 | one context sentence                              |
| Description | 300 | two sentences for the media library               |

Filename convention: `{niche}_{city}_{cpt}_{slug}_{variantIndex}.webp`

## Match scores

≥ 0.85 perfect (green) · 0.72–0.84 strong (blue) · 0.55–0.71 possible (yellow) · < 0.55 weak (red)
