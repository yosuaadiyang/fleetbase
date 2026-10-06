# Deploying Contrust on a VPS

This guide runs Contrust in production on a single Linux server with Docker Compose.
The installer sets up HTTPS with automatically renewed Let's Encrypt certificates, and
only ports 80 and 443 are open to the internet.

```
                 ┌──────────────────── your server ─────────────────────┐
 app.example.com │  caddy :443 ──► console (Ember app)                   │
 api.example.com │       └──────► httpd ──► application (API)            │
                 │       └──────► socket (/socketcluster/, real-time)    │
                 │  queue · scheduler · database (MySQL) · cache (Redis) │
                 └───────────────────────────────────────────────────────┘
```

## 1. What you need

| | Minimum | Recommended |
|---|---|---|
| CPU | 2 vCPU, **x86_64** | 4 vCPU |
| Memory | 4 GB **plus 4 GB swap** | 8 GB |
| Disk | 40 GB SSD | 80 GB SSD |
| OS | Ubuntu 22.04 / 24.04 or Debian 12 | |

- **Use an x86_64 (amd64) server, not ARM.** The SocketCluster image is amd64-only, and
  the published API images up to v0.7.68 ship an amd64 cron binary in their arm64
  variant, so the scheduler cannot start on ARM.
- **Two domain names**, for example `app.example.com` (console) and `api.example.com`
  (API). Create a DNS **A** record (and **AAAA** if the server has IPv6) for each, pointing
  at the server's public IP, *before* installing. Certificates can only be issued once DNS
  resolves to the server.
- **Ports 80 and 443** open in your provider's firewall or security group.
- **Docker Engine with Compose 2.24.4 or newer.** The installer checks this.

## 2. Prepare the server

Run as a user with `sudo`.

```bash
# Docker Engine + Compose plugin (official convenience script)
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # then log out and back in

# Swap: the production console build peaks at 4–5 GB of memory
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Firewall: SSH, HTTP and HTTPS only
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp
sudo ufw enable

sudo apt-get install -y git openssl
```

> Docker's published ports bypass `ufw`. In production mode only Caddy publishes ports
> (80/443); MySQL, Redis, the API and the console are reachable only inside Docker's
> network.

## 3. Install

```bash
git clone https://github.com/<you>/fleetbase.git
cd fleetbase
bash scripts/docker-install.sh
```

Answer the prompts:

| Prompt | Answer |
|---|---|
| Environment | `production` |
| Console domain | `app.example.com` |
| API domain | `api.example.com` |
| Email for Let's Encrypt | an address you read (expiry notices) |
| Database | `1` (bundled MySQL) unless you have a managed database |
| Mail server | **`y`**: password resets, invitations and notifications need it |
| Storage | `1` (local disk) is fine on one server; back it up (step 6) |

The first run builds the console (10–20 minutes) and then migrates and seeds the
database. When it finishes, open `https://app.example.com` and create your
administrator account and organization.

What the installer writes (all ignored by git except the console files):

| File | Holds |
|---|---|
| `.env` | `COMPOSE_FILE` (adds `docker-compose.prod.yml`), your domains, the image version |
| `docker-compose.override.yml` | `APP_KEY`, database credentials, mail and other settings — **back this up** |
| `api/.env` | optional extra API settings (empty by default) |
| `console/fleetbase.config.json` | the API and socket addresses the console uses |

Because `.env` sets `COMPOSE_FILE`, plain `docker compose …` commands in this directory
use the production configuration automatically.

## 4. Check it works

```bash
docker compose ps                         # every service "Up"; queue/scheduler "healthy"
curl https://api.example.com/health       # {"status":"ok",...}
docker compose logs -f caddy              # certificate issuance
```

In the console, change an order's status in a second browser tab: the first tab updates
without reloading. That confirms the real-time socket path.

## 5. Change settings later

Edit `docker-compose.override.yml` (under `x-api-environment`, shared by the API, queue
and scheduler), then:

```bash
docker compose up -d
docker compose exec application php artisan config:cache
```

Re-running `bash scripts/docker-install.sh` is also safe: it keeps the existing
`APP_KEY` and database credentials, backs up the old override, and rebuilds the console.
It asks every question again, so re-enter your mail settings.

## 6. Back up

Back up three things: the database, uploaded files, and your configuration.

```bash
# Database (both the live and the sandbox schema)
source <(grep -E '^ +MYSQL_ROOT_PASSWORD:' docker-compose.override.yml | sed 's/^ *MYSQL_ROOT_PASSWORD: */PW=/')
docker compose exec -T database mysqldump -uroot -p"$PW" --single-transaction --routines \
  --databases fleetbase fleetbase_sandbox | gzip > "contrust-$(date +%F).sql.gz"

# Uploaded files and configuration
tar czf "contrust-files-$(date +%F).tar.gz" api/storage/app \
  docker-compose.override.yml .env api/.env console/fleetbase.config.json
```

