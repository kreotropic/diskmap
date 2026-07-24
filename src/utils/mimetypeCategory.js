/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Maps a raw mimetype to one of 4 categories the treemap can safely color.
 * Any two treemap rectangles can end up as neighbors (it's not a bar chart
 * with fixed adjacency), so this is an "all-pairs" categorical palette —
 * validated with the dataviz skill's CVD checker, that caps at 4 hues:
 * `node scripts/validate_palette.js "<8 slots>" --pairs all` hard-FAILs
 * (ΔE 3.2 protan, 7.1 normal-vision — both below the floor), while the
 * first 4 slots clear every check in both light and dark. Everything else
 * — audio, code, unknown types, and folders — gets a neutral, non-hue color
 * so it never competes with the 4 categorical slots.
 */
export const CATEGORY_DOCUMENT = 'document'
export const CATEGORY_IMAGE = 'image'
export const CATEGORY_VIDEO = 'video'
export const CATEGORY_ARCHIVE = 'archive'
export const CATEGORY_OTHER = 'other'

const ARCHIVE_PATTERN = /zip|tar|7z|rar|gzip|bzip2|x-xz|compress|iso9660|diskimage|executable|msdownload|portable-executable|debian\.binary-package|x-rpm|x-msi|cab-compressed/i
const DOCUMENT_PATTERN = /^text\/|^application\/pdf$|^application\/(msword|vnd\.oasis|vnd\.openxmlformats|rtf|vnd\.ms-(excel|powerpoint))/i

/**
 * @param {string|null} mimetype
 * @return {string} one of CATEGORY_DOCUMENT / _IMAGE / _VIDEO / _ARCHIVE / _OTHER
 */
export function categoryForMimetype(mimetype) {
	if (!mimetype) {
		return CATEGORY_OTHER
	}
	if (mimetype.startsWith('image/')) {
		return CATEGORY_IMAGE
	}
	if (mimetype.startsWith('video/')) {
		return CATEGORY_VIDEO
	}
	if (ARCHIVE_PATTERN.test(mimetype)) {
		return CATEGORY_ARCHIVE
	}
	if (DOCUMENT_PATTERN.test(mimetype)) {
		return CATEGORY_DOCUMENT
	}
	return CATEGORY_OTHER
}
