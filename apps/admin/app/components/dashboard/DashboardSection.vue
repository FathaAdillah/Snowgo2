<script setup lang="ts">
defineProps<{
  title: string
  icon?: string
}>()
</script>

<template>
  <section class="flex flex-col gap-4">
    <header class="flex items-center gap-2">
      <UIcon
        v-if="icon"
        :name="icon"
        class="size-5 text-primary"
      />
      <h2 class="text-lg font-semibold text-highlighted">
        {{ title }}
      </h2>
    </header>
    <slot />
  </section>
</template>
