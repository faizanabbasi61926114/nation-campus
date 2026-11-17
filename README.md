# Nation Campus — multi-campus School & Campus Management System

A production-oriented CMS for a Pakistani school group: one Laravel REST API, one React SPA, one MySQL
database, with every record scoped to an organization and, where it matters, to a campus.

| Layer | Choice |
| --- | --- |
| API | PHP 8.4, Laravel 13, Sanctum 4 token auth |
| Frontend | React 19, TypeScript, Vite 8, Tailwind CSS 4 |
| Database | MySQL 8.4 (SQLite in-memory for tests) |
| Tenancy | Organization → campuses, resolved per request from the token and `X-Campus-Id` |

---

## 1. Bring-up

For running it locally day-to-day — exact Windows paths, sign-in accounts and a troubleshooting table —
see **[HOW-TO-RUN.md](HOW-TO-RUN.md)**. The condensed version:

```bash
# 1. database (the dev container is named cms-mysql: MySQL 8.4 on 127.0.0.1:3306)
cd backend
cp .env.example .env                      # then set the DB block below
php artisan key:generate

# .env
#   DB_CONNECTION=mysql  DB_HOST=127.0.0.1  DB_PORT=3306
#   DB_DATABASE=cms  DB_USERNAME=cms  DB_PASSWORD=<your local password>
#   APP_URL=http://127.0.0.1:8000  CMS_FRONTEND_URL=http://127.0.0.1:5173

php artisan migrate --force
php artisan db:seed --force               # permissions, roles, 4 campuses, demo people and records
php artisan serve --host=127.0.0.1 --port=8000

# 2. SPA
cd ../frontend
npm install
npm run dev                               # http://127.0.0.1:5173
```

`php artisan migrate:fresh --seed` rebuilds the whole demo dataset. Sample logins are listed in
`backend/database/seeders/UserSeeder.php` and share the development password `Password123!`. Seed
accounts are demo data only — never seed them into a live instance, and rotate every password before
handing the system to a school.

