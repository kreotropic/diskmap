#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

"""
l10n helper for the diskmap app.

What it does:
  1. Extracts every translatable string from src/ (t()/n() calls).
  2. Reports strings used in code but missing from l10n/en.json (must be
     translated by hand) and strings in l10n/en.json no longer used in code.
  3. Regenerates every l10n/<lang>.js from its l10n/<lang>.json, in the
     OC.L10N.register(...) format the Nextcloud frontend loads.
  4. Validates that each JSON has the required { "translations": {...},
     "pluralForm": "..." } wrapper (the flat format silently 500s the server).

Usage:
  python3 build/l10n.py           # check + regenerate all <lang>.js
  python3 build/l10n.py --check   # check only, non-zero exit if issues (CI)

Run it from the app root. Adding a new UI string only needs: add the English
key to l10n/en.json (and the translation to each l10n/<lang>.json), then run
this script to rebuild the .js files.
"""
from __future__ import annotations

import collections
import glob
import json
import os
import re
import sys

APP = "diskmap"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
L10N = os.path.join(ROOT, "l10n")
DEFAULT_PLURAL = "nplurals=2; plural=(n != 1);"

# t(APP, '...') and n(APP, 'singular', 'plural', ...); APP is the literal
# 'diskmap' or the local const APP.
_STR = r"'((?:[^'\\]|\\.)*)'"
_APP = r"(?:'diskmap'|APP)"
_T = re.compile(r"[^\w.]t\(\s*" + _APP + r"\s*,\s*" + _STR)
_N = re.compile(r"[^\w.]n\(\s*" + _APP + r"\s*,\s*" + _STR + r"\s*,\s*" + _STR)


def extract_source_keys() -> set[str]:
    """Return every translatable key in the canonical Nextcloud form.

    Singular strings are keyed as-is. Plural strings use the special
    "_<singular>_::_<plural>_" key that @nextcloud/l10n's translatePlural()
    (n()) looks up — keying a plural by its singular alone silently falls
    back to the untranslated English text.
    """
    keys: set[str] = set()
    pattern = os.path.join(ROOT, "src", "**", "*.*")
    for path in glob.glob(pattern, recursive=True):
        if not path.endswith((".vue", ".js", ".ts")):
            continue
        text = open(path, encoding="utf-8").read()
        for m in _N.finditer(text):
            keys.add(f"_{m.group(1)}_::_{m.group(2)}_")
        for m in _T.finditer(text):
            keys.add(m.group(1))
    return keys


def load_json(path: str) -> collections.OrderedDict:
    return json.load(open(path, encoding="utf-8"),
                     object_pairs_hook=collections.OrderedDict)


def write_js(lang: str, data: dict) -> int:
    tr = data["translations"]
    plural = data.get("pluralForm", DEFAULT_PLURAL)
    lines = ["OC.L10N.register(", f'    "{APP}",', "    {"]
    items = list(tr.items())
    for i, (k, v) in enumerate(items):
        comma = "," if i < len(items) - 1 else ""
        key = json.dumps(k, ensure_ascii=False)
        val = json.dumps(v, ensure_ascii=False)
        lines.append(f"    {key} : {val}{comma}")
    lines += ["},", f'"{plural}");']
    out = "\n".join(lines) + "\n"
    with open(os.path.join(L10N, f"{lang}.js"), "w", encoding="utf-8") as f:
        f.write(out)
    return len(tr)


def main() -> int:
    check_only = "--check" in sys.argv
    problems = 0

    json_files = sorted(
        f for f in glob.glob(os.path.join(L10N, "*.json"))
        if not f.endswith(".bak")
    )
    if not os.path.exists(os.path.join(L10N, "en.json")):
        print("ERROR: l10n/en.json not found", file=sys.stderr)
        return 1

    # 1. validate wrapper format on every json
    langs = {}
    for path in json_files:
        lang = os.path.splitext(os.path.basename(path))[0]
        data = load_json(path)
        if "translations" not in data or not isinstance(data["translations"], dict):
            print(f"ERROR: {lang}.json is missing the \"translations\" wrapper "
                  f"(flat format crashes the Nextcloud server).", file=sys.stderr)
            problems += 1
            continue
        if "pluralForm" not in data:
            print(f"WARN:  {lang}.json has no \"pluralForm\" (using default).")
        langs[lang] = data

    if "en" not in langs:
        return 1 if problems else 0

    # 2. reconcile source strings against en.json
    src_keys = extract_source_keys()
    en_keys = set(langs["en"]["translations"])
    missing = sorted(src_keys - en_keys)
    orphan = sorted(en_keys - src_keys)
    if missing:
        problems += 1
        print("\nMISSING — used in code but not in l10n/en.json "
              "(add + translate these):")
        for k in missing:
            print(f"  - {k!r}")
    if orphan:
        print("\nORPHAN — in l10n/en.json but not found in src/ "
              "(safe to remove if not backend-only):")
        for k in orphan:
            print(f"  - {k!r}")

    # 3. per-language coverage report
    print("\nCoverage:")
    for lang, data in langs.items():
        keys = set(data["translations"])
        untranslated = sum(
            1 for k, v in data["translations"].items()
            if isinstance(v, str) and v == k and len(k) > 2
        )
        gaps = sorted(en_keys - keys)
        note = f", {len(gaps)} keys absent" if gaps else ""
        print(f"  {lang:<8} {len(keys):>3} strings{note}")

    # 4. regenerate .js (unless --check)
    if check_only:
        if problems:
            print("\n--check: issues found.")
            return 1
        print("\n--check: OK.")
        return 0

    print("\nRegenerating .js:")
    for lang, data in langs.items():
        n = write_js(lang, data)
        print(f"  wrote l10n/{lang}.js ({n} strings)")

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main())
