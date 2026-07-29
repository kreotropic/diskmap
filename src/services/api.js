/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/diskmap' + path)

/**
 * Fetch the admin team-folder overview: used/quota, files/trash/versions
 * breakdown, and linked groups/circles for every team folder.
 */
export async function fetchTeamFolders() {
	const { data } = await axios.get(base('/api/v1/admin/teamfolders'))
	return data.teamFolders
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
 * Fetch the whole-instance header total: files+trash+versions across every
 * user and team folder, plus the files-only figure the tree/map below
 * actually browse (see UsageController::instanceOverview()).
 */
export async function fetchInstanceOverview() {
	const { data } = await axios.get(base('/api/v1/instance/overview'))
	return data
}
