<script setup lang="ts">
defineProps<{ name: string }>()
</script>

<template>
  <svg
    class="snow-icon"
    aria-hidden="true"
    focusable="false"
    viewBox="0 0 24 24"
  >
    <use :href="'/brand/icons.svg#' + name" />
  </svg>
</template>
