<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="my-storage">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ t('diskmap', 'Could not load your storage overview.') }}
		</NcNoteCard>

		<template v-else>
			<div class="my-storage__header">
				<h2>{{ t('diskmap', 'My storage') }}</h2>
				<span class="my-storage__sep">·</span>
				<span><strong>{{ formatBytes(overview.used) }}</strong> {{ t('diskmap', 'used') }}</span>
				<span class="my-storage__sep">·</span>
				<span>{{ t('diskmap', 'Quota') }} <strong>{{ overview.quota !== null ? formatBytes(overview.quota) : t('diskmap', 'Unlimited') }}</strong></span>
				<span class="my-storage__sep">·</span>
				<span><strong>{{ formatBytes(overview.filesSize) }}</strong> {{ t('diskmap', 'files') }}</span>
				<span class="my-storage__sep">·</span>
				<span><strong>{{ formatBytes(overview.trashSize) }}</strong> {{ t('diskmap', 'trash') }}</span>
				<template v-if="overview.versionsSize > 0">
					<span class="my-storage__sep">·</span>
					<span><strong>{{ formatBytes(overview.versionsSize) }}</strong> {{ t('diskmap', 'versions') }}</span>
				</template>
				<template v-if="overview.occupancyPercent !== null">
					<span class="my-storage__sep">·</span>
					<span><strong>{{ overview.occupancyPercent }}%</strong> {{ t('diskmap', 'occupancy') }}</span>
				</template>
				<CategoryLegend
					class="my-storage__legend"
					:active-category="activeCategory"
					@toggle="onToggleCategory" />
				<button
					type="button"
					class="my-storage__info"
					:title="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })"
					:aria-label="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })">
					ⓘ
				</button>
			</div>

			<div class="my-storage__panels">
				<Splitpanes horizontal @resized="onPanesResized">
					<Pane :size="paneSizes[0]" :min-size="15">
						<FolderTree
							ref="folderTree"
							scope="user"
							:identifier="uid"
							:root-label="t('diskmap', 'My storage')"
							@select-path="onSelectPath" />
					</Pane>
					<Pane :size="paneSizes[1]" :min-size="15">
						<Treemap
							ref="treemap"
							scope="user"
							:identifier="uid"
							:active-category="activeCategory"
							@reveal-path="onRevealPath" />
					</Pane>
				</Splitpanes>
			</div>
		</template>
	</div>
</template>

<script>
import { Splitpanes, Pane } from 'splitpanes'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import FolderTree from '../components/FolderTree.vue'
import Treemap from '../components/Treemap.vue'
import CategoryLegend from '../components/CategoryLegend.vue'
import { fetchMyOverview } from '../services/api.js'
import { formatBytes, formatDate } from '../utils/format.js'
import { loadPaneSizes, savePaneSizes } from '../utils/panelSplit.js'

export default {
	name: 'MyStorageView',
	components: { NcLoadingIcon, NcNoteCard, Treemap, FolderTree, CategoryLegend, Splitpanes, Pane },
	props: {
		uid: { type: String, required: true },
	},
	data() {
		return {
			overview: null,
			loading: true,
			loadError: false,
			paneSizes: loadPaneSizes(),
			// Owned here (not in Treemap) so the header's <CategoryLegend> and
			// the map below can read/write the same value — they're siblings.
			activeCategory: null,
		}
	},
	computed: {
		lastUpdatedLabel() {
			return this.overview?.lastUpdated ? formatDate(this.overview.lastUpdated) : t('diskmap', 'unknown')
		},
	},
	async mounted() {
		try {
			this.overview = await fetchMyOverview()
		} catch (e) {
			this.loadError = true
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		formatBytes,
		onRevealPath(path) {
			this.$refs.folderTree?.revealPath(path)
		},
		onSelectPath(payload) {
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
.my-storage {
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

.my-storage__header {
	flex-shrink: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	row-gap: 2px;
	padding-left: 40px;
}

.my-storage__header h2 {
	margin: 0;
	font-size: 1.3em;
}

.my-storage__sep {
	color: var(--color-text-maxcontrast);
}

.my-storage__legend {
	margin-inline-start: auto;
}

.my-storage__info {
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
.my-storage__panels {
	flex: 1 1 auto;
	min-height: 0;
	margin-top: 6px;
}




</style>
