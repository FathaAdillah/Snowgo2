<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
const page = ref(1)
const { data, error, refresh } = await useFetch<StoreRead<'/wishlist'>>('/api/v1/wishlist', { server: false, query: { page } })
const { busy, problem, run } = useCustomerAction()
async function remove(slug: string) {
  await run(async () => {
    await customerWrite('/wishlist/' + encodeURIComponent(slug), 'DELETE')
    await refresh()
  })
}
</script>

<template>
  <section class="stack">
    <h1>Favorit saya</h1>
    <CustomerLinks />
    <p
      v-if="error"
      role="alert"
    >
      Favorit gagal dimuat. <button @click="refresh()">
        Coba lagi
      </button>
    </p>
    <template v-else-if="data">
      <p v-if="!data.data.length">
        Belum ada produk favorit. <NuxtLink to="/shop">Jelajahi Shop</NuxtLink>
      </p>
      <article
        v-for="p in data.data"
        :key="p.slug"
        class="list-row"
      >
        <NuxtLink :to="'/produk/' + p.slug">{{ p.name }}</NuxtLink>
        <button
          :disabled="busy"
          class="subtle"
          @click="remove(p.slug)"
        >
          Hapus dari favorit
        </button>
      </article>
      <div class="actions">
        <button
          v-if="page > 1"
          @click="page--"
        >
          Sebelumnya
        </button>
        <button
          v-if="page < data.meta.last_page"
          @click="page++"
        >
          Berikutnya
        </button>
      </div>
    </template>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </section>
</template>
