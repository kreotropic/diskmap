<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="teamfolder-detail">
		<div class="teamfolder-detail__header">
			<h2>{{ folder.name }}</h2>
			<span class="teamfolder-detail__sep">·</span>
			<span><strong>{{ formatBytes(folder.used) }}</strong> {{ t('diskmap', 'used') }}</span>
			<span class="teamfolder-detail__sep">·</span>
			<span>{{ t('diskmap', 'Quota') }} <strong>{{ folder.quota !== null ? formatBytes(folder.quota) : t('diskmap', 'Unlimited') }}</strong></span>
			<span class="teamfolder-detail__sep">·</span>
			<span><strong>{{ formatBytes(folder.filesSize) }}</strong> {{ t('diskmap', 'files') }}</span>
			<span class="teamfolder-detail__sep">·</span>
			<span><strong>{{ formatBytes(folder.trashSize) }}</strong> {{ t('diskmap', 'trash') }}</span>
			<template v-if="folder.versionsSize > 0">
				<span class="teamfolder-detail__sep">·</span>
				<span><strong>{{ formatBytes(folder.versionsSize) }}</strong> {{ t('diskmap', 'versions') }}</span>
			</template>
			<template v-if="folder.occupancyPercent !== null">
				<span class="teamfolder-detail__sep">·</span>
				<span><strong>{{ folder.occupancyPercent }}%</strong> {{ t('diskmap', 'occupancy') }}</span>
			</template>
			<template v-if="folder.groups.length">
				<span class="teamfolder-detail__sep">·</span>
				<span>{{ t('diskmap', 'Groups') }}: <strong>{{ groupNames }}</strong></span>
			</template>
			<CategoryLegend
				class="teamfolder-detail__legend"
				:active-category="activeCategory"
				@toggle="onToggleCategory" />
			<button
				type="button"
				class="teamfolder-detail__info"
				:title="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })"
				:aria-label="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })">
				ⓘ
			</button>
		</div>

		<div class="teamfolder-detail__panels">
			<Splitpanes horizontal @resized="onPanesResized">
				<Pane :size="paneSizes[0]" :min-size="15">
					<FolderTree
						ref="folderTree"
						:key="folder.id"
						scope="teamfolder"
						:identifier="folder.id"
						:root-label="folder.name"
						:folder-name="folder.name"
						@select-path="onSelectPath" />
				</Pane>
				<Pane :size="paneSizes[1]" :min-size="15">
					<Treemap
						ref="treemap"
						:key="folder.id"
						scope="teamfolder"
						:identifier="folder.id"
						:folder-name="folder.name"
						:active-category="activeCategory"
						@reveal-path="onRevealPath" />
				</Pane>
			</Splitpanes>
		</div>
	</div>
</template>

<script>
import { Splitpanes, Pane } from 'splitpanes'
import { translate as t } from '@nextcloud/l10n'

import FolderTree from '../components/FolderTree.vue'
import Treemap from '../components/Treemap.vue'
import CategoryLegend from '../components/CategoryLegend.vue'
import { formatBytes, formatDate } from '../utils/format.js'
import { loadPaneSizes, savePaneSizes } from '../utils/panelSplit.js'

export default {
	name: 'TeamFolderDetail',
	components: { Treemap, FolderTree, CategoryLegend, Splitpanes, Pane },
	props: {
		folder: { type: Object, required: true },
	},
	data() {
		return {
			paneSizes: loadPaneSizes(),
			// Owned here (not in Treemap) so the header's <CategoryLegend> and
			// the map below can read/write the same value — they're siblings.
			activeCategory: null,
		}
	},
	computed: {
		lastUpdatedLabel() {
			return this.folder.lastUpdated ? formatDate(this.folder.lastUpdated) : t('diskmap', 'unknown')
		},
		groupNames() {
			return this.folder.groups.map((group) => group.id).join(', ')
		},
	},
	watch: {
		'folder.id'() {
			this.activeCategory = null
		},
	},
	methods: {
		t,
		formatBytes,
		onRevealPath(path) {
			this.$refs.folderTree?.revealPath(path)
		},
		onSelectPath(payload) {
			// See InstanceView: focus and category filter cannot both apply.
			this.activeCategory = null
			this.$refs.treemap?.focusPath(payload)
		},
		onToggleCategory(key) {
			this.activeCategory = this.activeCategory === key ? null : key
		},
		onPanesResized(event) {
			const sizes = event.panes.map((pane) => pane.size)
			this.paneSizes = sizes
			savePaneSizes(sizes)
		},
	},
}
</script>

<style scoped>
.teamfolder-detail {
	height: 100%;
	/* Top padding clears NcAppNavigation's collapse toggle, which sits
	   absolutely positioned at top:8px + 34px tall (--app-navigation-padding
	   + --default-clickable-area) right at this corner. Rather than pushing
	   everything down to clear it (wasting a full row of height), only the
	   header row gets extra *left* padding, so the title sits beside the
	   toggle on the same line instead of below it. Top padding is tuned so
	   the header row's vertical center lines up with the toggle's center. */
	padding: 5px 20px 12px;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.teamfolder-detail__header {
	flex-shrink: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	row-gap: 2px;
	padding-left: 40px;
}

.teamfolder-detail__header h2 {
	margin: 0;
	font-size: 1.3em;
}

.teamfolder-detail__sep {
	color: var(--color-text-maxcontrast);
}

.teamfolder-detail__legend {
	margin-inline-start: auto;
}

.teamfolder-detail__info {
	background: none;
	border: none;
	cursor: help;
	color: var(--color-text-maxcontrast);
	font-size: 1.1em;
	padding: 0 2px;
	align-self: center;
}

/* WinDirStat-style split: tree on top, map below, together filling
   whatever viewport height is left under the header above. No panel
   headings — the tree/map are visually self-explanatory, and every pixel
   here is height the panels don't get. The split ratio is user-draggable
   (splitter styling lives in css/main.css, shared by all three
   views) and persisted via panelSplit.js. */
.teamfolder-detail__panels {
	flex: 1 1 auto;
	min-height: 0;
	margin-top: 6px;
}




</style>
