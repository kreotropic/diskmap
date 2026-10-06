<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="dm-ages">
		<div class="dm-ages__bar">
			<span v-if="data" class="dm-ages__summary">
				<strong>{{ formatCount(data.total) }}</strong> {{ t('diskmap', 'files') }}
				<span class="dm-ages__sep">·</span>
				<strong>{{ formatBytes(totalSize) }}</strong>
				<span v-if="folderPath" class="dm-ages__path" :title="'/' + folderPath">/{{ folderPath }}</span>
			</span>
			<SegmentedToggle
				v-model="metric"
				class="dm-ages__metric"
				:label="t('diskmap', 'Files by age')"
				:options="metricOptions" />
		</div>

		<NcLoadingIcon v-if="loading && !data" :size="32" />
		<NcNoteCard v-if="error" type="error">
			{{ t('diskmap', 'Could not load the file ages.') }}
		</NcNoteCard>
		<NcEmptyContent v-if="!loading && !error && data && data.total === 0" :name="t('diskmap', 'No files found.')" />

		<!-- Drawn at the element's real pixel size (ResizeObserver, same as
			 Treemap.vue) rather than a stretched viewBox, so the text and the
			 rounded bar ends never distort as the split pane is dragged. -->
		<svg
			v-show="data && data.total > 0"
			ref="canvas"
			class="dm-ages__canvas"
			:class="{ 'dm-ages__canvas--loading': loading }"
			:viewBox="`0 0 ${width} ${height}`"
			role="img"
			:aria-label="t('diskmap', 'Files by age')">
			<g v-for="row in rows" :key="row.index">
				<title>{{ row.tooltip }}</title>
				<text
					class="dm-ages__label"
					:x="LABEL_WIDTH"
					:y="row.cy"
					text-anchor="end"
					dominant-baseline="central"
					:font-size="textSize">
					{{ row.label }}
				</text>
				<rect
					class="dm-ages__track"
					:x="barX"
					:y="row.y"
					:width="barMaxWidth"
					:height="barHeight"
					:rx="barRadius" />
				<rect
					class="dm-ages__fill"
					:x="barX"
					:y="row.y"
					:width="row.width"
					:height="barHeight"
					:rx="barRadius"
					:fill-opacity="row.opacity" />
				<text
					class="dm-ages__value"
					:x="barX + row.width + 8"
					:y="row.cy"
					dominant-baseline="central"
					:font-size="textSize">
					{{ row.value }}<tspan class="dm-ages__percent"> · {{ row.percent }}</tspan>
				</text>
			</g>
			<!-- Share of the total per bucket, as a donut in the room right of
				 the bars — same colors as the bars, so no legend of its own. -->
			<g v-if="donut" :transform="`translate(${donut.cx} ${donut.cy})`">
				<circle class="dm-ages__ring" :r="donut.r" :stroke-width="donut.thickness" />
				<circle
					v-for="segment in donut.segments"
					:key="segment.index"
					class="dm-ages__segment"
					:r="donut.r"
					:stroke-width="donut.thickness"
					:stroke-dasharray="`${segment.length} ${donut.circumference}`"
					:stroke-dashoffset="-segment.offset"
					:stroke-opacity="segment.opacity"
					transform="rotate(-90)">
					<title>{{ segment.tooltip }}</title>
				</circle>
				<text class="dm-ages__donut-total" text-anchor="middle" dominant-baseline="central" :font-size="donut.totalSize" :y="metric === 'count' ? -donut.unitSize * 0.6 : 0">
					{{ donut.total }}
				</text>
				<text v-if="metric === 'count'" class="dm-ages__donut-unit" text-anchor="middle" dominant-baseline="central" :font-size="donut.unitSize" :y="donut.totalSize * 0.75">
					{{ t('diskmap', 'files') }}
				</text>
			</g>
		</svg>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'

import SegmentedToggle from './SegmentedToggle.vue'
import { fetchFileAges } from '../services/api.js'
import { formatBytes, formatCount } from '../utils/format.js'

// Room for the longest bucket label ("6-10 yrs") left of the bars, and for
// "1.2 GB · 18%" right of the longest bar.
const LABEL_WIDTH = 72
const VALUE_WIDTH = 120
const GAP = 10
// Rows split the pane's height evenly, so the chart grows and shrinks with
// the split; only a floor, so a very short pane still draws something.
const MIN_ROW = 10
// The donut only shows when the pane is wide enough to keep the bars
// readable beside it; on a narrow pane the percentages on the bars suffice.
const DONUT_MIN_PANE = 560
const DONUT_MIN = 48
const DONUT_GAP = 24
// Newest bucket fully saturated, each older one fainter — the bars read as
// "fading with age" without needing a second palette next to the category one.
const BUCKET_OPACITY = [1, 0.8, 0.62, 0.46, 0.32]

