<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="dm-segmented" role="group" :aria-label="label">
		<button
			v-for="option in options"
			:key="option.value"
			type="button"
			class="dm-segmented__item"
			:class="{ 'dm-segmented__item--active': option.value === modelValue }"
			:aria-pressed="option.value === modelValue ? 'true' : 'false'"
			@click="$emit('update:modelValue', option.value)">
			{{ option.label }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'SegmentedToggle',
	emits: ['update:modelValue'],
	props: {
		modelValue: { type: String, required: true },
		// [{ value, label }] — two or three short choices; this is sized to
		// sit inside a header row, not to hold a long list.
		options: { type: Array, required: true },
		// Accessible name for the group (the buttons' own labels say what
		// each choice is, this says what is being chosen).
		label: { type: String, default: '' },
	},
}
</script>

<style scoped>
/* Every rule on the items is scoped under .dm-segmented: core's server.css
   gives every <button> margin: 3px through a selector (button:not(...):not(...))
   that outranks a lone class, which left a gap around the active segment.

   Deliberately small — the same height as the header's legend chips — so it
   can share the header row rather than costing the panels a row of their own. */
.dm-segmented {
	display: inline-flex;
	flex-shrink: 0;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-pill, 16px);
	overflow: hidden;
}

.dm-segmented .dm-segmented__item {
	margin: 0;
	min-height: 0;
	padding: 2px 10px;
	border: none;
	border-radius: 0;
	background: none;
	color: var(--color-main-text);
	font-size: 0.8em;
	font-weight: normal;
	line-height: 1.5;
	white-space: nowrap;
	cursor: pointer;
}

.dm-segmented .dm-segmented__item + .dm-segmented__item {
	border-inline-start: 1px solid var(--color-border-dark, var(--color-border));
}

.dm-segmented .dm-segmented__item:hover {
	background: var(--color-background-hover);
}

.dm-segmented .dm-segmented__item--active,
.dm-segmented .dm-segmented__item--active:hover {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
