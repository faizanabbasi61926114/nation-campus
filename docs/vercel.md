# Deploying on Vercel — SPA here, API on your own host

This is the recommended shape: Vercel builds and serves the React app from its CDN, and forwards every
`/api/...` call to a server you control that runs PHP and MySQL. The browser only ever sees one origin,
so no CORS setup, no `VITE_API_URL`, and no change to `frontend/src/lib/api.ts` — it already calls the
relative path `/api/v1`, and Vite's dev server proxies it the same way
([frontend/vite.config.ts:10](../frontend/vite.config.ts)).

```
browser ── https://school.pk ──▶ Vercel  ── /            → dist/index.html + assets (CDN)
                                     │      └─ /api/*  ──▶ https://api.school.pk (nginx + PHP-FPM + MySQL)
```

## 1. Put the frontend on Vercel

Vercel deploys from a Git repository or from its CLI. This workspace has no `.git` yet, so either push
it to GitHub first, or run `npm i -g vercel && cd frontend && vercel` on your machine — the CLI uploads
the project directory without any repository. The install is global tooling; nothing is added to this
project's dependencies.

In the dashboard (or via the CLI prompts):

| Setting | Value |
| --- | --- |
| Root Directory | `frontend` |
| Build Command | `npm run build` |
| Output Directory | `dist` |
| Node.js version | pinned by `frontend/.nvmrc` (22) |
| Environment Variables | none required |

`npm run build` runs `tsc -b` before Vite, so a type error fails the deploy rather than shipping a
half-built bundle.

Then edit [frontend/vercel.json](../frontend/vercel.json) and replace the one placeholder:

```json
{ "source": "/api/:path*", "destination": "https://api.school.pk/api/:path*" }
```

Two things worth knowing about that file:

- Vercel does **not** substitute environment variables inside `vercel.json`, which is why the API host is
  a literal. It is a single line to change, and it is committed, so a deploy is reproducible.
- Rule order matters, and existing files win over rewrites. `/assets/index-*.js` is served from the CDN;
  only paths that are not files fall through. The second rule, `/((?!api/).*)` → `/index.html`, is what
  keeps a hard refresh on `/library` or `/certificates` from turning into a 404.

Add your domain under **Settings → Domains**; Vercel issues the certificate.

## 2. Prepare the API host

Any host that runs PHP 8.4 or newer, PHP-FPM, nginx and MySQL works — the Oracle Always Free VM, a
small VPS, or
a colocation box. [deploy/nginx-cms.conf](../deploy/nginx-cms.conf) is a complete server block for it:
copy it, change `server_name` and `root`, run certbot, and match the PHP-FPM socket to `php -v`. The
security and cache commands are in [README.md §6](../README.md). Three items are specific to sitting
behind Vercel:

- **`TRUSTED_PROXIES`.** Requests now arrive from Vercel's edge, so `$request->ip()` would record the
  proxy and every Audit Log entry would be useless. The addresses Vercel forwards from are dynamic, so
  on a host that is reachable *only* through Vercel set `TRUSTED_PROXIES=*`; if the host also serves
  traffic directly, list the proxy CIDRs instead, because `*` lets a direct caller spoof its own address.
  The switch is read at request time by Laravel's own `TrustProxies` middleware through
  [backend/config/trustedproxy.php](../backend/config/trustedproxy.php), which maps it to the
  `TRUSTED_PROXIES` value in `.env`.
- **Cron stays on this host.** `announcements:publish-due` is scheduled every fifteen minutes; Vercel's
  Hobby plan rejects any cron finer than once a day, so do not move it there.
  `*/15 * * * * cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1`
- **MySQL must not be exposed.** Bind it to localhost, and keep the daily `mysqldump` copied off the
  machine — Vercel holds no copy of your data.

Before the first real login: run `migrate --force` without the demo seeders, replace every seeded
password, and set `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY`,
`APP_URL=https://api.school.pk`, `APP_TIMEZONE=Asia/Karachi`, then
`php artisan config:cache route:cache view:cache`.

## 3. Verify it, in this order

1. Open the domain → the sign-in screen renders and the network tab shows no mixed-content warnings.
2. Sign in → `/api/v1/auth/me` returns 200 **from your own origin**. That single check proves the rewrite
   path works, because the SPA never learns the API's real host.
3. Hard-refresh a deep link such as `/hostel` → still renders (SPA fallback rule).
4. Switch campus in the header → figures change, i.e. the `X-Campus-Id` header survived the proxy hop.
5. Open **Audit Log** → your real public IP is recorded, not a Vercel address (`TRUSTED_PROXIES` worked).
6. Sign out, revisit → the 401 interceptor sends you to `/login` rather than showing an empty shell.

## 4. Limits to accept knowingly

- **Hobby is non-commercial.** Vercel's free plan is for personal, non-commercial projects; a school
  running its live records on it needs a paid plan. Free is a fine trial, not the client's contract.
- **One extra hop per API call.** Expect tens of milliseconds added, more if your API host is far from
  the user. The CDN makes the page itself faster; the JSON does not.
- **No retries.** If the API host is down, the rewrite fails and the page shows its red "couldn't load"
  state with a Retry button — by design, but it will read as "the site is broken".
- **Request body cap (~4.5 MB) on the edge.** Harmless today because no endpoint accepts uploads. If you
  add student photos or document uploads, post them straight to the API host's own domain (or to object
  storage) instead of through the rewrite.
- **Cold start on the API side is yours to manage**, not Vercel's: keep PHP-FPM warm, and cache config and
  routes so each request does not re-read every `.env` value.

## 5. What this does *not* do

It does not put Laravel on Vercel. That is possible with a community PHP runtime or a FrankenPHP
container function, but it costs you MySQL (Vercel's database is Postgres), the `mysqldump` backup
procedure, sub-daily cron, and any local file writes — and it puts unmanaged third-party glue between a
school's data and the internet. Keep the API on a server you can log into.
