<script setup lang="ts">
const props = defineProps<{ slug: string }>()
const { busy, problem, notice, run } = useCustomerAction()
const route = useRoute()
async function favorite() {
  try {
    await customerWrite('/wishlist/' + encodeURIComponent(props.slug), 'PUT')
  } catch (err) {
    if ((err as { status?: number }).status === 401) {
      await navigateTo({ path: '/masuk', query: { next: route.fullPath } })
      return
    }
    throw err
  }
}
</script>

<template>
  <div class="stack">
    <button
      class="subtle"
      :disabled="busy"
      @click="run(favorite, 'Produk tersimpan di favorit.')"
    >
      <SnowIcon name="heart" /> Simpan ke favorit
    </button>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }}
    </p>
  </div>
</template>
