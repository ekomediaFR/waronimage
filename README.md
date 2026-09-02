# War on Image Manager

SEO image command center for WordPress fleets. Connect a WordPress site, pull **all** its pages and
**all** its media, let the matcher pair every page with its best-fit image, review, then push featured
images + SEO metadata (ALT, title, caption, description) back to WordPress **in one command**.
The AI generation and stock-photo phases are built in for later; the static WP workflow needs no AI keys.

## Core workflow (Sync & Match → Review → WP Export)

1. **Add a site** — WordPress URL + credentials (Application Password, JWT, or the EkoSEO Bridge
   JUPITER key), with a live *Test connection* check.
2. **Sync Pages** — pulls every page/post/CPT via the WP REST API v2 (`/types` discovery, full
   pagination via `X-WP-TotalPages`, publish+draft when authenticated).
3. **Sync Media** — pulls the whole image library with dimensions, ALT, captions; images under
   400×225 are flagged unusable.
4. **Run Matching** — Jaccard similarity on normalized tokens (title/slug/excerpt vs.
   title/ALT/caption/filename) + slug/city/niche bonuses. Scores ≥ 0.65 auto-approve; manual
   assignments are never overwritten. Rule-based SEO metadata is generated per assignment.
5. **Review** — split-panel per assignment: score breakdown, editable metadata with char counters
   (ALT 125 · title 60 · caption 160 · description 300), approve / skip / swap image / approve-all
   above a threshold slider.
6. **Export All Approved** — one command sets `featured_media` on every page and writes the SEO
   metadata onto each media item. Batched (25/run) with a live progress bar, per-page errors and
   retry. Only approved assignments export; re-export is idempotent.

Everything is non-destructive: re-syncs upsert by WordPress ID and keep approvals; one failure
never stops a bulk export.

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
cp .env.example .env.local   # fill in DATABASE_URL (+ DIRECT_URL for Supabase pooling)
npx prisma db push           # create tables
npm run dev                  # app on http://localhost:3000
npm run worker               # optional: Bull generation worker (needs REDIS_URL)
```

On Vercel, set `DATABASE_URL` and `DIRECT_URL` in Project → Settings → Environment Variables.
WordPress credentials live per site in the database — never in env vars. The static WP workflow
(sync/match/export) needs no OpenAI key.

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
