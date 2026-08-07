<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="external-detail">
		<div class="external-detail__header">
			<h2>{{ storage.name }}</h2>
			<span class="external-detail__sep">·</span>
			<span>
				<strong>{{ usedLabel }}</strong> {{ t('diskmap', 'used') }}
			</span>
			<CategoryLegend
				class="external-detail__legend"
				:active-category="activeCategory"
				@toggle="onToggleCategory" />
			<button
				type="button"
				class="external-detail__info"
				:title="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })"
				:aria-label="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })">
				ⓘ
			</button>
		</div>

		<!-- An external storage is the one scope whose contents Nextcloud
		     reads live from a remote backend but only *measures* when
		     something scans it. Until then the file cache legitimately knows
		     less than the Files app shows, and saying so here is the whole
		     difference between "this mount is empty" and "nobody has counted
		     it yet".

		     Deliberately phrased as "not yet" rather than as an instruction:
		     core's own ScanFiles background job already targets exactly these
		     rows (size -1 with a parent, joined against oc_mounts) and does
		     not skip external mounts, so on an instance with working cron
		     this resolves itself. The occ command is the way to force it now,
		     not a chore the admin has been left with. -->
		<NcNoteCard v-if="storage.sizeExact === false" type="info" class="external-detail__notice">
			{{ t('diskmap', 'This storage has not been fully scanned yet, so sizes are a lower bound. Background scanning fills this in over time; "occ files_external:scan" measures it right away.') }}
		</NcNoteCard>

		<div class="external-detail__panels">
			<Splitpanes horizontal @resized="onPanesResized">
				<Pane :size="paneSizes[0]" :min-size="15">
					<FolderTree
						ref="folderTree"
						:key="storage.storageId"
						scope="storage"
						:identifier="storage.storageId"
						:root-label="storage.name"
						:folder-name="storage.name"
						@select-path="onSelectPath" />
				</Pane>
				<Pane :size="paneSizes[1]" :min-size="15">
					<Treemap
						ref="treemap"
						:key="storage.storageId"
						scope="storage"
						:identifier="storage.storageId"
						:folder-name="storage.name"
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
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import FolderTree from '../components/FolderTree.vue'
import Treemap from '../components/Treemap.vue'
import CategoryLegend from '../components/CategoryLegend.vue'
import { formatBytes, formatDate } from '../utils/format.js'
import { loadPaneSizes, savePaneSizes } from '../utils/panelSplit.js'

export default {
	name: 'ExternalStorageDetail',
	components: { Treemap, FolderTree, CategoryLegend, NcNoteCard, Splitpanes, Pane },
	props: {
		storage: { type: Object, required: true },
	},
	data() {
		return {
			paneSizes: loadPaneSizes(),
			// Owned here rather than in Treemap for the same reason
			// TeamFolderDetail owns it: the header's <CategoryLegend> and the
			// map are siblings and must read/write one value.
			activeCategory: null,
		}
	},
	computed: {
		usedLabel() {
			const label = formatBytes(this.storage.used)
			return this.storage.sizeExact === false ? `≥ ${label}` : label
		},
		lastUpdatedLabel() {
			return this.storage.lastUpdated ? formatDate(this.storage.lastUpdated) : t('diskmap', 'unknown')
		},
	},
	watch: {
		'storage.storageId'() {
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
/* Mirrors TeamFolderDetail's layout exactly — see the comments there for why
   the header row carries its own left padding instead of the whole view
   being pushed down to clear NcAppNavigation's collapse toggle. */
.external-detail {
	height: 100%;
	padding: 5px 20px 12px;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.external-detail__header {
	flex-shrink: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	row-gap: 2px;
	padding-left: 40px;
}

.external-detail__header h2 {
	margin: 0;
	font-size: 1.3em;
}

.external-detail__sep {
	color: var(--color-text-maxcontrast);
}

.external-detail__legend {
	margin-inline-start: auto;
}

.external-detail__info {
	background: none;
	border: none;
	cursor: help;
	color: var(--color-text-maxcontrast);
	font-size: 1.1em;
	padding: 0 2px;
	align-self: center;
}

.external-detail__notice {
	flex-shrink: 0;
	margin: 4px 0 0;
}

.external-detail__panels {
	flex: 1 1 auto;
	min-height: 0;
	margin-top: 6px;
}
</style>
