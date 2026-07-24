<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="dm-treemap">
		<NcLoadingIcon v-if="loading && !root" :size="32" />
		<NcNoteCard v-if="error" type="error" class="dm-treemap__error">
			{{ t('diskmap', 'Could not load the largest files.') }}
		</NcNoteCard>
		<NcEmptyContent v-if="!loading && !error && (!root || !layoutNodes.length)" :name="t('diskmap', 'No files found.')" />

		<template v-if="root && layoutNodes.length">
			<svg
				ref="canvas"
				class="dm-treemap__canvas"
				:class="{ 'dm-treemap__canvas--loading': loading }"
				:viewBox="`0 0 ${canvasWidth} ${canvasHeight}`"
				preserveAspectRatio="none"
				role="img"
				:aria-label="t('diskmap', 'Storage treemap')">
				<g v-for="node in layoutNodes" :key="keyFor(node)">
					<rect
						:x="node.x0"
						:y="node.y0"
						:width="Math.max(0, node.x1 - node.x0 - 1)"
						:height="Math.max(0, node.y1 - node.y0 - 1)"
						:fill="colorFor(node.data)"
						:opacity="rectOpacity(node)"
						class="dm-treemap__rect"
						:class="{
							'dm-treemap__rect--clickable': isClickable(node.data),
							'dm-treemap__rect--hovered': hoveredKey === keyFor(node),
							'dm-treemap__rect--selected': selectedKey === keyFor(node),
						}"
						tabindex="0"
						:role="isClickable(node.data) ? 'button' : undefined"
						@click="activate(node)"
						@keydown.enter="activate(node)"
						@pointermove="showTooltip(node, $event)"
						@pointerleave="hideTooltip"
						@focus="showTooltip(node, $event)"
						@blur="hideTooltip" />
					<text
						v-if="labelFits(node)"
						:x="node.x0 + 4"
						:y="node.y0 + 14"
						class="dm-treemap__label">
						{{ truncateLabel(rectLabel(node.data), node.x1 - node.x0) }}
					</text>
				</g>
			</svg>

			<div v-if="tooltip" class="dm-treemap__tooltip" :style="{ left: tooltip.x + 'px', top: tooltip.y + 'px' }">
				<strong>{{ formatBytes(tooltip.node.data.size) }}</strong>
				<span>{{ rowLabel(tooltip.node) }}</span>
			</div>

			<div class="dm-treemap__legend">
				<button
					v-for="entry in legendEntries"
					:key="entry.key"
					class="dm-treemap__legend-item"
					:class="{ 'dm-treemap__legend-item--dimmed': activeCategory && activeCategory !== entry.key }"
					@click="toggleCategory(entry.key)">
					<span class="dm-treemap__legend-swatch" :style="{ background: entry.color }" />
					{{ entry.label }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import { hierarchy, treemap, treemapSquarify } from 'd3-hierarchy'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import { fetchMap } from '../services/api.js'
import { formatBytes } from '../utils/format.js'
import { categoryForMimetype, CATEGORY_DOCUMENT, CATEGORY_IMAGE, CATEGORY_VIDEO, CATEGORY_ARCHIVE, CATEGORY_OTHER } from '../utils/mimetypeCategory.js'

const CANVAS_WIDTH = 960
const CANVAS_HEIGHT = 380
const OTHERS_TYPE = 'other'

export default {
	name: 'Treemap',
	components: { NcLoadingIcon, NcEmptyContent, NcNoteCard },
	emits: ['reveal-path'],
	props: {
		scope: { type: String, required: true },
		identifier: { type: [String, Number], required: true },
		// The team folder's mount point name, needed to build a Files-app
		// link for scope="teamfolder" (its files live under /<folderName>/…
		// in the Files app, not under the internal filecache path).
		folderName: { type: String, default: '' },
	},
	data() {
		return {
			// Nested UsageNode tree from usage#map (plan Phase 3c) — root.children
			// is null until expanded; see IUsageSource::mapTree().
			root: null,
			loading: true,
			error: false,
			tooltip: null,
			activeCategory: null,
			hoveredKey: null,
			selectedKey: null,
			// Set by focusPath() when a folder is clicked in FolderTree.vue —
			// the tree→map half of the WinDirStat-style sync. Dims every tile
			// that isn't part of that folder's region instead of the usual
			// per-category dimming.
			highlightedFolderPath: null,
			canvasWidth: CANVAS_WIDTH,
			canvasHeight: CANVAS_HEIGHT,
		}
	},
	computed: {
		// d3 builds the full layout (every node's x0/y0/x1/y1, including
		// internal folder nodes we never draw) from the nested tree in one
		// shot — .leaves() then gives just the tiles this map actually
		// renders: real files, unexpanded folders (drawn as a single tile,
		// same idea as a collapsed WinDirStat branch), and each expanded
		// folder's own "other small files" bucket.
		layoutNodes() {
			if (!this.root) {
				return []
			}
			const hierarchyRoot = hierarchy(this.root, (d) => d.children)
				.sum((d) => (d.children ? 0 : Math.max(0, d.size)))
				.sort((a, b) => b.value - a.value)
			treemap().tile(treemapSquarify).size([this.canvasWidth, this.canvasHeight]).paddingInner(2).paddingOuter(1).round(true)(hierarchyRoot)
			return hierarchyRoot.leaves()
		},
		legendEntries() {
			return [
				{ key: CATEGORY_DOCUMENT, label: t('diskmap', 'Documents'), color: 'var(--dm-cat-document)' },
				{ key: CATEGORY_IMAGE, label: t('diskmap', 'Images'), color: 'var(--dm-cat-image)' },
				{ key: CATEGORY_VIDEO, label: t('diskmap', 'Video'), color: 'var(--dm-cat-video)' },
				{ key: CATEGORY_ARCHIVE, label: t('diskmap', 'Archives & installers'), color: 'var(--dm-cat-archive)' },
				{ key: CATEGORY_OTHER, label: t('diskmap', 'Other'), color: 'var(--dm-cat-other)' },
			]
		},
	},
	watch: {
		scope() {
			this.load()
		},
		identifier() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	beforeUnmount() {
		this.resizeObserver?.disconnect()
	},
	methods: {
		t,
		formatBytes,
		async load() {
			this.loading = true
			this.error = false
			this.activeCategory = null
			this.selectedKey = null
			this.highlightedFolderPath = null
			try {
				const data = await fetchMap(this.scope, this.identifier)
				this.root = data.root
			} catch (e) {
				this.error = true
			} finally {
				this.loading = false
				this.$nextTick(() => this.observeCanvasSize())
			}
		},
		// The SVG's logical coordinate system (canvasWidth/canvasHeight) is
		// kept in lockstep with its actual rendered pixel box. Without this,
		// preserveAspectRatio="none" stretches the 960x380 default to
		// whatever shape the flex layout gives the canvas — fine for
		// rectangle *areas* (an affine stretch preserves area ratios), but
		// it visibly smears text into illegible glyphs whenever the real
		// box isn't close to 960:380 (e.g. a short, wide map panel).
		observeCanvasSize() {
			const el = this.$refs.canvas
			if (!el || typeof ResizeObserver === 'undefined') {
				return
			}
			this.resizeObserver?.disconnect()
			this.resizeObserver = new ResizeObserver((entries) => {
				const entry = entries[0]
				if (!entry) {
					return
				}
				const { width, height } = entry.contentRect
				if (width > 0 && height > 0) {
					this.canvasWidth = Math.round(width)
					this.canvasHeight = Math.round(height)
				}
			})
			this.resizeObserver.observe(el)
		},
		// Relative path from the map's own root to this node, joining
		// ancestor names — deliberately the SAME algorithm FolderTree.vue
		// uses for navPath (parentNavPath + '/' + name, root = ''), computed
		// independently from the same underlying names so a file/folder's
		// map path and tree navPath always agree without either side
		// sending the other a path string.
		pathFor(node) {
			const segments = []
			let cur = node
			while (cur.parent) {
				segments.unshift(cur.data.name)
				cur = cur.parent
			}
			return segments.join('/')
		},
		keyFor(node) {
			if (node.data.type === OTHERS_TYPE) {
				// Every expanded folder can have its own "other" bucket now
				// (unlike the old flat map's single global one), so the key
				// needs the parent folder's path to stay unique.
				return this.pathFor(node.parent) + '#other'
			}
			return this.pathFor(node)
		},
		categoryFor(data) {
			// Anything that isn't an actual file (the 'other' bucket, or an
			// unexpanded folder shown as a single tile) reads as neutral —
			// a folder doesn't have one mimetype to color by.
			if (data.type !== 'file') {
				return CATEGORY_OTHER
			}
			return categoryForMimetype(data.mimetype)
		},
		colorFor(data) {
			return `var(--dm-cat-${this.categoryFor(data)})`
		},
		rectOpacity(node) {
			if (this.highlightedFolderPath !== null) {
				return this.isInHighlightedFolder(node) ? 1 : 0.15
			}
			if (!this.activeCategory) {
				return 1
			}
			return this.categoryFor(node.data) === this.activeCategory ? 1 : 0.25
		},
		isInHighlightedFolder(node) {
			let cur = node
			while (cur) {
				if (this.pathFor(cur) === this.highlightedFolderPath) {
					return true
				}
				cur = cur.parent
			}
			return false
		},
		isClickable(data) {
			return data.type === 'file'
		},
		// A single click both selects (visual feedback in the map itself)
		// and tells the folder tree above to expand down to and highlight
		// this same file — the actual WinDirStat map↔tree sync, not an
		// external Files-app link.
		activate(node) {
			if (!this.isClickable(node.data)) {
				return
			}
			this.selectedKey = this.keyFor(node)
			this.highlightedFolderPath = null
			this.$emit('reveal-path', this.pathFor(node))
		},
		// Called by the parent view (via $refs) when a row is clicked in
		// FolderTree.vue — the tree→map half of the sync. A folder highlights
		// its whole region (every tile whose ancestor chain includes it); a
		// file highlights just its own tile, same as clicking it here would.
		focusPath({ path, type }) {
			if (type === 'file') {
				this.highlightedFolderPath = null
				this.selectedKey = path
			} else {
				this.selectedKey = null
				this.highlightedFolderPath = path
			}
		},
		toggleCategory(key) {
			this.activeCategory = this.activeCategory === key ? null : key
		},
		labelFits(node) {
			return (node.x1 - node.x0) > 40 && (node.y1 - node.y0) > 18
		},
		truncateLabel(name, widthPx) {
			// Rough estimate at the label's 11px font — good enough to avoid
			// SVG text overflowing its own rectangle without a full text-metrics pass.
			const maxChars = Math.max(3, Math.floor((widthPx - 8) / 6))
			return name.length > maxChars ? name.slice(0, maxChars - 1) + '…' : name
		},
		rectLabel(data) {
			if (data.type !== OTHERS_TYPE) {
				return data.name
			}
			if (data.fileCount > 0) {
				const count = data.countExact ? String(data.fileCount) : `${data.fileCount}+`
				return t('diskmap', 'Other small files ({count})', { count })
			}
			return t('diskmap', 'Other')
		},
		rowLabel(node) {
			// Full path, not just the filename — these files can be anywhere
			// in the tree, so the path is what tells you where to look.
			return node.data.type === OTHERS_TYPE ? this.rectLabel(node.data) : this.pathFor(node)
		},
		showTooltip(node, event) {
			this.hoveredKey = this.keyFor(node)
			if (node.data.type === OTHERS_TYPE) {
				this.tooltip = { node, x: event.offsetX + 12, y: event.offsetY + 12 }
				return
			}
			const rect = this.$el.querySelector('.dm-treemap__canvas').getBoundingClientRect()
			const x = (event.clientX ?? rect.left) - rect.left + 12
			const y = (event.clientY ?? rect.top) - rect.top + 12
			this.tooltip = { node, x, y }
		},
		hideTooltip() {
			this.tooltip = null
			this.hoveredKey = null
		},
	},
}
</script>

<style scoped>
.dm-treemap {
	/* Harmonized categorical palette (equal-ish lightness/chroma, hue-only
	   distinction) — validated with the dataviz skill's validate_palette.js
	   (OKLCH + CVD simulation, --pairs all since treemap tiles can be any two
	   neighbors). Document/blue is the fixed anchor (closest to NC's own
	   blue); image/video/archive were re-stepped in chroma just enough to
	   clear the colorblind-separation floor while keeping the requested
	   hues. CVD separation lands in the 6-8 "warn" band, which the method
	   allows given the secondary encoding this map already ships: direct
	   text labels on tiles big enough, plus the translucent tile border
	   below. "Other" gray was kept light/discreet as requested (Outros
	   should recede, not compete) — its separation from the green (image)
	   hue is a little tighter than ideal, mitigated the same way. */
	--dm-cat-document: #3e6fa8;
	--dm-cat-image: #3e925c;
	--dm-cat-video: #9c4976;
	--dm-cat-archive: #b77722;
	--dm-cat-other: #8a8f94;
	position: relative;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	min-height: 0;
}

@media (prefers-color-scheme: dark) {
	body:not([data-theme-light]) .dm-treemap {
		--dm-cat-document: #2f82dc;
		--dm-cat-image: #06793f;
		--dm-cat-video: #b64886;
		--dm-cat-archive: #c58431;
		--dm-cat-other: #7d848b;
	}
}

body[data-theme-dark] .dm-treemap {
	--dm-cat-document: #2f82dc;
	--dm-cat-image: #06793f;
	--dm-cat-video: #b64886;
	--dm-cat-archive: #c58431;
	--dm-cat-other: #7d848b;
}

.dm-treemap__error {
	margin-bottom: 12px;
}

.dm-treemap__canvas {
	width: 100%;
	flex: 1 1 auto;
	min-height: 0;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.dm-treemap__canvas--loading {
	opacity: 0.5;
	pointer-events: none;
	transition: opacity 0.15s;
}

.dm-treemap__rect {
	/* A translucent white seam (not a theme color) so adjacent same-hue
	   tiles (several small blue "item-…" files side by side) stay visually
	   separated regardless of light/dark mode. */
	stroke: rgba(255, 255, 255, 0.15);
	stroke-width: 1px;
}

.dm-treemap__rect--clickable {
	cursor: pointer;
}

.dm-treemap__rect--clickable:hover,
.dm-treemap__rect--clickable:focus {
	filter: brightness(1.15);
	outline: none;
}

.dm-treemap__rect--hovered {
	filter: brightness(1.15);
}

.dm-treemap__rect--selected {
	stroke: var(--color-main-text);
	stroke-width: 2px;
}

.dm-treemap__label {
	font-size: 11px;
	fill: #fff;
	pointer-events: none;
	paint-order: stroke;
	stroke: rgba(0, 0, 0, 0.4);
	stroke-width: 1.5px;
	stroke-linejoin: round;
}

.dm-treemap__tooltip {
	position: absolute;
	pointer-events: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	font-size: 0.85em;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
	display: flex;
	flex-direction: column;
	z-index: 10;
	max-width: 260px;
}

.dm-treemap__tooltip strong {
	color: var(--color-main-text);
}

.dm-treemap__tooltip span {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dm-treemap__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 4px;
	flex-shrink: 0;
}

.dm-treemap__legend-item {
	display: flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	cursor: pointer;
	padding: 1px 3px;
	color: var(--color-main-text);
	font-size: 0.78em;
	opacity: 1;
	transition: opacity 0.15s;
}

.dm-treemap__legend-item--dimmed {
	opacity: 0.4;
}

.dm-treemap__legend-swatch {
	width: 9px;
	height: 9px;
	border-radius: 2px;
	display: inline-block;
}
</style>
