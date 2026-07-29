<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is listed in `.nextcloudignore`, so
`krankerl package` leaves it out of the App Store tarball. It exists for
working on DiskMap, not for running it.

| File | What it is |
|---|---|
| `l10n.py` | Regenerates `l10n/*.js` from the `.json` sources and reports missing or orphaned strings. CI runs `--check`. |
| `docker-compose.pgsql.yml` | Disposable PostgreSQL Nextcloud instance, port 8081. |
| `docker-compose.mysql.yml` | Disposable MariaDB Nextcloud instance, port 8082. |
| `seed-fixture.php` | Writes a deterministic file tree into one account's home. |

`l10n.py` is the routine one and its commands live in the main README's
*Translations build* section, so they stay in one place. The rest of this file
covers the cross-engine setup, which has nowhere else to live.

## Cross-engine checks

DiskMap supports MySQL/MariaDB and PostgreSQL. The composition queries are the
only place that distinction reaches the database, and
`tests/Unit/FilecacheDialectTest.php` pins the SQL generated for each engine
without needing a database at all — run that first after touching any of it.

For a real end-to-end comparison there is a disposable instance per engine,
each with its own project name, containers and volumes, so neither can disturb
a development instance you actually use:

```bash
docker compose -p diskmap-pg -f build/docker-compose.pgsql.yml up -d
docker compose -p diskmap-my -f build/docker-compose.mysql.yml up -d
```

Point both at the same Nextcloud version — identical versions are what make the
two outputs diffable — then give both the identical fixture.
`seed-fixture.php` writes a fixed tree (same names, sizes and mtimes every
time, sparse files so it costs no real disk) chosen to cover what the
aggregation queries are sensitive to: non-ASCII folder names, the `.pst`/`.dwg`
extensions the composition query rewrites, four levels of nesting, and a folder
of many small files.

```bash
for c in diskmap-pg-app diskmap-my-app; do
    docker exec -u www-data $c php occ app:enable diskmap
    OC_PASS=fixture-pw docker exec -e OC_PASS -u www-data $c \
        php occ user:add --password-from-env fixture
    docker exec $c php /var/www/html/custom_apps/diskmap/build/seed-fixture.php fixture
    docker exec -u www-data $c php occ files:scan fixture
done
```

Then dump the app's output on each and diff the two.

Two things to know before you trust a diff. **Leave storage ids and fileids out
of what you compare** — they come from auto-increment and sequences, so they
differ between instances by construction and say nothing about the engine. And
expect one legitimate difference: the account home's own `mtime` is the
timestamp of its scan, so it varies unless both scans landed in the same
second.

Tear either instance down with `down -v`. The `-v` matters: without it the
volumes survive and the next `up` resumes the old instance rather than building
a clean one.

```bash
docker compose -p diskmap-pg -f build/docker-compose.pgsql.yml down -v
docker compose -p diskmap-my -f build/docker-compose.mysql.yml down -v
```

Note that Nextcloud refuses to start on a *lower* version than its data already
has, so lowering the `image:` in a compose file against an existing instance
means tearing it down first.
