<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<SegmentedToggle
		:model-value="modelValue"
		:options="options"
		:label="t('diskmap', 'Lower panel view')"
		@update:model-value="onChange" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import SegmentedToggle from './SegmentedToggle.vue'
import { saveLowerView } from '../utils/panelSplit.js'

/**
 * Header switch between the treemap and the file age bars in the lower pane.
 * Lives in the header row rather than above the pane, so having a second
 * view costs the map no height at all.
 */
export default {
	name: 'LowerViewToggle',
	components: { SegmentedToggle },
	emits: ['update:modelValue'],
	props: {
		// 'map' | 'ages' — owned by the view, initialised from loadLowerView().
		modelValue: { type: String, required: true },
	},
	computed: {
		options() {
			return [
				{ value: 'map', label: t('diskmap', 'Map') },
				{ value: 'ages', label: t('diskmap', 'File age') },
			]
		},
	},
	methods: {
		t,
		onChange(view) {
			saveLowerView(view)
			this.$emit('update:modelValue', view)
		},
	},
}
</script>
