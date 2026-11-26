<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

defineProps<{
  label: string
  icon: string
  color?: 'neutral' | 'error' | 'success' | 'warning'
  to?: RouteLocationRaw
  disabled?: boolean
}>()
const emit = defineEmits<{ click: [event: MouseEvent] }>()
</script>

<template>
  <UTooltip :text="label">
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
      @click="!disabled && emit('click', $event)"
    />
  </UTooltip>
</template>
