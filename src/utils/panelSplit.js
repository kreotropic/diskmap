/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const STORAGE_KEY = 'diskmap-panel-split'

// Tree:map ratio — the tree needs less room than the map to still be useful
// (it's a fast-scanning list), so the map gets the majority share by default.
export const DEFAULT_PANE_SIZES = [40, 60]

/**
 * Reads the user's saved tree/map split (percentages, [treeSize, mapSize])
 * from localStorage. Shared across both views (team folder + personal) —
 * it's a UI preference about how the user likes to work, not tied to which
 * folder they're looking at.
 */
export function loadPaneSizes() {
	try {
		const raw = window.localStorage.getItem(STORAGE_KEY)
		const parsed = raw ? JSON.parse(raw) : null
		if (Array.isArray(parsed) && parsed.length === 2 && parsed.every((n) => Number.isFinite(n) && n > 0)) {
			return parsed
		}
	} catch (e) {
		// localStorage unavailable (private browsing) or a malformed stored
		// value — fall back to the default split rather than failing.
	}
	return [...DEFAULT_PANE_SIZES]
}

export function savePaneSizes(sizes) {
	try {
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify(sizes))
	} catch (e) {
		// Storage unavailable or full — the split just won't persist, not fatal.
	}
}
