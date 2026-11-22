<script setup lang="ts">
defineProps<{ collapsed?: boolean }>()
</script>

<template>
  <span class="flex items-center gap-3">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-default text-primary shadow-sm ring ring-default">
      <UIcon
        name="i-lucide-snowflake"
        class="size-5"
      />
    </span>
    <span
      v-if="!collapsed"
      class="text-base font-bold tracking-tight whitespace-nowrap text-highlighted"
    >
      Snowego <span class="text-primary">Admin</span>
    </span>
  </span>
</template>
