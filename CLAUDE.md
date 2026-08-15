# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

mailcow: dockerized — the orchestration layer for a full mail server stack (Postfix, Dovecot, Rspamd, SOGo, MariaDB, Redis, nginx, ACME, ClamAV) plus the **mailcow UI**, a PHP admin/user web application in [data/web/](data/web/).

The repo contains almost no compiled code. It is: a Compose file pinning ~18 images, shell scripts that generate config and perform updates, the Dockerfiles + entrypoints for mailcow's own images, and the PHP application. The mail daemons themselves are upstream software configured via [data/conf/](data/conf/).

The scripts are bash and assume a Linux host with Docker. This checkout lives on Windows; the stack cannot run here. Code edits to `data/web` and `data/conf` are still fully valid — they're bind-mounted into the containers at runtime, not built.

## Configuration model

`.env` is a **git symlink to `mailcow.conf`** (mode 120000). On Windows it materializes as a plain text file containing the literal string `mailcow.conf` — do not "fix" it by writing config into it, and do not commit changes to it. `mailcow.conf` itself is gitignored and is the single source of every `${VAR}` in [docker-compose.yml](docker-compose.yml).

Two files are generated and gitignored — never edit them by hand:
- `mailcow.conf` — written by [generate_config.sh](generate_config.sh)
- `data/web/inc/app_info.inc.php` — version/commit/branch stamped into the UI footer, written by both `generate_config.sh` and `update.sh`

`data/conf/nginx/*.conf` are also generated: [data/Dockerfiles/nginx/bootstrap.py](data/Dockerfiles/nginx/bootstrap.py) renders the Jinja2 templates in [data/conf/nginx/templates/](data/conf/nginx/templates/) at container start. Edit the `.j2` templates, not the output.

## Commands

```bash
./generate_config.sh          # first-time setup; writes mailcow.conf (requires .env symlink present)
docker compose up -d          # start the stack
docker compose pull           # pull pinned images

./update.sh                   # the supported update path: git merge + image pull + restart
./update.sh -c                # check only (exit 0 = update available, 3 = none)
./update.sh --prefetch        # pull new images without applying
./update.sh -d                # DEV MODE — skips re-fetching _modules and update.sh from origin.
                              #   Use this whenever testing local changes to the scripts.
./update.sh --gc              # garbage-collect old image tags

./helper-scripts/backup_and_restore.sh backup all      # or: vmail|crypt|redis|rspamd|postfix|mysql
./helper-scripts/backup_and_restore.sh restore
./helper-scripts/mailcow-reset-admin.sh                # reset admin to admin/moohoo
```

Building a mailcow image locally (CI does this via [.github/workflows/image_builds.yml](.github/workflows/image_builds.yml)):

```bash
cp helper-scripts/docker-compose.override.yml.d/BUILD_FLAGS/docker-compose.override.yml .
docker compose build php-fpm-mailcow      # or dovecot-mailcow, rspamd-mailcow, ...
```

### Tests

There is no unit test suite. The only automated tests are integration shell scripts:

```bash
./helper-scripts/dev_tests/test_backup_and_restore.sh   # builds the backup image, round-trips .tar.zst/.tar.gz
./helper-scripts/dev_tests/view_autodiscover.sh
```

Per [CONTRIBUTING.md](CONTRIBUTING.md), PRs are expected to carry a manual test log, screenshot, or GIF instead.

### Translations

`lang.en-gb.json` is the master. To sync a target language:

```bash
php helper-scripts/add-new-lang-keys.php de-de    # adds + sorts missing keys, prints what needs translating
```

Never hand-add keys to non-English files; always propagate from `en-gb` with this script.

## Architecture

### Container topology

