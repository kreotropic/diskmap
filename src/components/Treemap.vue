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
				<defs>
					<!-- One gradient definition reused by every tile: in the default
						 objectBoundingBox units it re-maps to each rect's own box, so
						 every block gets its own light-to-dark sheen rather than one
						 gradient stretched across the whole canvas. The vector is CSS's
						 165deg: a CSS angle runs along (sin, -cos), which at 165 is
						 (0.26, 0.97) — top-left toward bottom-right, steeply. -->
					<linearGradient id="dm-tile-sheen" x1="0" y1="0" x2="0.26" y2="0.97">
						<stop offset="0" stop-color="#fff" stop-opacity="0.16" />
						<stop offset="1" stop-color="#000" stop-opacity="0.1" />
					</linearGradient>
				</defs>
				<!-- Opacity sits on the group rather than on the fill rect so the
					 sheen dims in step with the tile beneath it whenever the category
					 filter or a folder highlight is active. -->
				<g
					v-for="node in layoutNodes"
					:key="keyFor(node)"
					class="dm-treemap__tile"
					:opacity="rectOpacity(node)">
					<rect
						:x="node.x0"
						:y="node.y0"
						:width="tileWidth(node)"
						:height="tileHeight(node)"
						:fill="colorFor(node.data)"
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
					<!-- Skipped on tiles too small to shade, where it would only
						 muddy an already tiny patch of colour. pointer-events="none"
						 leaves every interaction on the fill rect underneath. -->
					<rect
						v-if="tileWidth(node) > 3 && tileHeight(node) > 3"
						:x="node.x0"
						:y="node.y0"
						:width="tileWidth(node)"
						:height="tileHeight(node)"
						class="dm-treemap__sheen"
						fill="url(#dm-tile-sheen)"
						pointer-events="none" />
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
import { categoryForFile, CATEGORY_OTHER } from '../utils/mimetypeCategory.js'

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
		// Owned by the parent view (which also renders <CategoryLegend> in its
		// header) rather than by Treemap itself — both need to read/write the
		// same value, and they're siblings in the DOM, not parent/child.
		activeCategory: { type: String, default: null },
	},
	data() {
		return {
			// Nested UsageNode tree from usage#map (plan Phase 3c) — root.children
			// is null until expanded; see IUsageSource::mapTree().
			root: null,
			loading: true,
			error: false,
			tooltip: null,
			hoveredKey: null,
			selectedKey: null,
			// Set by focusPath() when a folder is clicked in FolderTree.vue —
			// the tree→map half of the WinDirStat-style sync. Dims every tile
			// that isn't part of that folder's region instead of the usual
			// per-category dimming.
			highlightedFolderPath: null,
			// Staleness guard for load(): the scope/identifier watchers can
			// start a second fetch before the first resolves, and without a
			// token whichever response lands LAST wins rather than whichever
			// was asked for last — the map would then show a different scope
			// than the tree beside it. Same pattern as FolderTree's
			// loadToken/revealToken.
			loadToken: 0,
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
			const token = ++this.loadToken
			this.loading = true
			this.error = false
			this.selectedKey = null
			this.highlightedFolderPath = null
			try {
				const data = await fetchMap(this.scope, this.identifier)
				if (token !== this.loadToken) {
					return
				}
				this.root = data.root
			} catch (e) {
				if (token === this.loadToken) {
					this.error = true
				}
			} finally {
				// Only the still-current load owns the spinner and the
				// canvas observer; a superseded one must leave both alone.
				if (token === this.loadToken) {
					this.loading = false
					this.$nextTick(() => this.observeCanvasSize())
				}
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
		// Same walk as pathFor(), for the tooltip only — prefers displayName
		// per segment (only ever set on the top-level instance segment) so an
		// LDAP/AD uid doesn't leak into the human-readable breadcrumb even
		// though pathFor() itself must keep returning the raw-uid path for
		// navigation (reveal-path, keyFor(), highlight matching).
		displayPathFor(node) {
			const segments = []
			let cur = node
			while (cur.parent) {
				segments.unshift(cur.data.displayName ?? cur.data.name)
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
			return categoryForFile(data.name, data.mimetype)
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
			// An unexpanded folder tile is common at this node budget on a
			// large scope (most of the tree stays folded into flat "folder"
			// tiles rather than reaching individual files) — it needs to be
			// clickable too, not just files, or most of what's actually on
			// screen would be dead space with no map↔tree sync at all.
			// The fold-in bucket is clickable as well: it has no path of its
			// own, but its containing folder does, and that folder's tree row
			// lists every file folded in here individually (see activate()).
			return data.type === 'file' || data.type === 'folder' || data.type === OTHERS_TYPE
		},
		// A single click both selects (visual feedback in the map itself)
		// and tells the folder tree above to expand down to and highlight
		// this same file or folder — the actual WinDirStat map↔tree sync,
		// not an external Files-app link.
		activate(node) {
			if (!this.isClickable(node.data)) {
				return
			}
			this.selectedKey = this.keyFor(node)
			this.highlightedFolderPath = null
			// The fold-in bucket is synthetic — it stands for many files at
			// once and has no path to reveal. Its parent folder does, and the
			// tree lists that folder's children individually (the tree is not
			// node-budgeted the way the map is), so this is where the files
			// folded in here can actually be read one by one. Expanding the
			// bucket in place is not possible: on the whole-server view it can
			// even span several different accounts.
			const target = node.data.type === OTHERS_TYPE ? node.parent : node
			if (!target) {
				return
			}
			this.$emit('reveal-path', this.pathFor(target))
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
		// The 1px taken off each tile is the seam between neighbours. Shared by
		// the fill rect and the sheen laid over it so the two can never drift
		// apart and leave a bright or dark fringe along an edge.
		tileWidth(node) {
			return Math.max(0, node.x1 - node.x0 - 1)
		},
		tileHeight(node) {
			return Math.max(0, node.y1 - node.y0 - 1)
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
				// displayName is only set on a top-level instance tile (a
				// user's home on an LDAP/AD-backed instance, where data.name
				// stays the raw uid — pathFor()/keyFor() still need it for
				// navigation, only the on-screen label prefers the human name).
				return data.displayName ?? data.name
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
			return node.data.type === OTHERS_TYPE ? this.rectLabel(node.data) : this.displayPathFor(node)
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
	/* --dm-cat-* category colors now live in css/main.css, on the shared
	   .app-diskmap ancestor — FolderTree.vue's composition bar needs the
	   same values and it's a sibling, not a child, of this component. */
	position: relative;
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	min-height: 0;
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

/* Each tile is a group: the flat category fill, then a gradient sheen over
   it. Isolating the group keeps that sheen blending against its own tile
   rather than against whatever the canvas shows through the seams — without
   this, blend containment would only happen by accident, whenever the group
   already had opacity below 1. */
.dm-treemap__tile {
	isolation: isolate;
}

.dm-treemap__rect {
	/* A translucent white seam (not a theme color) so adjacent same-hue
	   tiles (several small blue "item-…" files side by side) stay visually
	   separated regardless of light/dark mode. */
	stroke: rgba(255, 255, 255, 0.55);
	stroke-width: 1px;
}

/* The depth pass: a single shared gradient (see the <defs> in the template)
   laid over the flat fill, blended rather than simply painted on, so it
   shades the category colour instead of washing it toward grey. Deliberately
   square-cornered like the fill beneath it — rounding individual tiles would
   eat area at the corners and make small blocks read as smaller than the
   number they stand for, which is the one thing a treemap must get right.
   Only the canvas as a whole is rounded. */
.dm-treemap__sheen {
	mix-blend-mode: overlay;
	stroke: rgba(0, 0, 0, 0.05);
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

</style>
