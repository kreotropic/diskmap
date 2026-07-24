/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
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
