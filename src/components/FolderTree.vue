<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="dm-tree">
		<NcLoadingIcon v-if="loadingRoot" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ t('diskmap', 'Could not load the folder tree.') }}
		</NcNoteCard>
		<NcEmptyContent v-else-if="!flatTree.length" :name="t('diskmap', 'No items found.')" />

		<template v-else>
			<div class="dm-tree__rows">
				<div class="dm-tree__header-row">
					<span class="dm-tree__col-name" />
					<span class="dm-tree__col-composition">{{ t('diskmap', 'Composition') }}</span>
					<span class="dm-tree__col-pct">{{ t('diskmap', '% of parent') }}</span>
					<span class="dm-tree__col-size">{{ t('diskmap', 'Size') }}</span>
					<span class="dm-tree__col-count">{{ t('diskmap', 'File count') }}</span>
					<span class="dm-tree__col-mtime">{{ t('diskmap', 'Last modified') }}</span>
				</div>

				<div
					v-for="(node, index) in flatTree"
					:key="node.navPath ?? `other-${index}`"
					ref="rowRefs"
					class="dm-tree__row"
					:class="{ 'dm-tree__row--selected': node.navPath !== null && node.navPath === selectedNavPath }"
					@click="selectNode(node)">
					<span class="dm-tree__col-name" :style="{ paddingLeft: (node.depth * 20 + 4) + 'px' }">
						<button
							v-if="node.hasArrow"
							class="dm-tree__arrow"
							:class="{ 'dm-tree__arrow--expanded': node.expanded }"
							:title="node.expanded ? t('diskmap', 'Collapse') : t('diskmap', 'Expand')"
							@click.stop="toggle(node)">
							<span v-if="node.loadingChildren" class="dm-tree__spinner" />
						</button>
						<span v-else class="dm-tree__arrow-placeholder" />
						<span class="dm-tree__icon">{{ iconFor(node) }}</span>
						<span class="dm-tree__name">{{ node.name }}</span>
					</span>
					<span class="dm-tree__col-composition">
						<span class="dm-tree__comp-track">
							<span
								v-for="seg in compositionSegments(node)"
								:key="seg.category"
								class="dm-tree__comp-seg"
								:style="{ width: seg.pct + '%', background: seg.color }" />
						</span>
					</span>
					<span class="dm-tree__col-pct">{{ pct(node).toFixed(1) }}%</span>
					<span class="dm-tree__col-size">{{ formatBytes(node.size) }}</span>
					<span class="dm-tree__col-count">{{ formatCount(node.fileCount) }}</span>
					<span class="dm-tree__col-mtime">{{ formatDate(node.mtime) }}</span>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import { fetchChildren } from '../services/api.js'
import { formatBytes, formatDate, formatCount } from '../utils/format.js'
import { categoryForMimetype, CATEGORY_OTHER } from '../utils/mimetypeCategory.js'

const OTHERS_TYPE = 'other'
// One level's worth of children per fetch — comfortably covers realistic
// folder fan-out; the truncation row handles overflow gracefully either way.
const CHILD_LIMIT = 200

