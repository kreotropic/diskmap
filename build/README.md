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
| `dump-readpaths.php` | Prints every read path over that tree, normalised so two instances can be diffed. |

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

Then dump each instance's output and diff the two. `dump-readpaths.php` walks
every folder of the fixture at every depth — sizes, counts, per-mimetype
composition, listing order and the shape of the map tree — and never prints
storage ids or file ids, which come from auto-increment and sequences and so
differ between instances by construction:

```bash
for c in diskmap-pg-app diskmap-my-app; do
    docker exec -u www-data $c \
        php /var/www/html/custom_apps/diskmap/build/dump-readpaths.php fixture > "$c.txt"
done
diff diskmap-pg-app.txt diskmap-my-app.txt
```

Expect exactly one legitimate difference: the account home's own `mtime` is the
timestamp of its `files:scan`, so it varies unless both scans landed in the
same second. It shows up as four lines. Everything the fixture itself owns
carries a fixed mtime and must match exactly — if anything else differs, that
is a real finding.

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

## Releasing to the App Store

Signing needs the app certificate. `~/.nextcloud/certificates/` already holds
`diskmap.key` and `diskmap.csr`; `diskmap.crt` arrives when the request at
[app-certificate-requests#1125](https://github.com/nextcloud/app-certificate-requests/pull/1125)
is merged, as a file committed into that repository. That directory is the path
`krankerl sign` looks in, which is why the key lives there rather than anywhere
tidier. **The key is not backed up anywhere** — it signs every future release,
and losing it means requesting a new certificate and disclosing the loss.

### 1. Pre-flight

`krankerl package` archives the **committed** tree, not the working copy —
neither uncommitted edits to tracked files nor untracked files reach the
tarball. That is a useful property (a release always corresponds to a commit)
with one sharp edge: forget to commit and it silently packages the previous
version. So commit first, then check `git status` is clean.

Bump `version` in `appinfo/info.xml` (and `package.json`, kept in step), and
give the new version its own `CHANGELOG.md` section — the App Store reads the
section matching the release version, so a heading that does not match ships an
empty changelog.

```bash
vendor/bin/phpunit -c phpunit.xml
vendor/bin/phpstan analyse
python3 build/l10n.py --check
reuse lint
npm ci && npm run build && git diff --exit-code -- js/
```

### 2. Package

```bash
krankerl package
tar tzf build/artifacts/diskmap.tar.gz | wc -l          # 36 at 0.1.0
tar xzf build/artifacts/diskmap.tar.gz -O diskmap/appinfo/info.xml | grep '<version>'
```

Check the version inside the tarball rather than trusting the working copy —
it is the one place the "packages HEAD" behaviour shows up as a wrong answer.

### 3. Sign the packaged content, not the working tree

**The trap worth knowing before you hit it:** running `integrity:sign-app`
against `apps/diskmap` hashes the whole development directory — `src/`,
`tests/`, `node_modules/`, `vendor/` — none of which ship. The signature would
then list files the installed app does not have, and `integrity:check-app`
fails on every one. Sign an extracted copy of the tarball, so the hashes cover
exactly the shipped file set.

```bash
docker cp build/artifacts/diskmap.tar.gz nextcloud-app:/tmp/
docker cp ~/.nextcloud/certificates/diskmap.key nextcloud-app:/tmp/
docker cp ~/.nextcloud/certificates/diskmap.crt nextcloud-app:/tmp/
docker exec nextcloud-app sh -c '
    cd /tmp && rm -rf signroot && mkdir signroot &&
    tar xzf diskmap.tar.gz -C signroot &&
    chown -R www-data:www-data signroot diskmap.key diskmap.crt'

docker exec -u www-data nextcloud-app php /var/www/html/occ integrity:sign-app \
    --privateKey=/tmp/diskmap.key \
    --certificate=/tmp/diskmap.crt \
    --path=/tmp/signroot/diskmap

docker exec nextcloud-app sh -c 'cd /tmp/signroot && tar czf /tmp/diskmap-signed.tar.gz diskmap'
docker cp nextcloud-app:/tmp/diskmap-signed.tar.gz build/artifacts/
docker exec nextcloud-app sh -c 'rm -rf /tmp/signroot /tmp/diskmap.key /tmp/diskmap.crt /tmp/diskmap.tar.gz'
```

The `chown` matters: `occ` has to run as `www-data` (it refuses to run as root),
and `docker cp` leaves the key owned by root and mode 600. The final `rm`
matters for the same reason the key lives outside every repository.

### 4. Verify

`appinfo/signature.json` is now inside `diskmap-signed.tar.gz`. Prove it before
publishing, by installing that tarball on a disposable instance (the
cross-engine compose files above are already disposable) and asking Nextcloud
itself:

```bash
docker exec -u www-data <container> php occ integrity:check-app diskmap
```

Empty output means the signature covers exactly what is installed. Anything
else lists the offending files and means step 3 signed the wrong tree.

### 5. Publish

Upload `diskmap-signed.tar.gz` at <https://apps.nextcloud.com> — the account
signs in with GitHub, and the first upload is what creates the app's store
page. Screenshots come from the `<screenshot>` URLs in `info.xml`, served from
this repository, so they must already be pushed.

### Automating it later

`krankerl publish <url>` does **not** upload anything: it registers a release
against `https://apps.nextcloud.com/api/v1/apps/releases`, and the store then
fetches the tarball from the URL you hand it. So automation means publishing
the tarball somewhere public first — normally a GitHub release asset — which is
the actual shape of the "GitHub → App Store" integration. `krankerl login
--appstore <token>` and `--github <token>` store both credentials in
krankerl's own config file **in plaintext**.

Deliberately not used for the first release: krankerl's last release is
0.14.0 from December 2022, and what its `sign --package` produces is
undocumented — it takes only the private key, so it is not the
`appinfo/signature.json` that step 3 builds. Worth revisiting once there is a
certificate to test against, at which point the honest comparison is a plain
`curl` to that API endpoint versus a four-year-old dependency.