Tests do not need MySQL: `phpunit.xml` pins `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.

```bash
cd backend && php artisan test            # 14 feature classes, 111 tests / 1,175 assertions
cd frontend && npm run build              # tsc -b && vite build
```

---

## 2. Tenancy and access control

Every request that touches data passes through three middleware, in this order:

| Middleware | Route key | Job |
| --- | --- | --- |
| `EnsurePermission` | `perm:fees.manage` | Comma-separated list = OR. Returns 403 otherwise. |
| `ResolveCampus` | `tenant` | Reads `X-Campus-Id`, checks it against the user's accessible campuses, publishes a `TenantContext`. |
| `EnforceTenantOnBindings` | `tenant.bindings` | Re-checks each route-model-bound record against the organization/campus after binding, so a guessed ID gets 404, not a leak. |

Two model traits carry the rest of the burden:

- **`BelongsToTenant`** — stamps `organization_id` / `campus_id` on create and adds a global scope.
  Rows with a null `campus_id` are organization-wide and stay visible to campus users (that is how
  head-office announcements and calendar events reach every campus).
- **`Auditable`** — writes an `AuditLog` row on create, update and delete, with the acting user, IP
  and user agent.

A user's permissions are the union of the permissions of their assigned roles, cached into the
Sanctum token's abilities at login. Changing a role revokes the active tokens, so the next request
fails until the user signs in again. Campus scope per role is a pivot column (`role_user.campus_id`):
a null pivot means "every campus in the organization".

### Roles

`super_admin`, `head_office_admin`, `principal`, `admissions_officer`, `class_teacher`,
`exam_controller`, `accounts_officer`, `finance_director`, `hr_manager`, `receptionist`, `guardian`,
`librarian`, `hostel_warden`. `super_admin` holds `*`; the rest are built from a permission catalogue
in `database/seeders/PermissionSeeder.php` that any `roles.manage` holder can re-cut per organization.

### Institution types

A campus has a `school_type` — Montessori, Junior School, Secondary (Matric), Higher Secondary,
College or University. The type decides which campus-life modules that school runs (a junior school
has no boarding house; certificates and conduct cases start from junior school upwards), and the SPA
hides the entries that do not apply. See `frontend/src/lib/schoolTypes.ts` for the matrix and
`config/cms.php` for the vocabulary.

Multi-campus staff choose a school after signing in (`/select-school`); the choice sets the data
scope and can be changed at any time from the header.

---

## 3. Modules

Each module is a migration + model + controller + API routes + React page + feature test. All
responses are `{"data": …}`, creates return 201, and validation uses `Rule::in(...)` against
documented vocabularies rather than database enums.

| Group | Pages | API surface (under `/api/v1`) |
| --- | --- | --- |
| Overview | Dashboard, Reports | `dashboard`, `reports/trial-balance`, `reports/profit-loss`, `reports/balance-sheet` |
| Admissions & Students | Admissions, Students | `admissions` (pipeline + enrolment into a student record), `students`, `student-documents` |
| Academics | Programs & Classes, Timetable, Attendance, Examinations | `programs`, `classes`, `sections`, `subjects`, `academic-years`, `grading-scales`, `timetable`, `attendance`, `exams`, `exams/{id}/marks`, results publishing |
| Campus Life | Front Desk, School Calendar, Library, Transport, Hostel, Certificates, Discipline | `frontdesk/visitors`, `calendar`, `library/books`, `library/loans`, `transport/vehicles`, `transport/routes`, `transport/assignments`, `hostel/blocks`, `hostel/rooms`, `hostel/allotments`, `certificates`, `discipline/cases` |
| Finance | Fee Management, Accounts, Payroll | `fee-structures`, `fee-invoices`, `fee-payments`, `fee-waivers`, `accounts`, `vouchers`, `payroll`, `payslips` |
| Staff & HR | Staff Directory, Leave | `staff`, `departments`, `designations`, `leave-applications` |
| Communication | Announcements, Messages | `announcements`, `messages`, `communication/logs` |
| Administration | Campuses, Users, Roles & Permissions, Audit Log, Settings | `campuses`, `users`, `roles`, `permissions`, `audit-logs`, `settings` |

Business rules worth knowing, because they are enforced server-side and not only in the UI:

- An admission can only be enrolled into a class with a free seat, and minting a student writes
  `admission_no` plus a per-campus roll number.
- Attendance can be marked once per student per day; a percentage below
  `attendance.warning_threshold` puts the student on the at-risk list.
- Marks are validated against the subject's total; results stay unpublished until the exam
  controller publishes them.
- A fee payment cannot exceed the outstanding invoice balance; waivers need
  `fees.waivers_approve` and reduce the invoice, they do not rewrite history.
- Vouchers must balance (debits = credits) and can be reversed, never deleted.
- Payroll applies EOBI and tax from settings, and a processed run is locked.
- A library copy can only be issued if the last unreturned copy exists; overdue fines accrue per day
  from `library.fine_per_day`.
- Transport assignment refuses a student who already holds an active route and refuses to overload a
  vehicle beyond its capacity. A route stop cannot be deleted while a student is assigned to it.
- Hostel allotment checks gender against the block and refuses a bed that is already taken.
- Visitor passes are numbered `V-YYMMDD-NN` per campus per day; checkout twice is rejected.
- Certificate requests mint a per-organization reference (`CERT/LHR/0007`) and only print once issued.

---

## 4. Settings

`settings` rows are dotted keys scoped either to the organization (`campus_id` null) or to one
campus, and `Setting::getFor($org, $campus, $key, $default)` resolves campus → organization → default.
The Settings page groups them into Academic year, Fees, Attendance, Staff and leave, Payroll,
Communication, Library, Hostel and Transport, and stages edits: nothing is written until *Save
settings*, and a campus user can only change their own campus's rows.

Pakistan-specific defaults seeded for the demo group: April–March academic year, PKR, BISE/FBISE
board list, 40% pass mark with A+/A/B/C/D bands, fee due on the 10th, 2% late fee, cash / bank
transfer / JazzCash / Easypaisa / cheque channels, Sunday week-off, EOBI at 2% on a 60,000 wage
ceiling, and a 10% payroll tax above a 600,000 annual threshold.

CNIC and Pakistani phone numbers are validated by pattern (`config/cms.php`). Outbound SMS defaults to
the `log` driver: nothing leaves the server until `SMS_DRIVER=gateway` plus `SMS_GATEWAY_URL` and
`SMS_GATEWAY_API_KEY` are set in the environment. Gateway credentials are deliberately **not**
storable in the settings table, because `/settings` is readable by anyone with `settings.view`.

---

## 5. Frontend conventions

- `components/ui.tsx` is the only kit: `Card`, `Btn`, `Field`, `Table/Th/Td`, `Modal`, `Tabs`,
  `StatCard`, `Badge`, `Status`, `Empty`, `Notice`, `ErrorNote`, `Pagination`, plus `inputCls` and
  `selectCls`. Pages compose the kit; they do not invent markup.
- `lib/labels.ts` is the single plain-English layer for stored vocabulary (`no_show` → "Not marked",
  `head_office_admin` → "Head Office Admin") and for colour semantics: green = done, amber = waiting,
  rose = problem, blue = informational, slate = neutral or closed. New statuses get a word here, not
  an inline ternary.
- `Toast` and `askConfirm()` replace `alert()`/`confirm()`; destructive actions route through the
  confirm host, and Escape/scroll-locking are shared so stacked modals behave.
- Every page fetches through `useApi(path, deps)`, which refetches when the campus scope changes, so
  switching school never leaves another campus's figures on screen.
- `npm run build` runs `tsc -b` first; the type check is part of the build, not a separate chore.

---

## 6. Operations

- **Scheduler**: one entry, `announcements:publish-due` every fifteen minutes
  (`bootstrap/app.php`). The compose stack runs it as its own `scheduler` service via
  `php artisan schedule:work`; on a non-Docker install, add
  `* * * * * php artisan schedule:run` to cron on the app server.
- **Clock**: `config/app.php` reads `APP_TIMEZONE` and defaults to `Asia/Karachi`, so `now()`, daily
  attendance cutoffs and "today" reports all roll at Pakistan midnight. MySQL containers normally run
  UTC, so no query uses a DB-side `NOW()` — every clock read goes through Carbon, and the naive local
  strings that land in the columns stay the wall-clock time a campus user expects to see.
- **Backups**: take a consistent dump before any destructive change, and keep the daily copy off the
  host — `mysqldump --single-transaction --routines cms | gzip > cms-$(date +%F).sql.gz`.
  Restoring is `gunzip -c file.sql.gz | mysql cms`.
- **Production `.env`**: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`,
  `SESSION_SECURE_COOKIE=true`, queue driver `database` or `redis`, and a distinct database user per
  environment with no `FILE` privileges.
