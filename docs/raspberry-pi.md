# Raspberry Pi: hosting, deployment and operations

The testbed (`deploy/testbed`: Weathermap, Observium, MariaDB, rrdcached and
three simulated SNMP devices) runs on a Raspberry Pi at home, next to other
applications, and deploys itself from GitHub. This document describes what is
in place, how to use it and what not to break when changing the code. The
testbed itself is described in [deploy/testbed/README.md](../deploy/testbed/README.md).
The Pi as a whole (every hosted application, Caddy, firewall, Tailscale,
runners, reinstallation) is documented in
[genepy/infra](https://github.com/genepy/infra#network-weathermap-networkweathermapng),
whose `setup-pi.sh` prepares it. That script lives there: change it there, by
pull request, never from this repository.

## Access

| From | Weathermap | Observium | Certificate |
|---|---|---|---|
| Home network and Tailscale | <https://weathermap.genepyhome.ovh/> | <https://observium.genepyhome.ovh/> | Let's Encrypt |
| Home network, fallback | <https://raspberry-tools.local:8444/> | <https://raspberry-tools.local:8445/> | Caddy's local authority (see genepy/infra) or a warning to accept |

Nothing is exposed to the Internet. Caddy terminates HTTPS and forwards each
request over HTTP to `127.0.0.1` (Weathermap on port 8002, Observium on 8003),
with the original `Host`, `X-Forwarded-For` and `X-Forwarded-Proto: https`,
through a single proxy. Both applications log in as `admin`; the first
passwords are in the Pi's `.env` (see [Passwords](#passwords)).

Observium's links all point to `https://observium.genepyhome.ovh/`
(`OBSERVIUM_BASE_URL`), including when it is opened through the fallback
address.

## Deployment

```
merge on main ──> "CI" (GitHub, ubuntu-24.04) ──green──> "Deploy testbed" (the Pi's runner)
```

- **Automatic**: every push to `main` whose CI passes is deployed, exactly the
  commit CI tested. Never on a pull request.
- **By hand**: Actions tab > "Deploy testbed" > "Run workflow" (branch
  `main`). This is also how a change to the Pi's `.env` is applied.
- **What a deployment does** (`.github/workflows/deploy-testbed.yml`, then
  `deploy/testbed/deploy.sh`): builds the new images while the running version
  keeps running, backs up its data to `/opt/backups/networkweathermapng` (the
  last 10 are kept), starts the new version, waits until Weathermap and
  Observium answer, adds or updates the Observium data source and the
  "Testbed" map in Weathermap, then prints the addresses. If the backup fails,
  the running version is left untouched and the workflow fails. The first
  build compiles PHP extensions and can take up to 1 h 30 on the Pi (the
  workflow's limit is 90 minutes); later builds use Docker's cache.
- Until the repository variable `TESTBED_ENABLED` is `true` (Settings >
  Secrets and variables > Actions > Variables), the workflow does nothing.

### From a Claude session in the cloud

A session cannot connect to the Pi, but it can start a deployment through the
API and read the results:

```bash
gh api -X POST repos/genepy/networkweathermapng/actions/workflows/deploy-testbed.yml/dispatches -f ref=main
gh api "repos/genepy/networkweathermapng/actions/runs?per_page=5" --jq '.workflow_runs[] | "\(.name) \(.event) \(.status) \(.conclusion)"'
```

Everything that happens *on* the Pi (logs, `.env`, passwords, restoring a
backup) is for the user to do: give them the commands, ready to copy.

## Settings: the Pi's `.env`

`/opt/apps/networkweathermapng/.env` (readable by root and the runner's user
only) holds the testbed's settings on the Pi; it is not in the repository.
`sudo ./setup-pi.sh app networkweathermapng` writes it, and `setup-pi.sh lan`
rewrites the values the Pi imposes; `deploy.sh` reads it on every deployment.
All variables are listed in the testbed's
[Settings](../deploy/testbed/README.md#settings).

What the Pi imposes:

| Variable | Value on the Pi | Why |
|---|---|---|
| `BIND_ADDRESS` | `127.0.0.1` | Caddy and Tailscale are in front. Docker bypasses `ufw` for published ports: on `0.0.0.0`, both applications would be open over plain HTTP to the whole network |
| `WEATHERMAP_PORT`, `OBSERVIUM_PORT` | `8002`, `8003` | Caddy forwards to them; 8080 is Caddy's, 8000 and 8001 belong to the other applications |
| `OBSERVIUM_BASE_URL` | `https://observium.genepyhome.ovh/` | Observium's links and redirects use this address |

Set once, never replaced:

| Variable | Value | Note |
|---|---|---|
| `WEATHERMAP_ADMIN_PASSWORD`, `OBSERVIUM_ADMIN_PASSWORD` | generated | Only used to create the `admin` accounts on the first start |
| `DB_ROOT_PASSWORD`, `OBSERVIUM_DB_PASSWORD`, `WEATHERMAP_DB_PASSWORD` | generated | MariaDB creates its users on the first start only: changing them afterwards breaks the database connection |
| `TZ` | `Europe/Paris` | |

Optional, by hand:

| Variable | Use |
|---|---|
| `WEATHERMAP_PUBLIC_URL`, `OBSERVIUM_PUBLIC_URL` | Addresses printed at the end of a deployment, e.g. `https://weathermap.genepyhome.ovh/`. Without them, `deploy.sh` prints `OBSERVIUM_BASE_URL` for Observium and `http://127.0.0.1:<port>` otherwise |
| `OBSERVIUM_DEVICES` | Devices Observium adds at start: the three simulated ones, plus real equipment as `host` or `host:community` |
| `RRDCACHED_WRITE_DELAY`, `RRDCACHED_JOURNAL` | See the testbed's README (SD card wear) |

After a change, redeploy (Actions > "Deploy testbed" > Run workflow).

## What not to break

- **The workflows**: the deployment is triggered by the workflow named
  **"CI"** (`.github/workflows/ci.yml`; renaming it stops deployments). "Deploy
  testbed" runs on `[self-hosted, ARM64, rpi]`, after CI passed on a push to
  `main` or by hand, **never on `pull_request`** (a pull request's code must
  not run on the Pi), and only when `vars.TESTBED_ENABLED == 'true'`.
- **Folders** `/opt/apps/networkweathermapng` (the `.env`) and
  `/opt/backups/networkweathermapng` (backups), used by the workflow and
  `setup-pi.sh`.
- **The Compose project name** `weathermap-testbed` and the volumes
  `weathermap-testbed_db`, `weathermap-testbed_rrd` and
  `weathermap-testbed_weathermap`: renaming them starts from empty volumes and
  breaks the backups and the restore commands below.
- **Ports** published on `${BIND_ADDRESS}` with `WEATHERMAP_PORT` and
  `OBSERVIUM_PORT`: never a hard-coded `0.0.0.0`, 8080 or 8668 in a port
  mapping.
- **Behind the HTTPS proxy**: links and redirects sent to the browser stay
  relative (or use the request's host), session cookies are `Secure` when
  `X-Forwarded-Proto` is `https` (`App\Core\Application::isHttps`), and nginx
  keeps `absolute_redirect off` (otherwise `/objects` would redirect to
  `http://…/objects/`).
- **Readiness**: `deploy.sh` waits for `GET /health` on Weathermap and
  `GET /` on Observium, from inside the containers. Changing either fails
  every deployment unless `deploy.sh` follows.
- **Data only in volumes**: Observium's database (`db`), RRD files (`rrd`),
  Weathermap's data (`weathermap`, mounted on `/app/data`: SQLite database,
  `app.key`, uploaded icons and backgrounds). Anything written elsewhere in a
  container is lost on the next deployment, and is not backed up.
- **Builds on the host's network**: every `build` in
  `deploy/testbed/docker-compose.yml` has `network: host`. Without it, the
  build containers cannot resolve names on the Pi and Composer cannot
  download its packages. `docker compose build` refuses such builds, so
  `deploy.sh` builds with `docker buildx bake --allow=network.host`.
- **ARM64**: the Pi is `aarch64`. Base images and packages must exist for
  `linux/arm64`; PHP extensions are compiled during the build.
- **Resources**: the Pi has 8 GB shared with the other applications, and an
  SD card: keep the low-write settings described in the testbed's README.

## Operations on the Pi

Commands to run on the Pi (over SSH or at the keyboard).

```bash
docker ps --filter name=weathermap-testbed                      # containers
docker logs --tail 100 -f weathermap-testbed-weathermap-1       # Weathermap (nginx and PHP)
docker logs --tail 100 -f weathermap-testbed-observium-1        # Observium (scheduler, Apache errors)
docker exec weathermap-testbed-weathermap-1 sh -c 'tail -n 100 /app/logs/*.log'
ls -lh /opt/backups/networkweathermapng/                        # backups made before each deployment
sudo ./setup-pi.sh status                                       # overview of the Pi (genepy/infra)
```

### Passwords

```bash
sudo grep ADMIN_PASSWORD /opt/apps/networkweathermapng/.env
```

These are the passwords the `admin` accounts were created with. Once changed
in the applications (Weathermap: Account > Change password; Observium: My
Profile), the `.env` no longer applies to them.

### Restoring a backup

Each deployment leaves two files with the same timestamp in
`/opt/backups/networkweathermapng`:

- `observium-db-<timestamp>.sql.gz`: Observium's database;
- `volumes-<timestamp>.tar.gz`: the RRD files (`rrd/`) and Weathermap's data
  (`weathermap/`).

Restoring replaces the current data with the backup's. Restore both files of
the same timestamp, so graphs and devices match.

1. Choose the backup:
   ```bash
   ls /opt/backups/networkweathermapng/
   B=/opt/backups/networkweathermapng
   S=20261019-214500    # the timestamp to restore
   ```
2. Stop the applications and rrdcached (the database keeps running):
   ```bash
   docker stop weathermap-testbed-weathermap-1 weathermap-testbed-observium-1 weathermap-testbed-rrdcached-1
   ```
3. Restore Observium's database:
   ```bash
   sudo gunzip -c "$B/observium-db-$S.sql.gz" \
       | docker exec -i weathermap-testbed-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" observium'
   ```
4. Restore the RRD files and Weathermap's data:
   ```bash
   docker run --rm -v weathermap-testbed_rrd:/restore/rrd -v weathermap-testbed_weathermap:/restore/weathermap \
       -v "$B":/backup:ro alpine:3.20 \
       sh -c "find /restore/rrd /restore/weathermap -mindepth 1 -delete && tar xzf /backup/volumes-$S.tar.gz -C /restore"
   ```
5. Start them again:
   ```bash
   docker start weathermap-testbed-rrdcached-1 weathermap-testbed-observium-1 weathermap-testbed-weathermap-1
   ```

The next deployment backs up the restored data like any other.

### Going back to previous code

`git revert` the faulty commit on `main`; the automatic deployment does the
rest. Data is not rolled back by a revert: restore the backup made before the
faulty deployment if needed.

### Starting from scratch

Deletes all of the testbed's data on the Pi (the `.env` and the backups stay):

```bash
docker compose -p weathermap-testbed down -v
```

Then redeploy: the databases and the `admin` accounts are created again with
the passwords of the `.env`.
