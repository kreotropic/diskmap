<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="dm-legend">
		<button
			v-for="entry in entries"
			:key="entry.key"
			type="button"
			class="dm-legend__item"
			:class="{ 'dm-legend__item--dimmed': activeCategory && activeCategory !== entry.key }"
			@click="$emit('toggle', entry.key)">
			<span class="dm-legend__swatch" :style="{ background: entry.color }" />
			{{ entry.label }}
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { CATEGORY_DOCUMENT, CATEGORY_IMAGE, CATEGORY_VIDEO, CATEGORY_ARCHIVE, CATEGORY_OTHER } from '../utils/mimetypeCategory.js'

export default {
	name: 'CategoryLegend',
	emits: ['toggle'],
	props: {
		// The currently-filtered category (or null) — a controlled component,
		// same "state lives in the parent view" pattern as Treemap's own
		// activeCategory prop; both read/write the SAME value so clicking the
		// legend (wherever it's rendered) and the treemap dim in sync.
		activeCategory: { type: String, default: null },
	},
	computed: {
		entries() {
			return [
				{ key: CATEGORY_DOCUMENT, label: t('diskmap', 'Documents'), color: 'var(--dm-cat-document)' },
				{ key: CATEGORY_IMAGE, label: t('diskmap', 'Images'), color: 'var(--dm-cat-image)' },
				{ key: CATEGORY_VIDEO, label: t('diskmap', 'Video'), color: 'var(--dm-cat-video)' },
				{ key: CATEGORY_ARCHIVE, label: t('diskmap', 'Archives & installers'), color: 'var(--dm-cat-archive)' },
				{ key: CATEGORY_OTHER, label: t('diskmap', 'Other'), color: 'var(--dm-cat-other)' },
			]
		},
	},
}
</script>

<style scoped>
.dm-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

.dm-legend__item {
	display: flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	cursor: pointer;
	padding: 1px 3px;
	color: var(--color-main-text);
	font-size: 0.78em;
	white-space: nowrap;
	opacity: 1;
	transition: opacity 0.15s;
}

.dm-legend__item--dimmed {
	opacity: 0.4;
}

.dm-legend__swatch {
	width: 9px;
	height: 9px;
	border-radius: 2px;
	display: inline-block;
	flex-shrink: 0;
}
</style>
