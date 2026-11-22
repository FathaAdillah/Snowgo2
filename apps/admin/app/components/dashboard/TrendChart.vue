<script setup lang="ts">
import type { TrendPoint } from '~/composables/useDashboard'

const props = defineProps<{ points: TrendPoint[] }>()

const width = 800
const height = 260
const pad = { top: 16, right: 44, bottom: 28, left: 64 }
const plotW = width - pad.left - pad.right
const plotH = height - pad.top - pad.bottom

const maxAmount = computed(() => Math.max(...props.points.map(p => p.amount), 1))
const maxOrders = computed(() => Math.max(...props.points.map(p => p.orders), 1))
const step = computed(() => plotW / Math.max(props.points.length, 1))

const x = (index: number) => pad.left + step.value * index + step.value / 2
const yAmount = (value: number) => pad.top + plotH - (value / maxAmount.value) * plotH

const linePath = computed(() => props.points.map((point, index) => {
  const px = x(index)
  const py = yAmount(point.amount)
  if (index === 0) {
    return `M${px},${py}`
  }
  const prevX = x(index - 1)
  const prevY = yAmount(props.points[index - 1]!.amount)
  const mid = (prevX + px) / 2
  return `C${mid},${prevY} ${mid},${py} ${px},${py}`
}).join(' '))

const areaPath = computed(() => props.points.length
  ? `${linePath.value} L${x(props.points.length - 1)},${pad.top + plotH} L${x(0)},${pad.top + plotH} Z`
  : '')

const bars = computed(() => props.points.map((point, index) => {
  const h = (point.orders / maxOrders.value) * plotH
  const w = Math.min(step.value * 0.5, 28)
  return { x: x(index) - w / 2, y: pad.top + plotH - h, w, h, point }
}))

const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map(ratio => ({
  y: pad.top + plotH - ratio * plotH,
  amount: formatCompactRupiah(Math.round(maxAmount.value * ratio)).replace('Rp ', ''),
  orders: Math.round(maxOrders.value * ratio)
})))

const labelEvery = computed(() => Math.ceil(props.points.length / 10))
const dayLabel = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' })

const hovered = ref<number | null>(null)
</script>

<template>
  <div class="relative">
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      class="h-auto w-full"
      role="img"
      aria-label="Grafik tren penjualan dan jumlah pesanan"
      @mouseleave="hovered = null"
    >
      <defs>
        <linearGradient
          id="trend-fill"
          x1="0"
          x2="0"
          y1="0"
          y2="1"
        >
          <stop
            offset="0%"
            stop-color="var(--ui-primary)"
            stop-opacity="0.25"
          />
          <stop
            offset="100%"
            stop-color="var(--ui-primary)"
            stop-opacity="0.02"
          />
        </linearGradient>
      </defs>

      <g
        v-for="tick in ticks"
        :key="tick.y"
      >
        <line
          :x1="pad.left"
          :x2="width - pad.right"
          :y1="tick.y"
          :y2="tick.y"
          stroke="var(--ui-border)"
          stroke-dasharray="4 4"
        />
        <text
          :x="pad.left - 8"
          :y="tick.y + 4"
          text-anchor="end"
          class="fill-(--ui-text-muted) text-[11px]"
        >{{ tick.amount }}</text>
        <text
          :x="width - pad.right + 8"
          :y="tick.y + 4"
          class="fill-(--ui-text-muted) text-[11px]"
        >{{ tick.orders }}</text>
      </g>

      <rect
        v-for="(bar, index) in bars"
        :key="bar.point.date"
        :x="bar.x"
        :y="bar.y"
        :width="bar.w"
        :height="bar.h"
        rx="4"
        fill="var(--ui-secondary)"
        :fill-opacity="hovered === index ? 0.9 : 0.55"
      />

      <path
        :d="areaPath"
        fill="url(#trend-fill)"
      />
      <path
        :d="linePath"
        fill="none"
        stroke="var(--ui-primary)"
        stroke-width="3"
        stroke-linecap="round"
      />

      <template
        v-for="(point, index) in points"
        :key="`label-${point.date}`"
      >
        <text
          v-if="index % labelEvery === 0"
          :x="x(index)"
          :y="height - 8"
          text-anchor="middle"
          class="fill-(--ui-text-muted) text-[11px]"
        >{{ dayLabel.format(new Date(`${point.date}T00:00:00`)) }}</text>
        <rect
          :x="x(index) - step / 2"
          :y="pad.top"
          :width="step"
          :height="plotH"
          fill="transparent"
          @mouseenter="hovered = index"
        />
      </template>

      <circle
        v-if="hovered !== null && points[hovered]"
        :cx="x(hovered)"
        :cy="yAmount(points[hovered]!.amount)"
        r="5"
        fill="var(--ui-bg)"
        stroke="var(--ui-primary)"
        stroke-width="3"
      />
    </svg>

    <div
      v-if="hovered !== null && points[hovered]"
      class="pointer-events-none absolute top-2 left-1/2 -translate-x-1/2 rounded-md border border-default bg-default px-3 py-2 text-xs shadow-md"
    >
      <p class="font-semibold text-highlighted">
        {{ dayLabel.format(new Date(`${points[hovered]!.date}T00:00:00`)) }}
      </p>
      <p class="text-muted">
        Penjualan: {{ formatRupiah(points[hovered]!.amount) }}
      </p>
      <p class="text-muted">
        Pesanan: {{ points[hovered]!.orders }}
      </p>
    </div>
  </div>
</template>
