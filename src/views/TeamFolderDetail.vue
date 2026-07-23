<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="teamfolder-detail">
		<h2>{{ folder.name }}</h2>

		<NcNoteCard type="info" class="teamfolder-detail__last-updated">
			{{ t('diskmap', 'Reflects the file cache as of {date}.', { date: lastUpdatedLabel }) }}
		</NcNoteCard>

		<dl class="teamfolder-detail__stats">
			<div class="stat">
				<dt>{{ t('diskmap', 'Used') }}</dt>
				<dd>{{ formatBytes(folder.used) }}</dd>
			</div>
			<div class="stat">
				<dt>{{ t('diskmap', 'Quota') }}</dt>
				<dd>{{ folder.quota !== null ? formatBytes(folder.quota) : t('diskmap', 'Unlimited') }}</dd>
			</div>
			<div v-if="folder.occupancyPercent !== null" class="stat">
				<dt>{{ t('diskmap', 'Occupancy') }}</dt>
				<dd>{{ folder.occupancyPercent }}%</dd>
			</div>
			<div class="stat">
				<dt>{{ t('diskmap', 'Files') }}</dt>
				<dd>{{ formatBytes(folder.filesSize) }}</dd>
			</div>
			<div class="stat">
				<dt>{{ t('diskmap', 'Trash') }}</dt>
				<dd>{{ formatBytes(folder.trashSize) }}</dd>
			</div>
			<div class="stat">
				<dt>{{ t('diskmap', 'Versions') }}</dt>
				<dd>{{ formatBytes(folder.versionsSize) }}</dd>
			</div>
		</dl>

		<h3>{{ t('diskmap', 'Groups') }}</h3>
		<ul v-if="folder.groups.length" class="teamfolder-detail__groups">
			<li v-for="group in folder.groups" :key="group.type + ':' + group.id">
				{{ group.type === 'circle' ? t('diskmap', 'Team') : t('diskmap', 'Group') }}: {{ group.id }}
			</li>
		</ul>
		<p v-else>
			{{ t('diskmap', 'Not linked to any group.') }}
		</p>

		<LargestFilesPanel scope="teamfolder" :identifier="folder.id" />
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import LargestFilesPanel from '../components/LargestFilesPanel.vue'
import { formatBytes, formatDate } from '../utils/format.js'

export default {
	name: 'TeamFolderDetail',
	components: { NcNoteCard, LargestFilesPanel },
	props: {
		folder: { type: Object, required: true },
	},
	computed: {
		lastUpdatedLabel() {
			return this.folder.lastUpdated ? formatDate(this.folder.lastUpdated) : t('diskmap', 'unknown')
		},
	},
	methods: {
		t,
		formatBytes,
	},
}
</script>

<style scoped>
.teamfolder-detail {
	padding: 20px;
	max-width: 900px;
}

.teamfolder-detail__last-updated {
	margin-bottom: 16px;
}

.teamfolder-detail__stats {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 0 0 24px;
}

.teamfolder-detail__stats .stat {
	min-width: 120px;
}

.teamfolder-detail__stats dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.teamfolder-detail__stats dd {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
}

.teamfolder-detail__groups {
	list-style: none;
	padding: 0;
}
</style>
