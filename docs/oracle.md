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

**Read the shape note before you pick anything.** The Ampere A1 free capacity — 4 OCPUs and 24 GB of
ARM — is only reservable once the account is **upgraded to Pay-As-You-Go**, even though usage inside
the free limits still bills at zero. A Free-tier-only account is not offered the ARM shapes at all.
What it does get is `VM.Standard.E2.1.Micro`: x86_64, 1 OCPU, 1 GB RAM, two instances allowed. This
guide targets that box, because it needs no card:

| Setting | Value | Why |
| --- | --- | --- |
| Image | **Ubuntu 24.04** (or Oracle Linux 9) | `apt`/`yum` both work; Ubuntu has the widest Docker docs |
| Shape | **VM.Standard.E2.1.Micro** | the only Always Free compute available without upgrading |
| Boot volume | 200 GB | Always Free block storage ceiling; backups land here |
| Public IP | **Ephemeral** — the only option here | it changes on every stop/start; see the warning below |
| SSH keys | paste this public key | Oracle refuses password auth on the console |

If you do upgrade the account later, `Ampere A1` with 1-2 OCPUs is the better machine and nothing in
this guide changes except the shape and the RAM note — the same bundle, the same compose file.

**Ephemeral IP is the sharp edge.** Stop the instance, start it, and it has a new address: the
certificate, `APP_URL` and every bookmark break together. Fix is two lines — edit `CMS_DOMAIN` and
`APP_URL` in `.env` to the new IP, then `docker compose up -d caddy`. Never *terminate* the instance
to save money; termination deletes the boot volume with it. Stop is enough, and a stopped instance
keeps its volume.

### Security list (this step is missed most often)

Oracle's VCN firewall sits in front of the OS firewall. Under the instance's VCN → Subnet →
Security List, add ingress rules:

| Source | Protocol | Destination port |
| --- | --- | --- |
| `0.0.0.0/0` | TCP | `22` (or a narrower source, strongly recommended) |
| `0.0.0.0/0` | TCP | `80` — HTTP→HTTPS redirect, and ACME if you later add a domain |
| `0.0.0.0/0` | TCP | `443` |

Do **not** open `3306`. MySQL is never published by this compose file.

## 2. No domain: HTTPS on a bare IP

Without a hostname there is nothing for Let's Encrypt to issue for, so Caddy falls back to **its own
CA** and serves `https://<public-ip>` — verified against this exact image: the certificate appears
under `/data/caddy/pki/authorities/local/` and the site answers over TLS.

That works, but every device sees an untrusted issuer, and the consequence for the phone app is
specific rather than cosmetic: **Chrome will not register a service worker over a certificate it does
trust**, so the install prompt never appears and `/app` degrades to a ordinary browser tab. Two ways
to get the real thing:

1. **Trust Caddy's root CA on the device.** Export it once from the VM
   (`docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./cms-ca.crt`) and install it:
   on Android, Settings → Security → *Install a CA certificate*; on Windows,
   `certmgr.msc` → Trusted Root Certification Authorities → Import. After that the padlock is green,
   the manifest loads and *Add to home screen* appears.
2. **Skip the PWA for now** and use it as a bookmarked HTTPS site. Everything except installation
   works — login, roll call, the gate desk, notifications inside the app.

The clean answer for families is still a domain, and it is a small one: point an `A` record at the IP,
put the hostname in `CMS_DOMAIN` and `APP_URL`, restart Caddy, and it swaps its internal CA for a real
Let's Encrypt certificate with no other change. Nothing in the image or the app has to be rebuilt for
that, so there is no reason to buy a domain today.

## 3. Install Docker

```bash
ssh ubuntu@<public-ip>
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER   # log out and back in for the group to take effect
docker --version && docker compose version
```

**Add swap before the first build.** The box has 1 GB of RAM and the build stages want more than
that: `npm ci` and the PHP `intl` compile together will get the build container OOM-killed, which
looks like a mysterious `exit code 137` rather than an error. Four gigabytes on the boot volume costs
nothing and is only borrowed:

```bash
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
sudo sysctl vm.swappiness=10          # keep MySQL resident; swap is for builds, not for runtime
free -h                               # confirm the Swap line is 4.0Gi
```

Ubuntu 24.04 on Oracle also ships `unattended-upgrades` holding apt locks during the first half hour;
if `get.docker.com` reports a locked dpkg, wait and re-run rather than killing it.

## 4. Put the code on the VM

Build on the VM. `VM.Standard.E2.1.Micro` is x86_64, the same architecture as this laptop, so a saved
image would technically run — but if you later take an Ampere A1 instance it is ARM64 and that tarball
would not, whereas the source bundle runs anywhere. Source is the portable artifact; the image is
throwaway.

```bash
git clone <your-repository-url> ~/cms
cd ~/cms
```

**No repository yet?** The deploy does not need one. From the laptop, `git archive` makes a
commit-pinned bundle of exactly the tracked files — source only, no `vendor/`, no `node_modules/`, no
`.env`:

```bash
git archive --format=tar.gz -o cms-src.tar.gz HEAD      # about 1 MB
scp cms-src.tar.gz ubuntu@<public-ip>:~
```

