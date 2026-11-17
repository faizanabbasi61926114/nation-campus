# Deploying the CMS on an Oracle Cloud Always Free VM

One VM, one `docker compose up`, one origin: Caddy terminates TLS, the app container serves the
built SPA and the API together, and MySQL stays on the private Docker network with no published
port. Nothing here needs a second service — no Vercel, no CDN rewrite, no `VITE_API_URL`.

```
visitor ── https://cms.example.edu.pk ──▶ caddy :80/:443
                                            └── app :8080 ── nginx ─┬─ /            → backend/public (SPA)
                                                                    ├─ /assets/*    → static, cached 1 year
                                                                    └─ /api/v1/*    → PHP-FPM → Laravel
                                                          mysql :3306 (compose network only)
```

## 1. Create the instance

Oracle Cloud → Compute → Instances → **Create an instance**, choosing:

| Setting | Value | Why |
| --- | --- | --- |
| Image | **Ubuntu 24.04** (or Oracle Linux 9) | `apt`/`yum` both work; Ubuntu has the widest Docker docs |
| Shape | **Ampere A1**, 4 OCPUs / 24 GB RAM | Always Free, and far more than this app needs |
| Boot volume | 200 GB | Free tier allows 200 GB total block storage; backups land here |
| Public IP | **Ephemeral** or a reserved **Flex** public IP | A reserved IP survives a stop/start, so DNS stays valid |
| SSH keys | your public key | Oracle refuses password auth on the console |

The A1 free shape is capacity-limited in some regions. If `Create` reports
`Out of capacity for the requested shape`, try a different availability domain, or fall back to
`VM.Standard.E2.1.Micro` (1 OCPU / 1 GB) — that runs the app but not comfortably alongside MySQL;
see the note at the end.

### Security list (this step is missed most often)

Oracle's VCN firewall sits in front of the OS firewall. Under the instance's VCN → Subnet →
Security List, add ingress rules:

| Source | Protocol | Destination port |
| --- | --- | --- |
| `0.0.0.0/0` | TCP | `22` (or a narrower source, strongly recommended) |
| `0.0.0.0/0` | TCP | `80` — required for Let's Encrypt HTTP-01 |
| `0.0.0.0/0` | TCP | `443` |

Do **not** open `3306`. MySQL is never published by this compose file.

## 2. Point DNS at the VM

An `A` record for `cms.example.edu.pk` → the instance's public IP. Caddy cannot obtain a
certificate until this resolves; verify with `dig +short cms.example.edu.pk` from anywhere.

## 3. Install Docker

```bash
ssh ubuntu@<public-ip>
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER   # log out and back in for the group to take effect
docker --version && docker compose version
```

## 4. Put the code on the VM

```bash
git clone <your-repository-url> ~/cms
cd ~/cms
cp deploy/compose.env.example .env
nano .env                       # every blank must be filled; see the table below
chmod 600 .env
```

| Key | Notes |
| --- | --- |
| `CMS_DOMAIN` | must match DNS exactly |
| `APP_URL` | `https://` + the same domain, written out in full |
| `APP_KEY` | `docker compose run --rm app php artisan key:generate --show` → paste the `base64:…` value |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | long random strings, not the ones used anywhere else |

`.env` belongs to the machine and is git-ignored. It is the only file you edit.

## 5. Build and start

```bash
docker compose up -d --build     # first build compiles the SPA and installs composer deps
docker compose ps                # app (healthy), mysql (healthy), caddy, scheduler
docker compose logs --tail=50 app
```

Expect the first `--build` to take several minutes on its own: the image compiles the PHP `intl`
extension and runs `npm ci` inside the build stages. Every later build reuses those layers, so a
routine deploy is the SPA and API copy steps only.

Then run the schema:

```bash
docker compose exec app php artisan migrate --force
```

