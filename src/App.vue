<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcContent app-name="diskmap">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('diskmap', 'My storage')"
					:active="selectedId === MY_STORAGE_ID"
					@click="selectedId = MY_STORAGE_ID" />
				<NcAppNavigationItem
					v-if="isAdmin"
					:name="t('diskmap', 'Whole server')"
					:active="selectedId === INSTANCE_ID"
					@click="selectedId = INSTANCE_ID" />
				<NcAppNavigationItem
					v-for="folder in teamFolders"
					:key="folder.id"
					:name="folder.name"
					:active="selectedId === folder.id"
					@click="selectedId = folder.id">
					<template #counter>
						{{ formatBytes(folder.used) }}
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-for="storage in externalStorages"
					:key="externalNavId(storage)"
					:name="storage.name"
					:active="selectedId === externalNavId(storage)"
					@click="selectedId = externalNavId(storage)">
					<template #counter>
						{{ externalCounter(storage) }}
					</template>
				</NcAppNavigationItem>
				<NcNoteCard v-if="externalLoadError" type="warning">
					{{ t('diskmap', 'Could not load external storages.') }}
				</NcNoteCard>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<MyStorageView v-if="selectedId === MY_STORAGE_ID" :uid="uid" />
			<InstanceView v-else-if="isAdmin && selectedId === INSTANCE_ID" />
			<ExternalStorageDetail
				v-else-if="isAdmin && selectedExternal"
				:storage="selectedExternal" />
			<template v-else-if="isAdmin">
				<NcLoadingIcon v-if="loading" :size="32" />
				<NcNoteCard v-else-if="loadError" type="error">
					{{ t('diskmap', 'Could not load team folders.') }}
				</NcNoteCard>
				<NcEmptyContent v-else-if="!teamFolders.length"
					:name="t('diskmap', 'No team folders found.')" />
				<TeamFolderDetail v-else-if="selectedFolder" :folder="selectedFolder" />
				<NcEmptyContent v-else
					:name="t('diskmap', 'Select a team folder to see details.')" />
			</template>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'

import TeamFolderDetail from './views/TeamFolderDetail.vue'
import ExternalStorageDetail from './views/ExternalStorageDetail.vue'
import MyStorageView from './views/MyStorageView.vue'
import InstanceView from './views/InstanceView.vue'
import { fetchExternalStorages, fetchTeamFolders } from './services/api.js'
import { formatBytes } from './utils/format.js'

// Sentinel nav selections distinct from any team folder id (those are ints).
const MY_STORAGE_ID = '__me__'
const INSTANCE_ID = '__instance__'
// External storages are keyed by their numeric storage id, which collides
// with team folder ids — this prefix keeps the two id spaces apart in the
// single `selectedId` the nav switches on.
const EXTERNAL_PREFIX = 'storage:'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcLoadingIcon,
		NcEmptyContent,
		NcNoteCard,
		TeamFolderDetail,
		ExternalStorageDetail,
		MyStorageView,
		InstanceView,
	},
	data() {
		const user = getCurrentUser()
		return {
			MY_STORAGE_ID,
			INSTANCE_ID,
			uid: user?.uid ?? '',
			isAdmin: user?.isAdmin ?? false,
			teamFolders: [],
			externalStorages: [],
			selectedId: MY_STORAGE_ID,
			loading: true,
			loadError: false,
			externalLoadError: false,
		}
	},
	computed: {
		selectedFolder() {
			return this.teamFolders.find((folder) => folder.id === this.selectedId) ?? null
		},
		selectedExternal() {
			return this.externalStorages.find((storage) => this.externalNavId(storage) === this.selectedId) ?? null
		},
	},
	async mounted() {
		if (!this.isAdmin) {
			this.loading = false
			return
		}
		// Settled, not all(): the two lists are independent sidebar sections
		// and a groupfolders-less instance failing one must not blank the
		// other out of the nav.
		const [teamFolders, externalStorages] = await Promise.allSettled([
			fetchTeamFolders(),
			fetchExternalStorages(),
		])
		this.teamFolders = teamFolders.status === 'fulfilled' ? teamFolders.value : []
		this.loadError = teamFolders.status === 'rejected'
		this.externalStorages = externalStorages.status === 'fulfilled' ? externalStorages.value : []
		this.externalLoadError = externalStorages.status === 'rejected'
		this.loading = false
	},
	methods: {
		t,
		formatBytes,
		externalNavId(storage) {
			return EXTERNAL_PREFIX + storage.storageId
		},
		// Same "≥" convention the tree uses for a storage the file cache
		// hasn't finished measuring — see UsageNode::$sizeExact.
		externalCounter(storage) {
			const label = formatBytes(storage.used)
			return storage.sizeExact === false ? `≥ ${label}` : label
		},
	},
}
</script>