Then on the VM:

```bash
mkdir -p ~/cms && tar -xzf ~/cms-src.tar.gz -C ~/cms
```

A later deploy is the same two commands with a fresh bundle, and the old one stays in
`~/cms` untouched until you overwrite it — which is why the image, not the folder, is the unit of
rollback.

In either case:

```bash
cd ~/cms
cp deploy/compose.env.example .env
nano .env                       # every blank must be filled; see the table below
chmod 600 .env
```

| Key | Notes |
| --- | --- |
| `CMS_DOMAIN` | the VM's public IP for now, e.g. `168.138.42.7`; later, the exact hostname |
| `APP_URL` | `https://` + the same value, written out in full |
| `APP_KEY` | `docker compose run --rm app php artisan key:generate --show` → paste the `base64:…` value |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | long random strings, not the ones used anywhere else |

`CMS_DOMAIN` is what Caddy answers, so an IP here produces the self-signed certificate described in
section 2 — the warning is expected, not a failed install.

`.env` belongs to the machine and is git-ignored. It is the only file you edit.

## 5. Build and start

```bash
docker compose up -d --build     # first build compiles the SPA and installs composer deps
docker compose ps                # app (healthy), mysql (healthy), caddy, scheduler
docker compose logs --tail=50 app
```

Expect the first `--build` to take a while on this shape — 20 to 40 minutes on a single OCPU, because
the PHP `intl` and opcache compiles are CPU-bound and `npm ci` runs in the same stage. It happens
once: every later build reuses those layers, so a routine deploy is the SPA and API copy steps only,
which take seconds. If a build stage dies with exit code 137, that is the OOM killer and swap
(section 3) is the fix, not a retry.

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

Without a repository, replace `git pull` with the same bundle-and-extract pair from section 4 —
`scp` the new `cms-src.tar.gz`, `tar -xzf` it over `~/cms`, then build.

The old image stays until the new containers are healthy, so a rollback is
`docker compose up -d` against the previous tag. There is no host-side `npm` or `composer` install
to keep in sync; the build stages inside the image do both.

## 9. If something is wrong

| Symptom | Cause |
| --- | --- |
| `docker compose ps` shows `mysql` restarting | `.env` changed `DB_PASSWORD` after the volume initialised — the volume keeps the old credentials. Recreate it or set the old value back. |
| Build stage dies with exit code `137` | the OOM killer on 1 GB — add swap (section 3) before retrying; the retry alone will fail again |
| HTTPS never comes up, Caddy log shows `acme error` | `CMS_DOMAIN` holds a hostname that does not resolve here. On a bare IP Caddy should *not* attempt ACME at all; if it does, the value is a name you do not own yet |
| Browser warns `NET::ERR_CERT_AUTHORITY_INVALID` | expected on the IP install — Caddy's own CA. Either trust `root.crt` per section 2 or add a domain; dismissing the warning does **not** enable the service worker |
| No *Add to home screen* prompt on Android | the certificate is untrusted (previous row). Chrome only installs PWAs over a chain it trusts |
| `404` on a hard refresh of `/gate` | the image was built without `frontend/` — `docker compose exec app ls public` (that shell already starts in `backend`) should show `index.html` and `assets/` |
| `502` from Caddy | app container still booting, or `APP_KEY` missing so the entrypoint exited (`docker compose logs app`) |
| Site unreachable after a stop/start | the ephemeral IP changed — see section 1; update `CMS_DOMAIN` and `APP_URL`, then `docker compose up -d caddy` |
| Audit Log records the container IP, not the visitor | `TRUSTED_PROXIES` cleared from `.env`; nginx hands Caddy's `X-Forwarded-For` straight through |
| Sign-in works, then every request is 401 | clock skew — a token check compares against PHP's clock; confirm `APP_TIMEZONE` and that the VM's NTP is running (`timedatectl`) |

## 10. Costs and limits to know

- **Always Free is a ceiling, not a trial** — but the ceiling depends on the account. On a
  Free-tier-only account you get the two x86 micro instances (1 OCPU / 1 GB each), 200 GB of block
  storage and 10 TB of monthly egress at no cost. The 4 OCPU / 24 GB ARM capacity is *also* free to
  use, but Oracle only exposes it after the account is upgraded to Pay-As-You-Go, which requires a
  card and still bills zero inside the free limits.
- **Ephemeral IP is the real tax on the free path.** Every stop/start changes the address, so a
  bookmarked school becomes unreachable until `CMS_DOMAIN` and `APP_URL` follow it. A reserved Flex IP
  — which needs the same upgrade — is what makes the deploy stable, and it is worth doing before
  families are told the URL.
- **1 GB is workable, not comfortable.** MySQL is capped in `docker-compose.yml` (128 MB buffer pool,
  `performance_schema=OFF`) so it coexists with PHP-FPM and nginx; keep swap configured anyway, because
  the build and a `mysqldump` are the two moments that spike.
- Oracle has no data centre in Pakistan; the nearest are Saudi Arabia and the UAE (Dubai). Expect
  roughly 40–90 ms of extra latency for campus users compared with a local host, which is
  acceptable for this app and invisible on cached pages.
