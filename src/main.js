/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
// Base layout/splitter CSS for the draggable tree↔map divider (TeamFolderDetail.vue,
// MyStorageView.vue) — the NC-themed colors are applied separately via :deep() in
// those views' own scoped styles, this only provides flex/cursor/sizing behavior.
import 'splitpanes/dist/splitpanes.css'

document.addEventListener('DOMContentLoaded', () => {
	const mount = document.getElementById('diskmap')
	if (mount) {
		createApp(App).mount(mount)
	}
})
