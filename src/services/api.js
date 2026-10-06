/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/diskmap' + path)

// ---------------------------------------------------------------------------
// Shared cache for the file-ages aggregation: it is the only read whose cost
// grows with the whole scope (full filecache scan), so two charts mounting
// at once must never trigger it twice, and switching chart type/category
// must reuse an already computed payload.
// ---------------------------------------------------------------------------
const FILE_AGES_TTL_MS = 120000
const fileAgesCache = new Map()

/**
 * Fetch the admin team-folder overview: used/quota, files/trash/versions
 * breakdown, and linked groups/circles for every team folder.
 */
export async function fetchTeamFolders() {
	const { data } = await axios.get(base('/api/v1/admin/teamfolders'))
	return data.teamFolders
}

/**
 * Fetch the admin external-storage list: one entry per files_external mount
 * (S3, SMB, WebDAV, local …), with its total and whether that total is
 * exact. Browsing one afterwards needs nothing special — it is a plain
 * 'storage' scope keyed by the numeric storage id returned here.
 */
export async function fetchExternalStorages() {
	const { data } = await axios.get(base('/api/v1/admin/externalstorages'))
	return data.externalStorages
}

/**
 * Fetch the caller's own storage overview: files/trash/versions breakdown
 * and quota occupancy.
 */
export async function fetchMyOverview() {
	const { data } = await axios.get(base('/api/v1/my/overview'))
	return data
}

/**
 * Fetch one level of immediate children (files and folders) under an
 * explicit scope + path — not recursive.
 *
 * @param {string} scope 'user' | 'teamfolder' | 'storage'
 * @param {string|number} identifier uid, team folder id, or numeric storage id
 * @param {object} params { path, limit }
 */
export async function fetchChildren(scope, identifier, params = {}) {
	const { data } = await axios.get(base('/api/v1/children'), {
		params: { scope, identifier, ...params },
	})
	return data
}

/**
 * Fetch the recursive aggregates (descendant file count + per-mimetype size
 * breakdown) for the same level fetchChildren() returns, keyed by child name.
 *
 * Deliberately a second request rather than part of the first: it is the only
 * read whose cost grows with the whole subtree instead of with the row limit,
 * so the tree renders its rows from fetchChildren() and fills the two
 * aggregate columns in when this answers. Pass the same path/limit as the
 * fetchChildren() call it accompanies or the two describe different rows.
 *
 * @param {string} scope 'user' | 'teamfolder' | 'storage' | 'instance'
 * @param {string|number} identifier uid, team folder id, or numeric storage id
 * @param {object} params { path, limit }
 */
export async function fetchComposition(scope, identifier, params = {}) {
	const { data } = await axios.get(base('/api/v1/composition'), {
		params: { scope, identifier, ...params },
	})
	return data
}

/**
 * Fetch the recursive, folder-nested tree the map renders (files nested
 * inside folders, node-budgeted — see UsageController::map()).
 *
 * @param {string} scope 'user' | 'teamfolder' | 'storage'
 * @param {string|number} identifier uid, team folder id, or numeric storage id
 * @param {object} params { path, maxNodes }
 */
export async function fetchMap(scope, identifier, params = {}) {
	const { data } = await axios.get(base('/api/v1/map'), {
		params: { scope, identifier, ...params },
	})
	return data
}

/**
 * Fetch the file-age histogram for a scope, optionally restricted to a
 * subtree path: five buckets (≤1y, 1-3y, 3-6y, 6-10y, >10y) + the file
 * total. Server-aggregated so the payload is constant whatever the scope
 * holds (see UsageController::fileAges()).
 *
 * Deduplicated + cached: concurrent calls with the same arguments share one
 * in-flight request (both FileAgeChart instances mount at once — v-show
 * tabs), and answers stay valid for FILE_AGES_TTL_MS so re-mounts driven by
 * the metric dropdown (:key) and category toggles cost nothing. Failures
 * evict the entry so the next call can retry.
 *
 * @param {string} scope 'user' | 'teamfolder' | 'storage' | 'instance'
 * @param {string|number} identifier uid, team folder id, or numeric storage id
 * @param {string} activeCategory CATEGORY_DOCUMENT, CATEGORY_IMAGE, CATEGORY_VIDEO, CATEGORY_ARCHIVE, CATEGORY_OTHER
 * @param {object} params { path }
 */
export function fetchFileAges(scope, identifier, activeCategory, params = {}) {
	const key = JSON.stringify(['file-ages', scope, identifier, activeCategory ?? null, params])
	const now = Date.now()
	const hit = fileAgesCache.get(key)
	if (hit && now < hit.expires) {
		return hit.promise
	}
	const promise = axios.get(base('/api/v1/file-ages'), {
		params: { scope, identifier, activeCategory, ...params },
	}).then(
		({ data }) => data,
		(err) => {
			fileAgesCache.delete(key)
			throw err
		},
	)
	// Detached no-op catch: keeps a rejection unobserved by any caller from
	// logging "Uncaught (in promise)" — real callers attach their own.
	promise.catch(() => {})
	fileAgesCache.set(key, { promise, expires: now + FILE_AGES_TTL_MS })
	return promise
}

/**
 * Fetch the whole-instance header total: files+trash+versions across every
 * user and team folder, plus the files-only figure the tree/map below
 * actually browse (see UsageController::instanceOverview()).
 */
export async function fetchInstanceOverview() {
	const { data } = await axios.get(base('/api/v1/instance/overview'))
	return data
}