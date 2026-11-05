# How to run Nation Campus in your browser

Nothing in this project starts automatically, so every session begins the same way: database, then API,
then the web app. Two servers must be running before a browser tab will show anything.

```
browser  →  http://127.0.0.1:5173   (Vite, the React app you actually look at)
                   │  /api/* proxied automatically
                   ▼
             127.0.0.1:8000         (php artisan serve, the Laravel API)
                   │
                   ▼
             MySQL 8.4 in Docker container "cms-mysql"
```

Use **5173** in the address bar. Port 8000 is JSON only — opening it in a browser gives you an API
response, not the app.

## 1. Every session, in this order

Run these from the project root, in Git Bash (the paths below assume it; in PowerShell drop the `//` to
`/c` and use the `$env:` form shown later).

Open two terminals. Terminal 1 runs the API and stays running; terminal 2 runs Vite and stays running.

> **First time on this machine only.** If `backend/vendor` or `frontend/node_modules` is missing, or
> `backend/.env` does not exist, do this once before anything else:
>
> ```bash
> cd backend  && composer install && cp .env.example .env && php artisan key:generate
> php artisan migrate --force && php artisan db:seed --force
> cd ../frontend && npm install
> ```
>
> Then edit `backend/.env` so the DB block matches the container: `DB_CONNECTION=mysql`,
> `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=cms`, `DB_USERNAME=cms`,
> `DB_PASSWORD=cms_secret` (what this machine's `cms-mysql` was created with).

**Terminal 1 — database and API**

```bash
# 1. Start Docker Desktop. On this machine it is installed under AppData, not Program Files:
cmd //c start "" "C:\Users\faiza\AppData\Local\Programs\DockerDesktop\Docker Desktop.exe"

# 2. Wait a few seconds, then start the database container:
docker start cms-mysql
docker ps --format "{{.Names}} | {{.Status}}"      # cms-mysql should say "Up"

# 3. Serve the API (stay in this terminal):
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

**Terminal 2 — the web app**

```bash
cd frontend
npm run dev            # listens on http://127.0.0.1:5173
```

Then open <http://127.0.0.1:5173>. It redirects you to `/login`.

### If something else already holds port 8000

Another dev server on this machine can take it — one was found there on 2026-10-22. Do not kill a process
you did not start. Put Laravel on a free port and tell Vite where to find it:

```bash
cd backend && php artisan serve --host=127.0.0.1 --port=8010

cd frontend && CMS_API_URL=http://127.0.0.1:8010 npm run dev        # Git Bash
# PowerShell equivalent:
#   $env:CMS_API_URL = "http://127.0.0.1:8010"; npm run dev
```

Without that second line every page shows its red "couldn't load" state, because Vite still proxies to
8000. The default is 8000, so day-to-day use needs no variable at all — see
[frontend/vite.config.ts](frontend/vite.config.ts).

## 2. Sign in

Every demo account shares the password `Password123!`. These are the accounts in the database as it
stands today (role and campus scope verified against it on 2026-10-22):

| Email | Role | Sees |
| --- | --- | --- |
| `admin@sunrise.edu.pk` | super_admin | all four campuses |
| `ho.admin@sunrise.edu.pk` | head_office_admin | all four campuses |
| `finance@sunrise.edu.pk` | finance_director | all four campuses |
| `principal.lhr@sunrise.edu.pk` | principal | Lahore |
| `principal.isb@sunrise.edu.pk` | principal | Islamabad |
| `principal.psh@sunrise.edu.pk` | principal | Peshawar |
| `admissions.lhr@sunrise.edu.pk` | admissions_officer | Lahore |
| `exams.lhr@sunrise.edu.pk` | exam_controller | Lahore |
| `teacher.lhr@sunrise.edu.pk` | class_teacher | Lahore |
| `hr.lhr@sunrise.edu.pk` | hr_manager | Lahore |
| `reception.khi@sunrise.edu.pk` | receptionist | head office |
| `guardian1@family.sunrise.edu.pk` | guardian | the phone app only — one child |
| `guardian2@family.sunrise.edu.pk` | guardian | the phone app only — one child |
| `guardian3@family.sunrise.edu.pk` | guardian | the phone app only — one child |

The three guardian accounts carry the `guardian` role (`dashboard.view`, `communication.view`,
`communication.reply`, `guardian.view`) and are linked to one enrolled child each at Lahore, so they open
the phone app at `/app` and see nothing else. As of 2026-11-05 they are assigned in this database; a fresh
`php artisan db:seed --class=RoleSeeder` plus the household part of `CommunicationSeeder` recreates them.

The password is `Password123!` unless the seeder was run with `CMS_SEED_PASSWORD` set
([backend/database/seeders/UserSeeder.php](backend/database/seeders/UserSeeder.php)).

The first time you sign in you land on **Select School** and pick a campus; after that you go straight to
the dashboard. The campus name in the header switches scope, and every figure on screen reloads for the
campus you choose.

The four campuses, and what each one is allowed to show:

| Code | Name | Type | Extra modules hidden |
| --- | --- | --- | --- |
| HO | Head Office — Karachi | secondary | — |
| LHR | Sunrise Model School — DHA Lahore | secondary | — |
| ISB | Sunrise Cambridge System — Islamabad | junior | hostel |
| PSH | Sunrise College — Peshawar | college | — |

Only three campus-life modules are gated by school type, in
[frontend/src/lib/schoolTypes.ts:28](frontend/src/lib/schoolTypes.ts#L28): hostel needs secondary or
above, while certificates and discipline need junior or above. Library, transport, front desk, calendar
and everything academic appear everywhere. So sign in as `principal.isb@sunrise.edu.pk` and the boarding
house is gone from the sidebar; a Montessori campus would also lose certificates and conduct cases.

The seeded logins and campus names still say "Sunrise" while the interface says Nation Campus. Re-seeding
renames only the super admin to `admin@nationcampus.edu.pk` — the other twelve keep their `@sunrise.edu.pk`
addresses, so the table above stays accurate until that seeder is updated.

### The phone app (`/app`)

A second, thumb-sized product lives at `http://127.0.0.1:5173/app`. It is the same React bundle, not a
separate build, and the desktop workspace at `/` is untouched.

| Who | How they get there | Tabs |
| --- | --- | --- |
| Family | signed in as `guardian1@…`: redirected to `/app` automatically | Children, Notices, Messages, Account |
| Class teacher | signed in as `teacher.lhr@…`: opens `/app` from the header link | Today, Roll call, Marks, Inbox, Account |

A teacher only sees the sections where `sections.class_teacher_id` is their own account, so
`teacher.lhr@sunrise.edu.pk` gets Grade 9 A/B and Grade 10 A/B. Roll call can be taken for the whole day
(the register the monthly sheet counts) or period by period from **My periods today**; a parent sees the
child marked present as soon as either is saved. Marks entry only offers papers that are not published yet.

To put it on a real phone, install it as an app: the manifest at `frontend/public/manifest.webmanifest`
makes it installable ("Add to Home screen" on the phone browser). It is offline-capable for the shell only —
`sw.js` never caches `/api/*`, because every response is scoped to one signed-in family or campus.

## 3. Prove it is healthy

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:5173/          # 200
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/up        # 200 — use whichever
                                                                          # port §1 step 3 used
cd backend && php artisan migrate:status                                  # every line "Ran"
cd backend && php artisan test                                            # 111 tests, no MySQL needed
```

`/up` is the app's own liveness endpoint: a 500 there means Laravel cannot read its config or reach the
database, while a 200 with an empty body means it can.

## 4. When it does not come up

| You see | What it means | Do this |
| --- | --- | --- |
| `ERR_CONNECTION_REFUSED` on 5173 | Vite is not running — the usual cause, since nothing auto-starts | terminal 2, `npm run dev` |
| Page loads, every panel red "couldn't load" | API not running, or Vite proxies the wrong port | restart §1 step 3, or use the `CMS_API_URL` lines above |
| "These credentials do not match our records" | Wrong email for this database, or wrong password | use the table in §2; check caps-lock — the password ends in `!` |
| Same message after correct attempts | Login is rate limited to 10 tries per minute | wait 60 seconds; `x-ratelimit-remaining` in the response header counts down |
| "Account is disabled. Contact your administrator." | That user's `is_active` flag is off | sign in as `admin@sunrise.edu.pk` and re-enable under Users |
| Bounced straight back to `/login` | Token expired, or the API 401s everything | sign in again; if it repeats, the API is down (§3) |
| Blank white page, no error card | A runtime error in the bundle | `cd frontend && npm run build` — `tsc -b` names the file and line |
| `php artisan migrate:status` hangs or errors | Docker daemon or the container is stopped | `docker ps`, then `docker start cms-mysql` |
| `/up` answers 404 HTML from Express | Port 8000 belongs to another app, so `artisan serve` is not running | start it on a free port and set `CMS_API_URL` (§1) |
| Select School lists no campuses | Database is migrated but not seeded | `php artisan db:seed` |
| A module you expect is missing from the sidebar | Not a bug — that campus's school type does not run it | switch campus, or see the gating table in §2 |

## 5. Stopping

`Ctrl+C` in each terminal, and `docker stop cms-mysql` when you want the database down too. Leaving Docker
Desktop running costs nothing but RAM; the two servers do not survive a reboot.

## 6. Two things to know before you share a link

- **This is local only.** The addresses above work on this machine and no other. Nothing is deployed;
  getting it on the internet is [docs/vercel.md](docs/vercel.md) plus a PHP host, and the server config is
  [deploy/nginx-cms.conf](deploy/nginx-cms.conf).
- **Do not expose these ports as they are.** `APP_DEBUG=true` prints stack traces, and every demo account
  uses `Password123!`. The records are synthetic, which is exactly why they are safe to look at locally —
  and not safe to publish.

To rebuild the whole demo dataset from scratch:

```bash
cd backend && php artisan migrate:fresh --seed     # destructive: drops every table
```

---

Module-by-module behaviour, permissions and settings are in [README.md](README.md).
