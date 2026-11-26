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
const open = ref(false)
const touch = ref(false)

function activate(event: MouseEvent) {
  if (touch.value && event.detail > 0 && !open.value) {
    event.preventDefault()
    open.value = true
    return
  }
  open.value = false
  if (!props.disabled) emit('click', event)
}
</script>

<template>
  <UTooltip
    v-model:open="open"
    :text="touch && !disabled ? `${label} · Ketuk lagi untuk menjalankan` : label"
    disable-closing-trigger
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
