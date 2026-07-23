<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
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
					v-for="folder in teamFolders"
					:key="folder.id"
					:name="folder.name"
					:active="selectedId === folder.id"
					@click="selectedId = folder.id">
					<template #counter>
						{{ formatBytes(folder.used) }}
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<MyStorageView v-if="selectedId === MY_STORAGE_ID" :uid="uid" />
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
import MyStorageView from './views/MyStorageView.vue'
import { fetchTeamFolders } from './services/api.js'
import { formatBytes } from './utils/format.js'

// Sentinel nav selection distinct from any team folder id (those are ints).
const MY_STORAGE_ID = '__me__'

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
		MyStorageView,
	},
	data() {
		const user = getCurrentUser()
		return {
			MY_STORAGE_ID,
			uid: user?.uid ?? '',
			isAdmin: user?.isAdmin ?? false,
			teamFolders: [],
			selectedId: MY_STORAGE_ID,
			loading: true,
			loadError: false,
		}
	},
	computed: {
		selectedFolder() {
			return this.teamFolders.find((folder) => folder.id === this.selectedId) ?? null
		},
	},
	async mounted() {
		if (!this.isAdmin) {
			this.loading = false
			return
		}
		try {
			this.teamFolders = await fetchTeamFolders()
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