export default {
	name: 'FolderTree',
	components: { NcLoadingIcon, NcEmptyContent, NcNoteCard },
	emits: ['select-path'],
	props: {
		scope: { type: String, required: true },
		identifier: { type: [String, Number], required: true },
		// Display name for the root row — the backend's own name for the
		// scope root is an internal implementation detail ("files", or a
		// team folder's raw storage name), never something a user chose.
		rootLabel: { type: String, required: true },
		// Kept for prop-shape parity with Treemap.vue; not used by this
		// read-only, expand/collapse-only component yet.
		folderName: { type: String, default: '' },
	},
	data() {
		return {
			flatTree: [],
			loadingRoot: true,
			error: false,
			selectedNavPath: null,
		}
	},
	watch: {
		scope() {
			this.loadRoot()
		},
		identifier() {
			this.loadRoot()
		},
	},
	mounted() {
		this.loadRoot()
	},
	methods: {
		t,
		formatBytes,
		formatDate,
		formatCount,
		async loadRoot() {
			this.loadingRoot = true
			this.error = false
			this.selectedNavPath = null
			try {
				const data = await fetchChildren(this.scope, this.identifier, { path: '', limit: CHILD_LIMIT })
				if (!data.root) {
					this.flatTree = []
					return
				}
				// Root's navPath is always '' (the scope root itself, per
				// Scope's own convention) — never data.root.name, which is
				// just the backend's internal name for that path ("files",
				// or a team folder's raw storage name) and must not be
				// treated as a path segment to descend into.
				const root = {
					name: this.rootLabel,
					navPath: '',
					size: data.root.size,
					mtime: data.root.mtime,
					type: data.root.type,
					fileCount: data.root.fileCount ?? null,
					composition: data.root.composition ?? null,
					kind: null,
					depth: 0,
					parentSize: null,
					// Never collapsible — it's the scope root, always visible,
					// same idea as a fixed "C:\" row.
					hasArrow: false,
					expanded: true,
					loadingChildren: false,
				}
				const children = this.childNodes(data.items, data.truncated, root)
				this.flatTree = [root, ...children]
			} catch (e) {
				this.error = true
			} finally {
				this.loadingRoot = false
			}
		},
		async toggle(node) {
			if (node.expanded) {
				this.collapse(node)
			} else {
				await this.expand(node)
			}
		},
		async expand(node) {
			if (node.loadingChildren || node.expanded) {
				return
			}
			node.loadingChildren = true
			try {
				const data = await fetchChildren(this.scope, this.identifier, { path: node.navPath, limit: CHILD_LIMIT })
				const children = this.childNodes(data.items, data.truncated, node)
				const idx = this.flatTree.indexOf(node)
				this.flatTree.splice(idx + 1, 0, ...children)
				node.expanded = true
			} catch (e) {
				// Silently ignore — the arrow stays collapsed, matching FolderPicker.vue's precedent.
			} finally {
				node.loadingChildren = false
			}
		},
		collapse(node) {
			const idx = this.flatTree.indexOf(node)
			let end = idx + 1
			// Depth-based, not path-prefix-based (FolderPicker.vue's approach) —
			// this also correctly sweeps up the synthetic truncation row, which
			// has no real path to prefix-match against.
			while (end < this.flatTree.length && this.flatTree[end].depth > node.depth) {
				end++
			}
			this.flatTree.splice(idx + 1, end - idx - 1)
			node.expanded = false
		},
		// Builds child nodes for one expanded level: the fetched items plus,
		// if truncated, a synthetic "more items" row sized as the gap between
		// what was fetched and the parent's real total (same idea as
		// Treemap.vue's __others__ bucket, computed per level here instead of
		// once for the whole scope).
		childNodes(items, truncated, parentNode) {
			const nodes = items.map((item) => this.makeNode(item, parentNode.depth + 1, parentNode.navPath, parentNode.size))
			if (truncated) {
				const accountedFor = items.reduce((sum, item) => sum + Math.max(0, item.size), 0)
				const remainder = parentNode.size - accountedFor
				if (remainder > 0) {
					nodes.push({
						name: t('diskmap', 'More items not shown'),
						navPath: null,
						size: remainder,
						mtime: null,
						type: OTHERS_TYPE,
						fileCount: null,
						depth: parentNode.depth + 1,
						parentSize: parentNode.size,
						hasArrow: false,
						expanded: false,
						loadingChildren: false,
					})
				}
			}
			return nodes
		},
		makeNode(item, depth, parentNavPath, parentSize) {
			const navPath = parentNavPath === '' ? item.name : `${parentNavPath}/${item.name}`
			return {
				name: item.name,
				navPath,
				size: item.size,
				mtime: item.mtime,
				type: item.type,
				// Only ever set for a 'file' item — used to color its
				// composition-bar segment via categoryForMimetype().
				mimetype: item.mimetype ?? null,
				fileCount: item.fileCount ?? null,
				composition: item.composition ?? null,
				// Only set on a top-level row of the whole-instance scope
				// ('user' | 'teamfolder' | 'external') — null everywhere else.
				kind: item.kind ?? null,
				depth,
				parentSize,
				hasArrow: item.type === 'folder' && item.size > 0,
				expanded: false,
				loadingChildren: false,
			}
		},
		pct(node) {
			if (node.parentSize === null || node.parentSize === undefined || node.parentSize <= 0) {
				return 100
			}
			return (Math.max(0, node.size) / node.parentSize) * 100
		},
		// The "Composição" stacked bar: for a folder, node.composition (a
		// backend-computed {mimetype: size} map recursive over every
		// descendant file) gets bucketed into the 5 UI categories and turned
		// into segment widths. A file has no composition of its own from the
		// backend (its single node.mimetype already says everything) — it
		// reads as one 100%-width segment in its own category. The synthetic
		// "more items not shown" row's true mix is unknown, so it reads as
		// 100% "Other" rather than guessing.
		compositionSegments(node) {
			if (node.type === OTHERS_TYPE) {
				return [{ category: CATEGORY_OTHER, pct: 100, color: 'var(--dm-cat-other)' }]
			}
			if (node.type === 'file') {
				const category = categoryForMimetype(node.mimetype)
				return [{ category, pct: 100, color: `var(--dm-cat-${category})` }]
			}
			if (!node.composition || node.size <= 0) {
				return []
			}
			const byCategory = {}
			for (const [mimetype, size] of Object.entries(node.composition)) {
				const category = categoryForMimetype(mimetype)
				byCategory[category] = (byCategory[category] ?? 0) + Math.max(0, size)
			}
			return Object.entries(byCategory)
				.map(([category, size]) => ({ category, pct: (size / node.size) * 100, color: `var(--dm-cat-${category})` }))
				.sort((a, b) => b.pct - a.pct)
		},
		// node.kind is only set on a top-level row of the whole-instance
		// scope — everywhere else (a normal folder, any depth under any
		// scope) it's null and falls through to the plain folder/file icons.
		iconFor(node) {
			if (node.kind === 'user') {
				return '👤'
			}
			if (node.kind === 'teamfolder') {
				return '👥'
			}
			if (node.kind === 'external') {
				return '🔗'
			}
			return node.type === 'folder' ? '📁' : '📄'
		},
		// Clicking any real row (not the synthetic "more items" row, which
		// has no navPath) both highlights it here and tells the map to
		// focus the same item — a folder highlights its whole region, a
		// file highlights just its own tile (the tree→map half of the
		// WinDirStat-style sync; revealPath() below is the map→tree half).
		selectNode(node) {
			if (node.navPath === null) {
				return
			}
			this.selectedNavPath = node.navPath
			this.$emit('select-path', { path: node.navPath, type: node.type })
		},
		// Called by the parent view (via $refs) when a file is clicked in
		// Treemap.vue — WinDirStat's actual map↔tree sync, not an external
		// Files-app link. Expands every ancestor folder along targetPath
		// (fetching only the levels not already expanded) and scrolls to /
		// highlights the leaf once it's visible.
		async revealPath(targetPath) {
			if (!targetPath || !this.flatTree.length) {
				return
			}
			const segments = targetPath.split('/')
			let currentPath = ''
			for (let i = 0; i < segments.length - 1; i++) {
				currentPath = currentPath === '' ? segments[i] : `${currentPath}/${segments[i]}`
				const node = this.flatTree.find((n) => n.navPath === currentPath)
				if (!node) {
					// A segment isn't in the tree yet (e.g. a deeper level we
					// haven't loaded because an ancestor wasn't a folder, or
					// the path simply doesn't exist) — nothing more we can do.
					return
				}
				if (!node.expanded) {
					await this.expand(node)
				}
			}

			this.selectedNavPath = targetPath
			await this.$nextTick()
			const idx = this.flatTree.findIndex((n) => n.navPath === targetPath)
			const el = Array.isArray(this.$refs.rowRefs) ? this.$refs.rowRefs[idx] : null
			if (el) {
				el.scrollIntoView({ block: 'center', behavior: 'smooth' })
			}
		},
	},
}
</script>

