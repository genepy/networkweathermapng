# Instructions for agents

This file is the shared brief for every agent working on NetworkWeathermapNG
(Claude, Codex, Copilot or any other). Several agents work on the repository
in parallel and the repository is the only thing they all see, so anything
another agent needs to know goes in this file or in
[docs/ROADMAP.md](docs/ROADMAP.md), never only in a chat.

Read this file and `docs/ROADMAP.md` before starting any work.

## Project rules

The project must stay international.

- All code is written in English: identifiers, comments, commit messages,
  pull request titles and descriptions.
- The user interface defaults to English.
- Every UI string goes through the translation catalogues in
  `src/app/Lang/` (see [TRANSLATING.md](TRANSLATING.md)), so anyone can add a
  language without touching code. When you add or change a string, add its
  French translation in `src/app/Lang/fr/` in the same change. Strings used
  by browser scripts live in the `*-js.php` files.
  Check with `php src/bin/i18n-check.php fr`.
- One task per branch and per pull request. Do not mix unrelated changes.
- Every change reaches `main` through a pull request that is reviewed, then
  merged. Never push to `main` directly.
- Only one task in progress at a time, per agent, to save quota. Paused
  items stay paused until their turn comes.
- Credit third-party code, icons and ideas, and respect their licenses:
  record them in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)
  (`tests/App/LicenseNoticesTest.php` checks it).

## Coordination between agents

[docs/ROADMAP.md](docs/ROADMAP.md) is the backlog and the status board. It
lists every item with its state and its branch.

**Before starting an item**

1. Fetch and read `docs/ROADMAP.md` on `main`, the remote branches
   (`git branch -r`) and the open pull requests. An item that has a branch
   or a pull request is claimed by whoever owns that branch: do not start
   it, pick another one or ask the maintainer.
2. Claim the item: create its branch from the latest `main`, change the
   item's line in `docs/ROADMAP.md` to *In progress* with the branch name,
   commit, push, and open a **draft** pull request right away. The pushed
   branch and the draft PR are the claim that other agents see.

**Branch names** say who owns the work:
`claude/<slug>` for Claude, `codex/<slug>` for Codex, `<agent>/<slug>` for
any other agent. Existing Claude branches are named
`claude/project-thread-<id>`; the roadmap maps each one to its item.

**While working**, keep the item's line in the roadmap up to date on your
branch (what is done, what is left). If you stop before finishing, push
everything (a `WIP:` commit is fine), set the item to *Paused* with a short
note of what is left, so anyone can resume it from the branch alone.

**When finishing**, set the item to *Done* with the PR number in the same
pull request, and mark the PR ready for review. After the merge, delete the
branch.

**Resuming someone else's paused branch** is allowed only when the roadmap
says it is free to take (or the maintainer asks): rebase it on `main`, keep
its branch name or move it to your own prefix and update the roadmap line.

If two changes to `docs/ROADMAP.md` conflict, keep both sides: each line
belongs to one item.

## Architecture

PHP application, no framework, Composer autoload (PSR-4).

| Path | Content |
|------|---------|
| `src/public/` | Web root: `index.php` (front controller), `assets/` (CSS, JS of the editor and the viewer, icons), `objects/`, `backgrounds/`, `fonts/` |
| `src/app/Core/` | Router, auth, CSRF, i18n (`helpers.php`), config |
| `src/app/Controllers/` | HTTP controllers: maps, map management of the Editor tab (`MapManagementController`), admin, API, legacy editor (`EditorController`), SVG editor (`SvgEditorController`), migration |
| `src/app/Services/` | Business logic: maps, groups, data sources (Zabbix, Observium), editor, images, migration from php-weathermap |
| `src/app/Views/` | PHP templates |
| `src/app/Lang/<code>/` | Translation catalogues (English is the key, no `en` folder) |
| `src/lib/WeatherMap/` | Map engine, derived from php-weathermap: config parser, nodes, links, zones, PNG and SVG renderers |
| `src/lib/Zabbix/` | Zabbix API client |
| `src/bin/` | CLI tools (`i18n-check.php`...) |
| `docker/` | Production image (nginx + PHP-FPM + supervisor) |
| `deploy/testbed/` | Testbed: Observium, MariaDB, rrdcached, simulated SNMP devices and Weathermap |
| `tests/App`, `tests/Engine` | PHPUnit tests; `tests/fixtures/` holds maps and reference renders |

The README documents the features, the map config format, the editor and
the environment variables (for example `OBSERVIUM_PUBLIC_URL`).

## Development and tests

Requires PHP 8.5.12 or later (8.5 is the only version tested in CI) with `gd`, `pdo_sqlite`,
`mbstring`, `curl`.

```bash
composer install
composer start                       # http://localhost:8080
vendor/bin/phpunit                   # engine render tests + application tests
vendor/bin/phpstan analyse           # static analysis
php src/bin/i18n-check.php fr        # missing or unused translations
```

Render tests compare each map of `tests/fixtures/maps/` with
`tests/fixtures/reference/`. After an intended visual change, regenerate
with `UPDATE_REFERENCE=1 vendor/bin/phpunit` and review the images before
committing them.

Before opening a pull request: lint, phpstan and phpunit pass, French
translations are complete for the strings you touched, and UI changes were
checked in a browser (light and dark themes) when you can run one.

CI (`.github/workflows/ci.yml`) runs on every pull request: PHP lint,
phpstan, phpunit and an informative translation check.

## Deployment

- `.github/workflows/docker-publish.yml` builds and publishes the Docker
  image.
- `.github/workflows/deploy-testbed.yml` deploys `deploy/testbed` on a
  Raspberry Pi through a self-hosted runner (labels `self-hosted`, `ARM64`,
  `rpi`) after CI passes on a push to `main`, or by hand. It only runs when
  the repository variable `TESTBED_ENABLED` is `true`, and never for pull
  requests. See [docs/raspberry-pi.md](docs/raspberry-pi.md) and
  [deploy/testbed/README.md](deploy/testbed/README.md).
- So a merge to `main` updates the testbed: that is where the maintainer
  checks merged work against Observium and simulated devices.
- `cleanup-workflow-runs.yml` and `remove-old-artifacts.yml` clean up old
  Actions runs and artifacts.

## Tooling notes

- Claude sessions use a graphify knowledge graph when `graphify-out/` exists
  (see `CLAUDE.md`). Other agents can ignore it. A large diff of
  `graphify-out/graph.json` in a pull request is expected.