Copy the archives off the server (for example with `rclone` or `scp`), and schedule
both commands daily with `cron`.

## 7. Update

```bash
cd fleetbase
# back up first (step 6)
git stash                     # the installer-written console files
git pull
git stash pop
VERSION=$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' console/package.json | head -n1)
sed -i "s/^FLEETBASE_VERSION=.*/FLEETBASE_VERSION=v$VERSION/" .env   # API image to match
docker compose pull
docker compose up -d --build
docker compose exec application ./deploy.sh
```

`deploy.sh` runs the new migrations and refreshes permissions and caches.

## 8. Troubleshooting

| Symptom | Check |
|---|---|
| Browser shows a certificate error | `docker compose logs caddy`. DNS must point at this server and ports 80/443 must be reachable from the internet. Caddy retries on its own. |
| Console loads but shows "network error" | `console/fleetbase.config.json` must have `"API_HOST": "https://api.example.com"`. After changing it, browsers may keep the old value for up to an hour (it is cached). |
| No live updates (map, activity) | `docker compose logs socket` for `Invalid origin`. `SOCKETCLUSTER_OPTIONS` in the override must list both domains and `"null:*"`. |
| `queue` or `scheduler` unhealthy | `docker compose logs queue`; their healthchecks fail when they cannot reach the database. |
| Emails never arrive | `MAIL_MAILER` in the override is `log` (the default); configure a real mailer (step 5). |
| Build killed / exit 137 | Out of memory: add swap (step 2), or stop the stack (`docker compose stop`) while it builds. |
| Sign-up says a name "contains forbidden words" | Deliberate spam filter on names and organization names (for example "test"). Use your real name and company name. |
| `/fleet-ops/settings/map` returns 500 | `GOOGLE_MAPS_API_KEY` is missing from the override. Re-run the installer, or add `GOOGLE_MAPS_API_KEY: ""` under `x-api-environment` (step 5). |

## Branding and license

This is a rebranded build of [Fleetbase](https://github.com/fleetbase/fleetbase), an
open-source project licensed under the **GNU AGPL-3.0**.

- **Name**: the installer's "Application name" (default `Contrust`) is used in emails and
  as the sender name. The console's own text says Contrust (`console/config/brand.js`).
- **Logo and icon**: placeholder Contrust artwork ships in `console/public/images` and
  `console/public/favicon`. Upload your real logo and icon in the console under
  **Admin → Branding**; they then replace the defaults in the console and in emails.
  To change the defaults themselves, replace those image files and rebuild
  (`docker compose up -d --build`).
- **Rebranded at build time**: the console regenerates the brand on every build
  (`console/config/brand*.js`), including text inside the extension packages, so it
  survives package updates. A few labels the API itself sends (the "managed" role and
  policy types, the seeded developer role) are relabelled in the console; the API's
  test email is overridden in `api/resources/views/vendor`.
- **Kept on purpose**: package, namespace, database and image names still say
  `fleetbase` (for example `@fleetbase/ember-core`, `fleetbase/fleetbase-api`). They are
  identifiers the code and the published packages depend on; users never see them.

The AGPL-3.0 applies to anyone who offers this software over a network:

- Keep the **"Legal"** link under the sign-in form. It shows the license notices the
  AGPL requires, and credits Fleetbase as the upstream project.
- Users of your deployment must be able to get **its source code**, including your
  changes. The "Legal" notice links to `https://github.com/yosuaadiyang/fleetbase`; if
  that repository is private, make it public or point the link at a public copy: add
  `SOURCE_CODE_URL=https://…` to `.env` and run `docker compose up -d --build`.
- "Fleetbase" is the upstream project's name. Keeping it out of your product name and
  logo, as this build does, avoids any confusion with their brand.

## Notes

- **Routing**: `OSRM_HOST` defaults to the public OSRM demo server, which is rate-limited
  and not meant for production traffic. Run your own OSRM, or use the Valhalla/VROOM
  extensions, for real workloads.
- **Maps**: Fleet-Ops draws its map with OpenStreetMap tiles by default, loaded by each
  user's browser. To use Google Maps, add the key in the console's Admin panel under
  Services (Google Maps API Key). Heavy use of the public OSM tile servers is against their
  [tile usage policy](https://operations.osmfoundation.org/policies/tiles/).
- **Logs** rotate at 10 MB × 5 files per container. Laravel's own logs are kept for 14
  days inside the `application` container.
- **Redis** persists its data (append-only file), so queued jobs survive restarts.