<style scoped>
.dm-tree {
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	min-height: 0;
}

.dm-tree__header-row,
.dm-tree__row {
	display: grid;
	grid-template-columns: minmax(220px, 1fr) 120px 55px 90px 90px 160px;
	align-items: center;
	gap: 6px;
	font-size: 12.5px;
}

.dm-tree__header-row {
	padding: 2px 8px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	position: sticky;
	top: 0;
	z-index: 1;
}

.dm-tree__rows {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.dm-tree__row {
	padding: 1px 8px;
	border-bottom: 1px solid var(--color-border);
}

.dm-tree__row {
	cursor: pointer;
}

.dm-tree__row:hover {
	background: var(--color-background-hover);
}

.dm-tree__row--selected {
	background: var(--color-primary-element-light);
}

.dm-tree__col-name {
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 0;
}

.dm-tree__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dm-tree__arrow {
	/* Override NC's global button rule (min-height: var(--default-clickable-area),
	   34px) — it was stretching every row to 34px regardless of the row's
	   own padding/font-size, since a grid row's height follows its tallest
	   cell. */
	min-height: 0;
	min-width: 0;
	height: 16px;
	background: none;
	border: none;
	cursor: pointer;
	padding: 0 2px;
	width: 16px;
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	justify-content: center;
}

.dm-tree__arrow::before {
	content: '';
	display: inline-block;
	width: 5px;
	height: 5px;
	border-right: 1.5px solid currentColor;
	border-bottom: 1.5px solid currentColor;
	transform: rotate(-45deg);
	transition: transform 0.15s ease;
}

.dm-tree__arrow--expanded::before {
	transform: rotate(45deg);
}

.dm-tree__arrow-placeholder {
	display: inline-block;
	width: 16px;
	flex-shrink: 0;
}

.dm-tree__spinner {
	display: inline-block;
	width: 10px;
	height: 10px;
	border: 1.5px solid var(--color-border-dark);
	border-top-color: var(--color-main-text);
	border-radius: 50%;
	animation: dm-tree-spin 0.6s linear infinite;
}

@keyframes dm-tree-spin {
	to {
		transform: rotate(360deg);
	}
}

.dm-tree__icon {
	flex-shrink: 0;
}

.dm-tree__col-composition {
	display: flex;
	align-items: center;
}

.dm-tree__comp-track {
	display: flex;
	width: 100%;
	height: 8px;
	background: var(--color-background-darker);
	border-radius: 3px;
	overflow: hidden;
}

.dm-tree__comp-seg {
	display: block;
	height: 100%;
}

.dm-tree__col-pct {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-align: right;
}

.dm-tree__col-size,
.dm-tree__col-count {
	text-align: right;
	font-size: 0.9em;
}

.dm-tree__col-mtime {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
