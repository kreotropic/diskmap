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

// application/x-diskmap-{pst,dwg} are synthetic — never a real mimetype
// Nextcloud assigns — produced by FilecacheUsageSource::recursiveComposition()'s
// SQL for a folder's aggregated composition (see its docblock): .pst/.dwg
// have no dedicated mimetype in Nextcloud, so they'd otherwise be
// indistinguishable from any other unrecognized binary sharing the generic
// application/octet-stream. Matched here so a folder's "Composição" bar
// buckets them as archive the same as a single file's own tile does (via
// categoryForFile() below, the per-file equivalent using the filename
// instead of a backend-computed pseudo-mimetype).
const ARCHIVE_PATTERN = /zip|tar|7z|rar|gzip|bzip2|x-xz|compress|iso9660|diskimage|executable|msdownload|portable-executable|debian\.binary-package|x-rpm|x-msi|cab-compressed|^application\/x-diskmap-(pst|dwg)$/i
const DOCUMENT_PATTERN = /^text\/|^application\/pdf$|^application\/(msword|vnd\.oasis|vnd\.openxmlformats|rtf|vnd\.ms-(excel|powerpoint))/i
const GENERIC_BINARY_MIMETYPE = 'application/octet-stream'
const EXTENSION_ARCHIVE_PATTERN = /\.(pst|dwg)$/i

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

/**
 * Extension-aware variant of categoryForMimetype() for a single named file
 * (a map tile or a tree file row, both of which know their own name) —
 * Outlook .pst archives and AutoCAD .dwg drawings have no dedicated
 * mimetype in Nextcloud, so they scan as the generic application/octet-stream,
 * same as any other file type Nextcloud doesn't recognize. Only overrides
 * the plain mimetype result when the mimetype is that generic fallback, so
 * a real archive mimetype is never second-guessed by a coincidental name.
 * @param {string|null} name
 * @param {string|null} mimetype
 * @return {string} one of CATEGORY_DOCUMENT / _IMAGE / _VIDEO / _ARCHIVE / _OTHER
 */
export function categoryForFile(name, mimetype) {
	if ((!mimetype || mimetype === GENERIC_BINARY_MIMETYPE) && name && EXTENSION_ARCHIVE_PATTERN.test(name)) {
		return CATEGORY_ARCHIVE
	}
	return categoryForMimetype(mimetype)
}