`docker compose up` deliberately does **not** migrate on its own — a restart of a healthy service
must never be a schema change. If you prefer it automated, set `CMS_MIGRATE=1` in `.env`.

## 6. First login — and then remove the demo data

Open `https://cms.example.edu.pk`, sign in, and confirm the network tab shows no mixed-content
warnings. Before any real person uses it:

1. Create the real head-office account under **Users**, with a password of at least eight
   characters (`config/cms.php` sets `password_min`).
2. Delete every seeded account and seeded student, or better, do not seed at all: the seeders only
   run if you invoke them, and this guide never does.
   `docker compose exec app php artisan tinker` if you need to remove them by hand.
3. Rotate the super admin password from the app (**Account → Change password**), which also drops
   its API tokens.

The seeded `Password123!` accounts are a local development convenience. On a public host they are
an open door: the login route is throttled at ten attempts per minute per IP, but a shared
password needs no guessing.

## 7. Backups

One command, and it is the whole restore story:

```bash
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines cms' \
  | gzip > ~/cms-$(date +%F).sql.gz
```

The password lives in the mysql container's own environment, so the single-quoted `sh -c` expands it
there and it never appears in your shell history. The "password on the command line" warning mysqldump
prints goes to stderr and does not pollute the dump.

Put that in the host's crontab, and copy the file off the VM — Oracle releases an instance whose
storage you cannot rebuild. Object storage in the same tenancy is the usual second home:

```bash
0 2 * * * cd /home/ubuntu/cms && docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines cms' | gzip > /home/ubuntu/backups/cms-$(date +\%F).sql.gz
```

Restore is
`gunzip -c cms-2026-11-14.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cms'`.

## 8. Shipping a change

```bash
cd ~/cms
git pull
docker compose up -d --build          # rebuilds the image with the new SPA and API
docker compose exec app php artisan migrate --force   # only when a migration ships
```

The old image stays until the new containers are healthy, so a rollback is
`docker compose up -d` against the previous tag. There is no host-side `npm` or `composer` install
to keep in sync; the build stages inside the image do both.

## 9. If something is wrong

| Symptom | Cause |
| --- | --- |
| `docker compose ps` shows `mysql` restarting | `.env` changed `DB_PASSWORD` after the volume initialised — the volume keeps the old credentials. Recreate it or set the old value back. |
| HTTPS never comes up, Caddy log shows `acme error` | DNS not pointing at this IP yet, or port 80 blocked by the VCN security list |
| `404` on a hard refresh of `/gate` | the image was built without `frontend/` — `docker compose exec app ls public` (that shell already starts in `backend`) should show `index.html` and `assets/` |
| `502` from Caddy | app container still booting, or `APP_KEY` missing so the entrypoint exited (`docker compose logs app`) |
| Audit Log records the container IP, not the visitor | `TRUSTED_PROXIES` cleared from `.env`; nginx hands Caddy's `X-Forwarded-For` straight through |
| Sign-in works, then every request is 401 | clock skew — a token check compares against PHP's clock; confirm `APP_TIMEZONE` and that the VM's NTP is running (`timedatectl`) |

## 10. Costs and limits to know

- **Always Free is a ceiling, not a trial.** A1 compute, 200 GB block storage and 10 TB egress per
  month stay free while the account stays in good standing. Keep a card on file or Oracle can
  reclaim idle ARM capacity.
- **A flexible billing account** is required to create Flex public IPs; a free-tier-only account
  gets an ephemeral IP that changes on every stop/start, which breaks DNS and invalidates nothing
  certificate-wise (Caddy re-issues on its own).
- **1 GB ARM micro shape** boots but leaves little headroom for `mysqldump` plus PHP-FPM; if you
  end up there, add swap before the first backup.
- Oracle has no data centre in Pakistan; the nearest are UAE (Dubai) and Saudi Arabia. Expect
  roughly 40–90 ms of extra latency for campus users compared with a local host, which is
  acceptable for this app and invisible on cached pages.
