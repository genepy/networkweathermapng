# Testbed: Observium + Weathermap with simulated devices

A complete test deployment on one machine: Observium Community Edition polls
three simulated network devices, and NetworkWeathermapNG draws them on a demo
map from Observium's database and RRD files. It runs on arm64 (Raspberry Pi 4,
Oracle Cloud Ampere A1) and amd64.

| Container | Role |
|-----------|------|
| `weathermap` | NetworkWeathermapNG, built from this repository (`docker/Dockerfile`) |
| `observium` | Observium Community Edition, built from the official tarball |
| `db` | MariaDB for Observium |
| `rrdcached` | Caches RRD updates in memory and writes them in batches |
| `core-router`, `access-switch-1`, `access-switch-2` | Simulated SNMP devices ([snmpsim](https://github.com/lextudio/snmpsim)) |

## Start it

On a Linux machine with Docker and the Compose plugin
(`curl -fsSL https://get.docker.com | sh`):

```bash
./deploy/testbed/deploy.sh
```

The first run builds the images (Observium is downloaded from observium.org;
on a Raspberry Pi 4 the Weathermap image takes a while to compile), creates
passwords in `~/.config/weathermap-testbed/env`, starts everything and adds
to Weathermap the Observium data source and a map named **Testbed**. Running
it again updates the containers and keeps all data.

| URL | Login |
|-----|-------|
| `http://<host>:8080` Weathermap | `admin`, `WEATHERMAP_ADMIN_PASSWORD` from the env file |
| `http://<host>:8668` Observium | `admin`, `OBSERVIUM_ADMIN_PASSWORD` from the env file |

Observium polls every 5 minutes, with two extra polls when it starts: the map
shows traffic about a minute after the start, and the hover graphs fill in as
the polls go by.

## Deploy from GitHub Actions

The **Deploy testbed** workflow (`.github/workflows/deploy-testbed.yml`) runs
`deploy.sh` on a Raspberry Pi through a self-hosted runner with the labels
`self-hosted`, `ARM64` and `rpi`. A push to `main` runs CI on GitHub's
runners first; the deployment then starts on the Pi with exactly the commit CI
tested, only if it passed. It can also be started by hand from the Actions tab.

Each deployment builds the new images while the running version keeps
running, backs up its data to `/opt/backups/networkweathermapng` (Observium's
database, RRD files, Weathermap's data; the last 10 are kept), then starts the
new version. If the backup fails, the running version is left untouched.

Once, on the Pi:

1. Install a runner for this repository (repository **Settings → Actions →
   Runners → New self-hosted runner**, Linux, ARM64) with the label `rpi`,
   under a user without sudo that is in the `docker` group, and install it as
   a service (`sudo ./svc.sh install <user> && sudo ./svc.sh start`). A runner
   registered for another repository of a personal account doesn't take this
   repository's jobs: install a second one, in its own folder, next to it.
2. Create the folders, owned by the runner's user:
   ```bash
   sudo install -d -o <user> /opt/apps/networkweathermapng /opt/backups/networkweathermapng
   ```
   The first deployment writes `/opt/apps/networkweathermapng/.env` with new
   passwords; edit it to change ports or settings.
3. Add the repository variable `TESTBED_ENABLED` = `true` (**Settings →
   Secrets and variables → Actions → Variables**). Until then the workflow
   does nothing, so no job waits for a runner that doesn't exist.

The workflow never runs for pull requests: code from a fork must not run on
your machine. If the repository is public, also set **Settings → Actions →
General → Fork pull request workflows** to require approval.

## Sparing the SD card

| Where | What |
|-------|------|
| RRD files | `rrdcached` keeps updates in memory and writes each file every 30 minutes (`RRDCACHED_WRITE_DELAY`), instead of every 5 minutes. Weathermap and Observium read through it, so graphs stay current. On `docker compose down` it writes everything first |
| MariaDB | No binary log, redo log flushed about once per second, no doublewrite buffer (`mariadb/low-writes.cnf`) |
| PHP | Zend OPcache in both containers: compiled scripts stay in memory. Observium's poller uses a file cache in `/tmp`, which is in RAM |
| Logs, sessions, caches | In RAM (tmpfs): Observium and Weathermap logs, `/tmp`, rendered maps. No web access logs. Container logs are capped at 4 MB each |
| Observium | Poller output discarded, only errors are logged |

A power cut can lose up to the last 30 minutes of graph data and the last
second of database changes: acceptable for a testbed. Set
`RRDCACHED_JOURNAL=on` in the env file to lose nothing, at the cost of a small
write per update.

On the Raspberry Pi itself, these help too:

- Keep the system journal in RAM: `Storage=volatile` in
  `/etc/systemd/journald.conf`, or install
  [log2ram](https://github.com/azlux/log2ram).
- Mount the root filesystem with `noatime` in `/etc/fstab`.
- Use zram instead of a swap file (`sudo apt install zram-tools`).
- Move Docker's data (`/var/lib/docker`) to an SSD as soon as you have one.

## Settings

Everything is in the env file (`/opt/apps/networkweathermapng/.env` when
deployed by the workflow, `~/.config/weathermap-testbed/env` by hand, or the
path in `TESTBED_ENV_FILE`); run `deploy.sh` again after a change. By hand,
set `TESTBED_BACKUP_DIR` (an absolute path) to back up before each run.

| Variable | Default | |
|----------|---------|---|
| `WEATHERMAP_PORT`, `OBSERVIUM_PORT` | `8080`, `8668` | Ports published on the host; change them if another application uses them |
| `BIND_ADDRESS` | `0.0.0.0` | Address the ports listen on; `127.0.0.1` behind a reverse proxy on the same machine |
| `TZ` | `UTC` | Time zone |
| `OBSERVIUM_DEVICES` | the three simulated devices | Devices Observium adds at start, `host` or `host:community`, separated by spaces |
| `OBSERVIUM_BASE_URL` | detected | Observium's public URL, if it is behind a proxy |
| `RRDCACHED_WRITE_DELAY` | `1800` | Seconds an RRD update may stay in memory |
| `RRDCACHED_JOURNAL` | `off` | `on` to journal updates on disk |

The simulated devices and their average traffic are described in
`snmpsim/devices.json`: add a device there, add a service for it in
`docker-compose.yml` and its name to `OBSERVIUM_DEVICES`.

To monitor real equipment, add it to `OBSERVIUM_DEVICES` or in Observium's
web interface. A cloud instance only reaches your network's devices through a
VPN (WireGuard, Tailscale).

## Security

By default the ports are published on every interface of the host. On a cloud instance
with a public address, don't open them to the world: allow only your own
address in the provider's firewall (Oracle: the subnet's security list), or
reach them through an SSH tunnel or a VPN.

## Stop, reset

```bash
cd deploy/testbed
docker compose --env-file ~/.config/weathermap-testbed/env down      # stop, keep the data
docker compose --env-file ~/.config/weathermap-testbed/env down -v   # stop and delete all data
```

To build Observium without Internet access, put
`observium-community-latest.tar.gz` in `deploy/testbed/observium/`.
