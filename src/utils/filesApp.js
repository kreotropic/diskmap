/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Builds a deep link into the Files app for a path inside one of DiskMap's
 * own scopes. `dir` is the query param the Files app's own router reads
 * (confirmed live in its router.ts/folderTree.ts, not a legacy shim), so
 * this needs no fileid and works the same way core's own "Open in Files"
 * action does for a folder.
 *
 * `prefix` is the top-level folder name Files shows this scope under: empty
 * for the user's own home (DiskMap's paths already match Files' own root
 * there), or a team folder's mount point otherwise — which IS that folder's
 * real top-level name in Files, not an approximation (TeamFolderService
 * already sets `name` from the same `mount_point` column).
 *
 * A file selection resolves to its *parent* folder — `dir` navigates to a
 * folder, not a specific file.
 */
export function filesAppUrl(prefix, navPath, type) {
	let dirPath = navPath
	if (type === 'file') {
		const idx = navPath.lastIndexOf('/')
		dirPath = idx === -1 ? '' : navPath.slice(0, idx)
	}
	const dir = '/' + [prefix, dirPath].filter(Boolean).join('/')
	return generateUrl('/apps/files/files') + '?dir=' + encodeURIComponent(dir)
}