All services share the `mailcow-network` bridge and address each other by network alias (`mysql`, `redis`, `dockerapi`, `rspamd`, …), so hostnames are stable regardless of `COMPOSE_PROJECT_NAME`. Two exceptions: `netfilter-mailcow` runs `network_mode: host` (it manipulates the host's nftables/iptables), and `unbound` sits at a fixed `.254` and is the DNS resolver for nearly every other container.

Cron is **Ofelia**, driven by `ofelia.job-exec.*` labels on the service definitions in `docker-compose.yml` — not by crontabs inside images. If you're adding a scheduled task, add a label there.

`MASTER=y/n` gates almost every scheduled job and DB write; slave/replica instances skip them.

### The UI request lifecycle

[data/web/](data/web/) is bind-mounted read-write into `php-fpm-mailcow` and read-only into `nginx-mailcow`. There is no build step — PHP edits are live.

Every page follows the same three-part shape:

1. `require_once .../inc/prerequisites.inc.php` — the bootstrap. It connects Redis and PDO (unix socket), hard-fails the request if Redis/MariaDB/dockerapi are unreachable, wires up OAuth2/WebAuthn/TOTP, resolves the locale and loads `lang/lang.<locale>.json` merged over `en-gb`, includes **every** `functions.*.inc.php`, and calls `init_db_schema()`.
2. The page sets `$template` and `$template_data`, and pushes any page-specific JS via `$js_minifier->add(...)`.
3. `require_once .../inc/footer.inc.php` — injects globals (CSRF token, version info, lang blobs), renders the Twig template, closes PDO.

Templates live in [data/web/templates/](data/web/templates/) (Twig, cached to `templates/cache/`, gitignored). Assets in `js/build/` and `css/build/` are **numerically prefixed** (`000-jquery…`, `013-mailcow.js`) because they are concatenated in `scandir` order and minified at runtime into a content-hashed file served from `/cache/`. The prefix is the load order — respect it when adding a library.

### Business logic convention

Domain logic lives in `data/web/inc/functions.<area>.inc.php`, each exposing **one dispatcher function** with a uniform signature rather than many small functions:

```php
mailbox($action, $type, $data, $extra)     // functions.mailbox.inc.php  (~300KB, the core)
acl($action, $scope, $data, $extra)        // functions.acl.inc.php
docker($action, $service_name, $attr1, …)  // functions.docker.inc.php
```

Results are not returned as messages. Functions return `true`/`false` and **push** structured feedback onto `$_SESSION['return'][]` as `['type' => 'success'|'danger'|'warning', 'log' => [...], 'msg' => 'lang_key'|['lang_key', $param]]`. The `msg` key is looked up in the language JSON at render time by `alertbox_log_parser()`. When adding a code path, push a return entry with a lang key and add that key to `lang.en-gb.json`.

### Database schema

There are **no migration files**. The entire schema (tables, views, triggers) is declared in [data/web/inc/init_db.inc.php](data/web/inc/init_db.inc.php) as PHP arrays, and `init_db_schema()` diffs the live database against them on every request. It short-circuits when `versions.db_schema` matches the hardcoded `$db_version` string near the top of the file.

**Any schema change requires bumping `$db_version`** (format `DDMMYYYY_HHMM`), or it will never be applied. Schema changes are also skipped entirely on non-`MASTER` instances.

### API

[data/web/json_api.php](data/web/json_api.php) is a single ~80KB file implementing the whole REST API. Routing is `?query=<action>/<category>/<object>/<extra>` with actions `add|edit|delete|get|search`, dispatched through nested `switch` statements; nginx rewrites `/api/v1/...` into that query string. The public contract is [data/web/api/openapi.yaml](data/web/api/openapi.yaml), served as Swagger UI at `/api` — update it alongside any endpoint change.

Authentication happens earlier, in [data/web/inc/sessions.inc.php](data/web/inc/sessions.inc.php): an `X-API-Key` header is looked up in the `api` table, IP-ACL checked, and on success the session is synthesized as `mailcow_cc_role = admin` with `mailcow_cc_api_access` set to `ro` or `rw`. Browser sessions instead carry a CSRF token checked on every POST (with a documented exemption for the DataTables `search/domain|mailbox` endpoints).

Roles are `admin`, `domainadmin`, `user` in `$_SESSION['mailcow_cc_role']`; fine-grained per-user permissions are loaded into the session by `acl('to_session')`.

Login dispatch lives in [data/web/inc/functions.auth.inc.php](data/web/inc/functions.auth.inc.php): `check_login()` fans out to `admin_login`, `domainadmin_login`, `user_login`, `apppass_login`, `keycloak_mbox_login_rest`, and `ldap_mbox_login`.

### Cross-container integration points

These are the non-obvious couplings worth knowing before changing anything:

- **UI → Docker.** PHP never touches the Docker socket. `docker()` makes HTTPS calls to `https://dockerapi:443`, a FastAPI service ([data/Dockerfiles/dockerapi/main.py](data/Dockerfiles/dockerapi/main.py)) that holds the socket mount. TLS verification is intentionally disabled — it reuses the mail certs, so hostnames won't match.
- **UI → fail2ban.** There is no fail2ban. PHP publishes ban-worthy events to the Redis pub/sub channel `F2B_CHANNEL`; `netfilter-mailcow` (Python, host network) subscribes and writes nftables/iptables rules.
- **Rspamd → MariaDB.** Rspamd pulls dynamic maps (aliases, BCC, footers, forwarding hosts, per-user settings) over HTTP from the PHP scripts in [data/conf/rspamd/dynmaps/](data/conf/rspamd/dynmaps/), served by the same nginx/php-fpm pair. Similarly [data/conf/rspamd/meta_exporter/](data/conf/rspamd/meta_exporter/) implements quarantine piping and Pushover notifications.
- **Dovecot/nginx → mailcowauth.** `docker-compose.yml` bind-mounts individual UI files (`functions.inc.php`, `functions.auth.inc.php`, `sessions.inc.php`, …) into `/mailcowauth/` in the dovecot-auth and nginx containers. **Editing those files changes IMAP/SMTP authentication**, not just the web UI.
- **SOGo → init_db.** `data/web/inc/init_db.inc.php` is mounted into the SOGo container as `/init_db.inc.php`.

### The update mechanism

[update.sh](update.sh) re-fetches [_modules/scripts/](_modules/scripts/) (shared bash helpers: `core.sh`, `ipv6_controller.sh`, `new_options.sh`, `migrate_options.sh`) from `origin/<branch>` before doing anything, and aborts asking for a re-run if they changed. `new_options.sh` and `migrate_options.sh` are how new `mailcow.conf` keys get added to existing installations — a new environment variable in `docker-compose.yml` generally needs a matching entry there.

Branches: `master` (stable), `staging` (**all PRs target this**), `nightly` (unstable), `legacy` (security-only). `update.sh --stable|--nightly|--legacy` switches between them and saves a diff of local modifications to `update_diffs/` first.

## Conventions

- Two-space indent, LF, UTF-8, trailing whitespace trimmed ([.editorconfig](.editorconfig)).
- PR branches target **`staging`**, never `master`, and are named `feat/…` or `fix/…`.
- **Changing anything under `data/Dockerfiles/` requires bumping the corresponding image tag in `docker-compose.yml`** — a version bump for real changes (`sogo:5.12.4` → `:5.12.5`), a letter suffix for patches (`:5.12.4a`).
- PHP dependencies are Composer-managed from [data/web/inc/lib/composer.json](data/web/inc/lib/composer.json), but `vendor/` is committed — a dependency change means committing the updated tree.
- Security issues go to info@servercow.de before any public issue or PR ([SECURITY.md](SECURITY.md)).