export default {
	name: 'FileAgePanel',
	components: { NcLoadingIcon, NcEmptyContent, NcNoteCard, SegmentedToggle },
	props: {
		scope: { type: String, required: true },
		identifier: { type: [String, Number], required: true },
		// The tree's current selection ({ path, type }) or null for the root
		// — the same payload the views already keep for "Open in Files".
		selection: { type: Object, default: null },
		activeCategory: { type: String, default: null },
	},
	data() {
		return {
			LABEL_WIDTH,
			data: null,
			loading: true,
			error: false,
			metric: 'count',
			// Staleness guard, same pattern as Treemap's: a quick run of
			// tree clicks must end on the last folder asked for, not on
			// whichever response arrived last.
			loadToken: 0,
			width: 600,
			height: 200,
		}
	},
	computed: {
		// Ages are a property of a subtree, so a selected file stands for the
		// folder it sits in rather than narrowing the chart to one file.
		folderPath() {
			if (!this.selection) {
				return ''
			}
			const { path, type } = this.selection
			if (type === 'file') {
				return path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : ''
			}
			return path
		},
		metricOptions() {
			return [
				{ value: 'count', label: t('diskmap', 'File count') },
				{ value: 'size', label: t('diskmap', 'Size') },
			]
		},
		labels() {
			return [t('diskmap', '≤ 1 yr'), t('diskmap', '1-3 yrs'), t('diskmap', '3-6 yrs'), t('diskmap', '6-10 yrs'), t('diskmap', '> 10 yrs')]
		},
		totalSize() {
			return this.data ? this.data.sizes.reduce((a, b) => a + b, 0) : 0
		},
		barX() {
			return LABEL_WIDTH + GAP
		},
		// Side of the donut's square: as tall as the bar block, capped, and
		// never more than a third of the width.
		donutSize() {
			if (this.width < DONUT_MIN_PANE) {
				return 0
			}
			const size = Math.round(Math.min(this.rowHeight * this.labels.length, this.width / 3))
			return size >= DONUT_MIN ? size : 0
		},
		// Room kept right of the value labels for the donut, with the same
		// margin on both of its sides so it sits centred in that column.
		donutSpace() {
			return this.donutSize ? this.donutSize + 2 * DONUT_GAP : 0
		},
		barMaxWidth() {
			return Math.max(0, this.width - this.barX - VALUE_WIDTH - this.donutSpace)
		},
		rowHeight() {
			return Math.max(MIN_ROW, this.height / this.labels.length)
		},
		barHeight() {
			return Math.max(4, Math.round(this.rowHeight * 0.7))
		},
		barRadius() {
			return Math.min(6, this.barHeight / 2)
		},
		// Label/value text follows the row height between readable bounds.
		textSize() {
			return Math.round(Math.min(14, Math.max(10, this.rowHeight * 0.4)))
		},
		rows() {
			if (!this.data) {
				return []
			}
			const values = this.metric === 'size' ? this.data.sizes : this.data.buckets
			const total = values.reduce((a, b) => a + b, 0)
			const max = Math.max(1, ...values)
			return values.map((value, index) => {
				const y = Math.round(index * this.rowHeight + (this.rowHeight - this.barHeight) / 2)
				return {
					index,
					label: this.labels[index],
					y,
					cy: y + this.barHeight / 2,
					// A non-empty bucket keeps a sliver so it never reads as zero.
					width: value > 0 ? Math.max(2, (value / max) * this.barMaxWidth) : 0,
					opacity: BUCKET_OPACITY[index],
					value: this.metric === 'size' ? formatBytes(value) : formatCount(value),
					percent: this.formatPercent(value, total),
					tooltip: `${this.labels[index]}: ${formatCount(this.data.buckets[index])} ${t('diskmap', 'files')} · ${formatBytes(this.data.sizes[index])}`,
				}
			})
		},
		donut() {
			if (!this.data || !this.donutSize) {
				return null
			}
			const values = this.metric === 'size' ? this.data.sizes : this.data.buckets
			const total = values.reduce((a, b) => a + b, 0)
			if (!total) {
				return null
			}
			const thickness = Math.max(10, Math.round(this.donutSize * 0.16))
			const r = (this.donutSize - thickness) / 2
			const circumference = 2 * Math.PI * r
			// A hairline between neighbouring segments, dropped for slivers
			// that would otherwise vanish into the gap.
			const gap = values.filter((v) => v > 0).length > 1 ? 2 : 0
			let offset = 0
			const segments = []
			values.forEach((value, index) => {
				const full = (value / total) * circumference
				if (full > 0) {
					segments.push({
						index,
						length: Math.max(0.5, full - gap),
						offset,
						opacity: BUCKET_OPACITY[index],
						tooltip: `${this.labels[index]}: ${this.formatPercent(value, total)}`,
					})
				}
				offset += full
			})
			return {
				cx: this.width - this.donutSpace / 2,
				// Centred on the bar block, which can be taller than the donut.
				cy: (this.rowHeight * this.labels.length) / 2,
				r,
				thickness,
				circumference,
				segments,
				total: this.metric === 'size' ? formatBytes(total) : formatCount(total),
				totalSize: Math.round(Math.min(26, Math.max(11, this.donutSize * 0.085))),
				unitSize: Math.round(Math.min(15, Math.max(9, this.donutSize * 0.055))),
			}
		},
	},
	watch: {
		scope() {
			this.load()
		},
		identifier() {
			this.load()
		},
		folderPath() {
			this.load()
		},
		activeCategory() {
			this.load()
		},
	},
	mounted() {
		this.observeSize()
		this.load()
	},
	beforeUnmount() {
		this.resizeObserver?.disconnect()
	},
	methods: {
		t,
		formatBytes,
		formatCount,
		async load() {
			const token = ++this.loadToken
			this.loading = true
			this.error = false
			try {
				const data = await fetchFileAges(this.scope, this.identifier, this.activeCategory, { path: this.folderPath })
				if (token !== this.loadToken) {
					return
				}
				this.data = data
			} catch (e) {
				if (token !== this.loadToken) {
					return
				}
				console.error('[diskmap] file ages load failed', e)
				this.error = true
				this.data = null
			} finally {
				if (token === this.loadToken) {
					this.loading = false
				}
			}
		},
		observeSize() {
			const el = this.$refs.canvas
			if (!el || typeof ResizeObserver === 'undefined') {
				return
			}
			this.resizeObserver = new ResizeObserver((entries) => {
				const entry = entries[0]
				if (!entry) {
					return
				}
				const { width, height } = entry.contentRect
				if (width > 0 && height > 0) {
					this.width = Math.round(width)
					this.height = Math.round(height)
				}
			})
			this.resizeObserver.observe(el)
		},
		formatPercent(value, total) {
			if (!total || !value) {
				return '0%'
			}
			const pct = (value / total) * 100
			return pct < 1 ? '<1%' : `${Math.round(pct)}%`
		},
	},
}
</script>

