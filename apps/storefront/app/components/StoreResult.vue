<script setup lang="ts">
defineProps<{ status: string, empty?: boolean, emptyLabel?: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <p
    v-if="status === 'pending' || status === 'idle'"
    role="status"
  >
    Memuat…
  </p>
  <p
    v-else-if="status === 'error'"
    role="alert"
  >
    Data belum dapat dimuat. <button
      type="button"
      @click="$emit('retry')"
    >
      Coba lagi
    </button>
  </p>
  <p v-else-if="empty">
    {{ emptyLabel }}
  </p>
  <slot v-else />
</template>
