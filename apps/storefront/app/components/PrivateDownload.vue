<script setup lang="ts">
const props = defineProps<{ path: string, label: string }>()
const { problem, busy, run } = useCustomerAction()
async function download() {
  const result = await $fetch<{ data: { url: string } }>('/api/v1' + props.path)
  const signed = new URL(result.data.url, window.location.origin)
  if (!['http:', 'https:'].includes(signed.protocol)) throw new Error('URL tidak valid')
  window.location.assign(signed.href)
}
</script>

<template>
  <div>
    <button
      type="button"
      class="subtle"
      :disabled="busy"
      @click="run(download)"
    >
      {{ label }}
    </button>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </div>
</template>
