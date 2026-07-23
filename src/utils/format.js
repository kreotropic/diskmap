/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

/**
 * Format a byte count as a human-readable string (binary, 1024-based).
 */
export function formatBytes(bytes) {
	if (!Number.isFinite(bytes) || bytes < 0) {
		return '—'
	}
	if (bytes === 0) {
		return '0 B'
	}
	const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), UNITS.length - 1)
	const value = bytes / 1024 ** exponent
	return `${value.toFixed(exponent === 0 ? 0 : 1)} ${UNITS[exponent]}`
}

/**
 * Format a Unix timestamp (seconds) as a locale date-time string.
 */
export function formatDate(timestamp) {
	if (!timestamp) {
		return '—'
	}
	return new Date(timestamp * 1000).toLocaleString()
}
