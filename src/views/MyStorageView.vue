<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="my-storage">
		<h2>{{ t('diskmap', 'My storage') }}</h2>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ t('diskmap', 'Could not load your storage overview.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard type="info" class="my-storage__last-updated">
				{{ t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel }) }}
			</NcNoteCard>

			<dl class="my-storage__stats">
				<div class="stat">
					<dt>{{ t('diskmap', 'Used') }}</dt>
					<dd>{{ formatBytes(overview.used) }}</dd>
				</div>
				<div class="stat">
					<dt>{{ t('diskmap', 'Quota') }}</dt>
					<dd>{{ overview.quota !== null ? formatBytes(overview.quota) : t('diskmap', 'Unlimited') }}</dd>
				</div>
				<div v-if="overview.occupancyPercent !== null" class="stat">
					<dt>{{ t('diskmap', 'Occupancy') }}</dt>
					<dd>{{ overview.occupancyPercent }}%</dd>
				</div>
				<div class="stat">
					<dt>{{ t('diskmap', 'Files') }}</dt>
					<dd>{{ formatBytes(overview.filesSize) }}</dd>
				</div>
				<div class="stat">
					<dt>{{ t('diskmap', 'Trash') }}</dt>
					<dd>{{ formatBytes(overview.trashSize) }}</dd>
				</div>
				<div class="stat">
					<dt>{{ t('diskmap', 'Versions') }}</dt>
					<dd>{{ formatBytes(overview.versionsSize) }}</dd>
				</div>
			</dl>

			<LargestFilesPanel scope="user" :identifier="uid" />
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import LargestFilesPanel from '../components/LargestFilesPanel.vue'
import { fetchMyOverview } from '../services/api.js'
import { formatBytes, formatDate } from '../utils/format.js'

export default {
	name: 'MyStorageView',
	components: { NcLoadingIcon, NcNoteCard, LargestFilesPanel },
	props: {
		uid: { type: String, required: true },
	},
	data() {
		return {
			overview: null,
			loading: true,
			loadError: false,
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
	},
}
</script>

<style scoped>
.my-storage {
	padding: 20px;
	max-width: 900px;
}

.my-storage__last-updated {
	margin-bottom: 16px;
}

.my-storage__stats {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 0 0 24px;
}

.my-storage__stats .stat {
	min-width: 120px;
}

.my-storage__stats dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.my-storage__stats dd {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
}
</style>