<style scoped>
.dm-ages {
	height: 100%;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	min-height: 0;
}

/* One slim row: what the bars add up to on the left, what they measure on
   the right. This is the only chrome the panel has. */
.dm-ages__bar {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
	font-size: 0.9em;
}

.dm-ages__summary {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dm-ages__sep,
.dm-ages__path {
	color: var(--color-text-maxcontrast);
}

/* Vue drops the whitespace between these elements (they sit on separate
   template lines), so the spacing has to come from the styles. */
.dm-ages__sep {
	margin: 0 4px;
}

.dm-ages__path {
	margin-inline-start: 6px;
}

.dm-ages__metric {
	margin-inline-start: auto;
}

.dm-ages__canvas {
	width: 100%;
	flex: 1 1 auto;
	min-height: 0;
	overflow: visible;
}

.dm-ages__canvas--loading {
	opacity: 0.5;
	transition: opacity 0.15s;
}

.dm-ages__label,
.dm-ages__value {
	fill: var(--color-main-text);
}

.dm-ages__percent {
	fill: var(--color-text-maxcontrast);
}

.dm-ages__track {
	fill: var(--color-background-dark);
}

.dm-ages__fill {
	fill: var(--color-primary-element);
	transition: width 0.2s;
}

.dm-ages__ring,
.dm-ages__segment {
	fill: none;
}

.dm-ages__ring {
	stroke: var(--color-background-dark);
}

.dm-ages__segment {
	stroke: var(--color-primary-element);
}

.dm-ages__donut-total {
	fill: var(--color-main-text);
	font-weight: bold;
}

.dm-ages__donut-unit {
	fill: var(--color-text-maxcontrast);
}
</style>
