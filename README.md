# Link Preview for Flarum

Turns plain `<a href>` links in posts into Discord/Slack-style preview cards.
Fetches OpenGraph / Twitter Card metadata **server-side**, caches it, and renders
the card client-side with **zero layout shift**. Links written with their own
title get an on-hover preview instead of a full card, and authors/moderators can
pin or dismiss any card per post.

> [!IMPORTANT]
> **You need a queue worker _or_ cron.** Link metadata is fetched server-side,
> off the request thread:
> - **Queue worker** (recommended) — a real `redis`/`database` queue with a
>   supervised `php flarum queue:work` fetches cards within seconds.
> - **Cron only** — on Flarum's default `sync` queue (no worker) the fetch is
>   *not* run inline (that would block the save); it's deferred to the 5-minute
>   scheduled sweep, so all you need is `php flarum schedule:run` on cron. Cards
>   appear within a few minutes — a fixed-size skeleton holds their place until
>   then, so there's no layout shift.
> - **Neither** — external-link cards won't appear. (Links to your own forum
>   still work — resolved instantly from the DB.)
>
> See [Install](#install).

- **Server-side, queue-backed fetching** — the post-save request never blocks on
  a remote fetch. A background worker pops the job, fetches with a hardened HTTP
  client, and the card appears on the next page load.
- **SSRF-hardened** — link fetching is dangerous to get wrong; this ships an
  eleven-layer defense against internal-network access, cloud-metadata leaks,
  DNS rebinding, and redirect-based bypasses (see *Security model*).
- **No layout shift** — every card slot has fixed CSS dimensions, so images
  loading in (or failing) never reflow the post.
- **Self-link short-circuit** — links to your own forum are resolved straight
  from the database (no HTTP fetch), so they work behind Cloudflare bot
  challenges and add no SSRF surface.

## Scope

This fills the gap **between** Flarum's other auto-embedders, rather than
competing with them:

- **Inline players / iframes** for ~150 popular sites (YouTube, Vimeo, Spotify,
  Reddit, TikTok, SoundCloud, Instagram, Mastodon, Bluesky, Imgur, GitHub Gist,
  …) are produced at parse time by `fof/formatting` (the s9e MediaPack). Those
  URLs are rewritten in the stored content before this extension ever sees them.
- **Inline `<img>`** for bare image URLs (`.jpg`, `.png`, `.gif`, …) is handled
  by `fof/formatting`'s `autoimage`.
- **Everything else** — articles, blog posts, docs, repos, Wikipedia pages, and
  any other URL not covered above — gets a card here: title, description, site
  name, and thumbnail.

## How it works

```
POST /api/posts (synchronous)                       Background worker
─────────────────────────                          ─────────────────────
1. Flarum saves the post.                          1. Pops the fetch job.
2. Posted/Revised event fires.                     2. SafeHttpClient.get()
3. ScanPostUrls listener:                              (≤10s budget,
   - DOMs the rendered body for <a href>             SSRF-hardened).
   - SKIPS mention / quote anchors                 3. OG + fallback parsers.
   - dedups / validates / whitelist / blacklist    4. Updates the preview row.
   - rate-limits per author (hourly)
   - SELF-LINK SHORT-CIRCUIT: a URL matching
     the local forum resolves from the DB
     synchronously and skips the queue
   - inserts a placeholder preview + pivot row
   - enqueues the fetch job
4. API response returns in normal ~50 ms.          5. Next page load renders
                                                       the card.
```

The post-save request thread never blocks on a remote fetch. If the queue is
backed up or a worker is down, posts still go in immediately; cards just appear
later as the worker drains. A scheduler sweep (5-minute interval) re-dispatches
any rows whose job was lost — and on the default `sync` queue (no worker) that
same sweep is the *primary* fetcher: the listener writes the placeholder row but
leaves the actual fetch for the sweep, so it never blocks the save. See
[Background fetching](#background-fetching-queue-worker-or-cron).

### What this does NOT do (intentional)

- **No client-driven fetch.** Browsers never trigger fetches. The only trigger
  is a post being saved/edited by an authenticated user.
- **No retries.** A failed fetch stays failed (one entry per URL) — a single bad
  URL never becomes a fetch storm.
- **No image proxy.** Thumbnails and site icons are hot-linked from the source.
  A failed image load degrades to a fixed-size placeholder slot (no layout
  shift).
- **No third-party favicon service.** Site icons come from the page's own
  declared icons, or from one server-side probe of its own `/favicon.ico`.
  Readers' browsers never contact a favicon aggregator.
- **No card for sites already handled by `fof/formatting`** (iframe players or
  inline images) — those URLs are transformed before they reach the scanner.
- **No URLs from mentions or quoted content.** `<UserMention>`, `<PostMention>`,
  and `<a>` tags inside `<blockquote>` are excluded — they're rendering output
  from other formatter tags, not user-typed URLs.

## Security model

Fetching user-supplied URLs server-side can leak internal network access (SSRF),
expose cloud metadata services, or turn the forum into a bandwidth amplifier.
This extension layers eleven defenses:

| #  | Defense                                              | What it stops                                             |
|----|------------------------------------------------------|-----------------------------------------------------------|
| 1  | Scheme allowlist (http/https only)                   | `file://`, `gopher://`, `javascript:`                     |
| 2  | Port allowlist (80/443)                              | SSH probes, port-scan-by-redirect                         |
| 3  | Reject credentials in URL                            | Forwarded-auth leak                                       |
| 4  | Resolve A + AAAA, filter every IP                    | Naïve filter bypass via AAAA                              |
| 5  | RFC1918 / loopback / link-local / ULA / multicast / test-net + v4-mapped IPv6 unwrap | All non-public address space, incl. `::ffff:127.0.0.1` |
| 6  | Reject host if ANY resolved IP is private            | DNS rebinding (mixed public/private answers)              |
| 7  | `CURLOPT_RESOLVE` pins the vetted IP for the connect | TOCTOU between resolve and connect                        |
| 8  | Manual redirect handling — every `Location` re-runs the full validation chain | Public-decoy → private-IP redirect              |
| 9  | `CURLOPT_PROTOCOLS_STR` locks the wire protocol      | `http://` redirect to `dict://`                           |
| 10 | `WRITEFUNCTION` aborts over 2 MB                      | Slowloris / bandwidth flood                               |
| 11 | Per-user hourly URL rate limit + per-post URL cap    | Logged-in attacker abusing the fetch queue                |

The full chain is exercised end-to-end by
`tests/Integration/Http/SafeHttpClientLiveTest.php`, which hits `127.0.0.1`,
`localhost`, and `169.254.169.254` against real curl and asserts all three are
blocked.

## Card visibility: raw links, titled links, hover previews

How a link is written decides its default presentation:

- **Raw links** — the pasted URL is its own text (`https://example.com/x`,
  including `<https://…>` autolinks) → **inline card** below the link.
- **Titled links** — Markdown `[Title](url)`, BBCode `[url=…]Title[/url]`,
  reference-style links — anything whose visible text isn't the URL → **no
  inline card**; hovering shows a floating preview overlay instead. (The author
  already wrote their own context; a full-width card would be noise — but readers
  can still peek.)

Either default can be overridden per `(post, preview)` by the post author or any
moderator/admin:

- On an inline card, hover → a **✕ Hide preview** button appears top-right.
  Click → the card collapses; the link becomes hover-only.
- On a hover overlay, a **Pin preview** button sits below the preview. Click →
  the card is pinned permanently into the post for everyone.

Detection is DOM-based (anchor text vs `href`), so every authoring syntax is
covered without storing anything; the two overrides live in `dismissed_at` /
`pinned_at` on the pivot table (mutually exclusive). Links back to your own
forum are the one case where the text is not the address even though it was
pasted bare: since Flarum 2.0.0-rc.6 the formatter shows those as a `#123`
label. Core marks exactly that case with `UrlLink--discussion`, which is read
as "raw", so a pasted discussion link gets its inline card like any other —
and a card pointing back at the forum opens in the same tab, without
`nofollow`, routed by the running application rather than reloading it. Hover previews are shown to
all readers — a "hidden" card de-emphasizes, it doesn't censor.

On touch devices there is no hover, and hijacking a link's first tap is the
classic iOS double-tap anti-pattern (a link must navigate on first tap).
Instead, a link with a hidden preview gets a small eye icon after it: tap it →
the preview opens (with **Pin preview** for authors/mods); tap the preview → the
URL opens; tap elsewhere → it closes.

Permissions: `$actor->can('edit', $post)` — Flarum's standard policy, which
grants `discussion.editOwnPost` to the author (only while the forum's edit-time
window is open) and `discussion.editPost` to mods/admins.

API:

```
POST /api/link-previews/posts/{postId}/previews/{previewId}/dismiss   -> 204
POST /api/link-previews/posts/{postId}/previews/{previewId}/pin       -> 204
```

## CLS (Cumulative Layout Shift) posture

Cards have fixed CSS dimensions:

- Desktop: square thumbnail, title clamped to 2 lines, description to 3.
- Mobile (≤480 px): full-width banner thumbnail, stacked.

Image loading doesn't reflow the card (the slot is reserved by CSS). An image
that 404s swaps to a same-dimension placeholder instead of being removed — the
card stays exactly as wide and tall as it was.

A card that's still being fetched renders as a **fixed-size skeleton** in the
same slot, so when the real card lands — on the next page load, the author's
first paint after saving, or a live `flarum/realtime` update — it fills the
reserved space without shifting the content below. (A fetch that ultimately
yields nothing usable removes the skeleton — a comparatively rare upward shift.)

## Install

```bash
composer require ekumanov/flarum-ext-link-preview
```

Then enable **Link Preview** in **Admin → Extensions**. Enabling runs the
extension's migration and creates its tables for you — there's no separate
`php flarum migrate` step on a fresh install.

### Background fetching: queue worker or cron

Preview metadata is fetched **in the background**. When a link is posted the
forum has to reach out to that other website, which can take a few seconds, so
the fetch happens *after* the post is saved rather than making the author wait —
the card then appears a moment later. Two pieces make that work:

- a **queue** — the to-do list of links waiting to be fetched, and
- a **worker** — something that works through that list and does the fetching.

Flarum can provide those in a couple of ways; pick whichever fits your forum:

- **A real queue + worker (recommended for busier forums)** — Redis via
  [`fof/redis`](https://github.com/FriendsOfFlarum/redis) (or the `database`
  queue), with `php flarum queue:work` running as a supervised daemon.
  *Supervisor* just keeps that worker process alive (restarts it if it stops);
  the worker itself loops continuously and fetches each link a second or two
  after it's posted.
- **Just cron (works on any forum, including the default `sync` queue)** — every
  Flarum site is meant to run `php flarum schedule:run` once a minute (its
  *scheduler*; see below). This extension hooks a task into that scheduler that,
  every 5 minutes, finds links not yet fetched and fetches them. So with **no
  worker at all** the single cron line is enough — cards just take a few minutes
  instead of seconds. (On `sync`, fetching inline would block the post-save, so
  the extension deliberately leaves the fetch for this sweep — see
  [How it works](#how-it-works).)

With neither a worker nor cron, external-link cards won't appear. Links to your
own forum still work either way — those are read straight from your database, no
fetching needed.

### Scheduler

Flarum's **scheduler** runs *recurring* tasks on a clock (this is separate from
the *queue*, which carries one-off background jobs). It's driven by a single
system-cron line you most likely already have:

```cron
* * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1
```

This extension's 5-minute sweep runs on that scheduler. On a worker-backed queue
the sweep is only a safety net (it re-dispatches any fetch job that got dropped —
worker restart, Redis flush, etc.); on the cron-only (`sync`) setup it's the
*primary* fetch path, so the cron line is required there.

> On the **database** queue driver, Flarum itself uses this same scheduler to
> run the worker (`queue:work --stop-when-empty`) every minute — so the one cron
> line doubles as your queue worker, with no Supervisor needed. Redis queues
> aren't auto-scheduled; they need their own supervised daemon.

## Update

```bash
composer update ekumanov/flarum-ext-link-preview
php flarum migrate
php flarum cache:clear
```

Unlike a fresh install, an update **does** need `php flarum migrate` — it
applies any new schema the new version introduces (a harmless no-op when there's
none). `php flarum cache:clear` then drops stale compiled assets so the new
front-end is served.

## Configuration

**Admin → Extensions → Link Preview** exposes every setting. The underlying keys
live under the `ekumanov-link-preview.` prefix in the `settings` table; you can
also set them directly via SQL:

```sql
INSERT INTO settings (`key`, value) VALUES
  ('ekumanov-link-preview.ttl_seconds',        '2592000'),   -- 30 days
  ('ekumanov-link-preview.user_rate_per_hour', '20'),
  ('ekumanov-link-preview.max_urls_per_post',  '10'),
  ('ekumanov-link-preview.whitelist',          ''),
  ('ekumanov-link-preview.blacklist',          ''),
  ('ekumanov-link-preview.user_agents',        ''),
  ('ekumanov-link-preview.show_favicons',      '1'),
  ('ekumanov-link-preview.icon_probe',         '1'),
  ('ekumanov-link-preview.favicon_max_bytes',  '32768')   -- 32 KB
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

### Site icons

Cards carry a small mark next to the site name: the linked page's own favicon,
taken from the `<link rel="icon">` tags the fetcher already reads. It is picked
server-side — closest to 32-64px wins, PNG and SVG beat multi-resolution `.ico`,
Safari's monochrome `mask-icon` is skipped, and the URL is resolved against the
page's final URL and forced to https.

The icon is hot-linked, exactly like the card thumbnail beside it, and requested
with `no-referrer`. It occupies a fixed 18x18 slot that stays put whether or not
the image loads, so a blocked or 404'd icon shifts nothing.

`icon_probe` covers pages that declare no icon at all: the server asks that
page's own origin for `/favicon.ico`, once per link, and stores the result if it
comes back as an image under `favicon_max_bytes`. A miss is remembered, so the
same site is never asked twice.

**Every icon is weighed before a reader sees it.** What a page declares about
its icons does not predict their size: measured across a live install, the
median icon is 4 KB but the tail reaches a 292 KB logo standing in for a favicon
and a 216 KB multi-resolution `.ico`, both of which every metadata-based
heuristic ranks perfectly well. So the chosen icon is fetched once, server-side,
at preview-fetch time and its size recorded in the `icons` column; anything over
`favicon_max_bytes` is never chosen again and the next-best candidate is tried
instead (up to three per page). Rows stored before this existed keep their marks
until `link-preview:backfill-icons` measures them.

A site that declares its logo as both `og:image` and favicon has given us a
brand mark rather than a picture of anything: it fills the small slot and the
thumbnail slot stays empty, rather than the card showing a magnified logo.

**Cards whose source offers no usable icon** — none declared, all of them dead,
or the only one too heavy — fall back to a lettered chip built from the
hostname. It costs no request and no bytes, so every card carries a mark instead
of some carrying a gap, and a hot-linked icon that 404s degrades to the letter
rather than to a blank square. Turning off `show_favicons` hides it too.

There is deliberately **no third-party favicon service**. Google's
`s2/favicons` and its equivalents would be a one-line alternative and would also
hand a third party a list of every domain your forum links to, looked up from
every reader's browser. `show_favicons` off removes the icon URL from the page
altogether rather than merely hiding it.

### `user_agents`

One User-Agent per line, most preferred first. The first is used for every
fetch; the rest are only tried when a site answers with a **block** status
(401, 403, 406, 429), and each retry re-runs the whole validation and redirect
chain under the new identity. Leave it empty for the built-in chain.

Why a chain rather than one good string: a great many sites answer an
unrecognised client with a block instead of content, and no single identity
gets past all of them. Measured against 54 hosts that were blocking a live
install:

| Identity | Hosts recovered |
|---|---|
| Desktop Chrome | 3 |
| `Twitterbot/1.0` | 4 |
| `facebookexternalhit/1.1` | 4 |
| Chrome **+ realistic `Accept` / `Sec-Fetch-*` headers** | 0 |

The sets overlap only partly, so the chain beats any one of them. Header
realism buys nothing at all — what remains after the User-Agent is IP
reputation and TLS fingerprinting, which no header can talk its way past.

The two scraper identities are in the default chain because sites very often
allowlist them *specifically so* link previews work, which is exactly what this
extension is doing. It is still borrowed identity, though. If you would rather
the fetcher only ever present as itself, put a single line in this setting and
the chain collapses to one request.

### `whitelist` / `blacklist`

Both default to empty — every URL gets a fetch + card unless the admin curates
exclusions. Comma-, space-, or semicolon-separated hostnames, case-insensitive.

- The `www.` prefix is normalised both ways — `amazon.com` matches
  `www.amazon.com` and vice versa.
- Subdomain wildcards: `*.amazon.com` matches `smile.amazon.com` but NOT bare
  `amazon.com` — add the apex separately.
- `whitelist` is enforced *before* `blacklist`. If `whitelist` is set, hosts
  outside it are excluded; `blacklist` filters within whatever remains.

Authors/mods can also dismiss individual cards per post via the ✕ button — the
right tool for "this one card is ugly", whereas the blacklist is for "I never
want cards from this host."

## Console commands

```
php flarum link-preview:backfill       # scan historical posts, enqueue missing fetches
php flarum link-preview:sweep          # re-dispatch dropped fetch jobs (also runs on the scheduler)
php flarum link-preview:retry-failed   # re-try fetches that failed recoverably (also on the scheduler)
php flarum link-preview:refresh-self   # re-resolve cached self-link previews from the local DB
php flarum link-preview:backfill-icons # probe /favicon.ico for older cards that have no site icon
```

`retry-failed` picks up rows whose last fetch failed for a reason that might
since have cleared — a bot-block, a timeout, a 5xx — and leaves settled answers
(404, an SSRF refusal, an oversized body, a non-HTML media URL) alone. Backoff
is per row via the `fetch_attempts` column: 1h, then 6h, 1d, 3d, 7d, 30d, then
the row is left alone. It runs hourly on the scheduler, so a site that keeps
blocking is *not* hit hourly. `--dry-run` lists what it would re-try;
`--ignore-backoff` re-tries every eligible row now.

This matters more than it sounds. Before it existed a blocked URL was a dead
row forever: the fetch recorded the block, nothing rescheduled it, and the post
kept its bare link even after the site stopped blocking. On a live install with
~4000 cached URLs, about a sixth of all recorded failures had already started
working again by the time anyone checked.

`refresh-self` rebuilds self-link previews that were cached before the local
resolver shipped (they were fetched over HTTP and may carry a cropped forum
logo) into clean, image-less title + first-post-excerpt cards. Supports
`--dry-run`.

`backfill-icons` is a one-off catch-up for links cached before icon probing
existed. It targets rows that already render a card, are not self-links, and
have no stored icon, and runs *only* the `/favicon.ico` probe on them — it never
re-fetches the page, and touches no column but `icons`, so titles, thumbnails,
`retrieved_at` and `fetch_attempts` come out unchanged. Rows that already have
a stored icon need nothing; they light up on the next render. `--dry-run`,
`--limit=N`, `--host=example.com`, and `--delay=` (milliseconds between
requests, default 500) are supported. Not scheduled: new links get their probe
inside the fetch job.

It runs two passes. The **validate** pass measures icons that are already stored
but have never been weighed, dropping any over `favicon_max_bytes`; the
**probe** pass covers rows with no icon at all. `--only=validate` or
`--only=probe` runs just one of them.

## Development

```bash
# PHP unit + integration tests
composer install
vendor/bin/phpunit
```

```bash
# JS build
cd js && npm ci && npm run build
```

The PHPUnit suite covers the SSRF chain, URL extraction, mention/quote
filtering, OpenGraph parsing, self-link parsing, and host matching. The
dismiss/pin controllers are thin permission-gate + UPDATE wrappers. The live
SSRF integration test hits real loopback / cloud-metadata / RFC1918 endpoints to
verify the guards.

## Future work

- **Per-group permission gating** for which user groups may trigger server-side
  fetches (today any authenticated author can, bounded only by the per-user rate
  limit, per-post URL cap, and the SSRF guards).
- **Optional image proxy** — serve card thumbnails and site icons from the
  forum's own domain instead of hot-linking, for reliability and to avoid
  leaking readers' IPs to third-party image hosts.
- **Search reindex hook** so fetched card titles/descriptions are searchable.

## License

[MIT](LICENSE). An independent implementation built against the public
OpenGraph spec.
