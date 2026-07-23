# DiskMap

See where your storage is going — treemap and top consumers, instance-wide.

DiskMap gives Nextcloud administrators and users a visual view of storage usage
across team folders, personal files and groups. It never scans the filesystem:
Nextcloud already tracks aggregated folder sizes in `oc_filecache`, updated on
every file operation, and DiskMap only reads that.

## Status

Early development (Phase 1 of the implementation plan): admin team-folder
overview (used/quota, files/trash/versions breakdown, linked groups) and
top-N largest files. See `CHANGELOG.md` for what has actually shipped.

## Install

Clone (or copy) this directory into your Nextcloud `apps/` (or
`custom_apps/`) folder, named `diskmap`, then enable it:

```bash
occ app:enable diskmap
```

The compiled JavaScript (`js/`) is committed, so a plain install doesn't need
`npm install`/`npm run build` — those are only needed when editing `src/`.

## Development

```bash
npm install
npm run watch    # rebuild on change
npm run build    # production build
```

Backend tests (PHPUnit) run inside the Nextcloud container, where the `OCP`
namespace is available via Nextcloud's own autoloaders:

```bash
docker exec nextcloud-app php /var/www/html/custom_apps/diskmap/vendor/bin/phpunit \
  -c /var/www/html/custom_apps/diskmap/phpunit.xml
```

### Translations

Add a new UI string: put the English key in `l10n/en.json` (and its
translation in every other `l10n/<lang>.json`), then run:

```bash
python3 build/l10n.py           # regenerates l10n/<lang>.js
python3 build/l10n.py --check   # CI gate, no write
```

## License

AGPL-3.0-or-later. See `LICENSES/AGPL-3.0-or-later.txt`.
