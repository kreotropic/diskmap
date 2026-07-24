<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="instance-view">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ t('diskmap', 'Could not load the instance overview.') }}
		</NcNoteCard>

		<template v-else>
			<div class="instance-view__header">
				<h2>{{ t('diskmap', 'Whole server') }}</h2>
				<span class="instance-view__sep">·</span>
				<span><strong>{{ formatBytes(usedSize) }}</strong> {{ t('diskmap', 'used') }}</span>
				<button
					type="button"
					class="instance-view__info"
					:title="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })"
					:aria-label="t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel })">
					ⓘ
				</button>
			</div>

			<div class="instance-view__panels">
				<Splitpanes horizontal @resized="onPanesResized">
					<Pane :size="paneSizes[0]" :min-size="15">
						<FolderTree
							ref="folderTree"
							scope="instance"
							identifier=""
							:root-label="t('diskmap', 'Whole server')"
							@select-path="onSelectPath" />
					</Pane>
					<Pane :size="paneSizes[1]" :min-size="15">
						<Treemap ref="treemap" scope="instance" identifier="" @reveal-path="onRevealPath" />
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
import { fetchMap } from '../services/api.js'
import { formatBytes, formatDate } from '../utils/format.js'
import { loadPaneSizes, savePaneSizes } from '../utils/panelSplit.js'

// Cheap enough to size the header without paying for a full-budget
// expansion — instanceMapTree() always populates every top-level entry
// (user/team folder) regardless of maxNodes, so root.size is accurate even
// at a small budget; only how deep it recurses past the top level shrinks.
const HEADER_MAX_NODES = 30

export default {
	name: 'InstanceView',
	components: { NcLoadingIcon, NcNoteCard, Treemap, FolderTree, Splitpanes, Pane },
	data() {
		return {
			usedSize: 0,
			lastUpdated: null,
			loading: true,
			loadError: false,
			paneSizes: loadPaneSizes(),
		}
	},
	computed: {
		lastUpdatedLabel() {
			return this.lastUpdated ? formatDate(this.lastUpdated) : t('diskmap', 'unknown')
		},
	},
	async mounted() {
		try {
			const data = await fetchMap('instance', '', { maxNodes: HEADER_MAX_NODES })
			this.usedSize = data.root?.size ?? 0
			this.lastUpdated = data.lastUpdated
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
		onPanesResized(event) {
			const sizes = event.panes.map((pane) => pane.size)
			this.paneSizes = sizes
			savePaneSizes(sizes)
		},
	},
}
</script>

<style scoped>
.instance-view {
	height: 100%;
	/* Same header-alignment reasoning as MyStorageView.vue/TeamFolderDetail.vue —
	   see the comments there for the NcAppNavigation toggle-button math. */
	padding: 5px 20px 12px;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.instance-view__header {
	flex-shrink: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	row-gap: 2px;
	padding-left: 40px;
}

.instance-view__header h2 {
	margin: 0;
	font-size: 1.3em;
}

.instance-view__sep {
	color: var(--color-text-maxcontrast);
}

.instance-view__info {
	margin-inline-start: auto;
	background: none;
	border: none;
	cursor: help;
	color: var(--color-text-maxcontrast);
	font-size: 1.1em;
	padding: 0 2px;
	align-self: center;
}

.instance-view__panels {
	flex: 1 1 auto;
	min-height: 0;
	margin-top: 6px;
}

.instance-view__panels :deep(.splitpanes__pane) {
	background: transparent;
}

.instance-view__panels :deep(.splitpanes--horizontal > .splitpanes__splitter) {
	position: relative;
	height: 8px;
	margin-top: -1px;
	border-top: 1px solid var(--color-border);
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	cursor: row-resize;
}

.instance-view__panels :deep(.splitpanes--horizontal > .splitpanes__splitter:hover) {
	background: var(--color-background-hover);
}

.instance-view__panels :deep(.splitpanes--horizontal > .splitpanes__splitter::before) {
	content: '';
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	width: 32px;
	height: 3px;
	border-radius: 2px;
	background: var(--color-border-dark);
}
</style>
