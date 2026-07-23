<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="largest-files-panel">
		<h3>{{ t('diskmap', 'Largest files') }}</h3>
		<NcLoadingIcon v-if="loading" :size="24" />
		<NcNoteCard v-else-if="error" type="error">
			{{ t('diskmap', 'Could not load the largest files.') }}
		</NcNoteCard>
		<NcEmptyContent v-else-if="!items.length" :name="t('diskmap', 'No files found.')" />
		<table v-else class="largest-files-panel__table">
			<thead>
				<tr>
					<th>{{ t('diskmap', 'Path') }}</th>
					<th>{{ t('diskmap', 'Size') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="item in items" :key="item.path">
					<td>{{ item.path }}</td>
					<td>{{ formatBytes(item.size) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import { fetchLargest } from '../services/api.js'
import { formatBytes } from '../utils/format.js'

export default {
	name: 'LargestFilesPanel',
	components: { NcLoadingIcon, NcEmptyContent, NcNoteCard },
	props: {
		scope: { type: String, required: true },
		identifier: { type: [String, Number], required: true },
		limit: { type: Number, default: 20 },
	},
	data() {
		return {
			items: [],
			loading: true,
			error: false,
		}
	},
	watch: {
		scope: 'load',
		identifier: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		formatBytes,
		async load() {
			this.loading = true
			this.error = false
			try {
				const data = await fetchLargest(this.scope, this.identifier, { limit: this.limit })
				this.items = data.items
			} catch (e) {
				this.error = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.largest-files-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.largest-files-panel__table th,
.largest-files-panel__table td {
	text-align: left;
	padding: 6px 12px 6px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