- **Deploying to the internet**: [`docs/oracle.md`](docs/oracle.md) is the recommended shape — one
  Dockerfile, one `docker compose up`, and the SPA and API served from the same origin by the app
  container, so nothing needs a `VITE_API_URL` or a CDN rewrite. [`docs/vercel.md`](docs/vercel.md)
  covers the split alternative (SPA on Vercel, API on a host you control); if you take it, set
  `TRUSTED_PROXIES` on the API host (see `backend/config/trustedproxy.php`) or the audit log records
  the edge's address instead of the visitor's.
- **Web server**: the Docker image configures nginx itself in [`docker/default.conf`](docker/default.conf).
  For a non-Docker install, [`deploy/nginx-cms.conf`](deploy/nginx-cms.conf) is a ready server block for
  `backend/public` with `try_files $uri /index.php?$query_string`, HSTS and nosniff headers, dotfile
  denial, and the `/up` liveness route. Client-side deep links resolve through the catch-all in
  `backend/routes/web.php`, which answers with `public/index.html` when the SPA has been built into it.
- **HTTPS/HSTS** in front of both, and rate limiting is already applied to `auth/login`.

## 7. Known gaps

- The Reports page is a permission-filtered launcher; a server-side report catalogue with CSV export
  is not built yet, and the three financial statements are the only computed reports.
- The app clock is `Asia/Karachi` (override with `APP_TIMEZONE`); a dev database seeded while it was
  still UTC holds a handful of timestamps five hours behind the local school day. Re-seed to refresh.
- Students and Admissions request the first 50 matching rows and offer no page controls, while Audit
  and Users page through the shared `Pagination` component.
- SMS is a `log` driver by design in this repository — no gateway credentials are committed, and no
  message leaves the server until an operator configures one.
