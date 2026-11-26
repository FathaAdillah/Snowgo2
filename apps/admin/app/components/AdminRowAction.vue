<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

const props = defineProps<{
  label: string
  icon: string
  color?: 'neutral' | 'error' | 'success' | 'warning'
  to?: RouteLocationRaw
  disabled?: boolean
}>()
const emit = defineEmits<{ click: [event: MouseEvent] }>()
const tooltip = ref<'closed' | 'hint' | 'touch-preview'>('closed')
const touch = ref(false)

function onTooltipChange(open: boolean) {
  if (!open) tooltip.value = 'closed'
  else if (tooltip.value === 'closed') tooltip.value = 'hint'
}

function activate(event: MouseEvent) {
  if (touch.value && event.detail > 0 && tooltip.value !== 'touch-preview') {
    event.preventDefault()
    tooltip.value = 'touch-preview'
    return
  }
  tooltip.value = 'closed'
  if (!props.disabled) emit('click', event)
}
</script>

<template>
  <UTooltip
    :open="tooltip !== 'closed'"
    :text="tooltip === 'touch-preview' && !disabled ? `${label} · Ketuk lagi untuk menjalankan` : label"
    disable-closing-trigger
    @update:open="onTooltipChange"
  >
    <UButton
      type="button"
      size="sm"
      square
      variant="outline"
      :color="color ?? 'neutral'"
      :icon="icon"
      :aria-label="label"
      :aria-disabled="disabled || undefined"
      :class="{ 'cursor-not-allowed opacity-50': disabled }"
      :to="disabled ? undefined : to"
      @pointerdown="touch = $event.pointerType === 'touch'"
      @keydown="touch = false"
      @click="activate"
    />
  </UTooltip>
</template>
