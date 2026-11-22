<script setup lang="ts">
defineProps<{
  label: string
  value: string
  icon: string
  accent: string
  iconClass: string
  sub?: string
}>()
</script>

<template>
  <UCard
    class="relative overflow-hidden transition-shadow hover:shadow-md"
    :ui="{ body: 'flex items-start justify-between gap-4 p-5 sm:p-5' }"
  >
    <span
      class="absolute inset-y-0 left-0 w-1"
      :class="accent"
    />
    <div class="flex min-w-0 flex-col gap-1">
      <span class="text-sm text-muted">{{ label }}</span>
      <span class="truncate text-2xl font-bold text-highlighted">{{ value }}</span>
      <span
        v-if="sub"
        class="text-xs text-muted"
      >{{ sub }}</span>
    </div>
    <span
      class="flex size-11 shrink-0 items-center justify-center rounded-lg"
      :class="iconClass"
    >
      <UIcon
        :name="icon"
        class="size-5"
      />
    </span>
  </UCard>
</template>
