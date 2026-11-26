<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const filters = computed(() => {
  const query: Record<string, string> = {}
  for (const field of ['q', 'category', 'method', 'sort', 'page']) {
    const value = route.query[field]
    if (typeof value === 'string' && value.trim()) query[field] = value.trim()
  }
  return query
})
const { data, error, status, refresh } = await useFetch<StoreRead<'/products'>>('/api/v1/search', { query: filters })
const { data: categories } = await useFetch<StoreRead<'/categories'>>('/api/v1/categories', { query: { per_page: 100 } })
const q = ref(filters.value.q ?? '')
watch(() => filters.value.q, (value) => {
  q.value = value ?? ''
})
function filter(field: string, value: string) {
  return router.push({ path: '/shop', query: { ...filters.value, page: undefined, [field]: value || undefined } })
}
useStoreSeo(() => 'Shop · Snowe GO', () => 'Temukan koleksi siap kirim dan preorder Snowe GO.', () => '/shop' + (filters.value.page ? '?page=' + encodeURIComponent(filters.value.page) : ''))
useSeoMeta({ robots: () => Object.keys(filters.value).some(k => k !== 'page') ? 'noindex,follow' : 'index,follow' })
</script>

<template>
  <section class="shop-section">
    <h1>Shop</h1>
    <form
      class="filters"
      @submit.prevent="filter('q', q)"
    >
      <label>Cari produk<input
        v-model="q"
        type="search"
        maxlength="120"
      >
      </label>
      <label>Kategori<select
        :value="filters.category || ''"
        @change="filter('category', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">Semua kategori</option>
        <option
          v-for="c in categories?.data"
          :key="c.slug"
          :value="c.slug"
        >{{ c.name }}</option>
      </select>
      </label>
      <label>Cara beli<select
        :value="filters.method || ''"
        @change="filter('method', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">Semua</option>
        <option value="RTS">Siap kirim</option>
        <option value="PO">Preorder</option>
      </select>
      </label>
      <button>Cari</button>
    </form>
    <p
      v-if="status === 'pending'"
      role="status"
    >
      Memuat produk…
    </p>
    <div
      v-else-if="error"
      role="alert"
    >
      Katalog belum dapat dimuat. <button @click="refresh()">
        Coba lagi
      </button>
    </div>
    <ProductGrid
      v-else-if="data?.data.length"
      :products="data.data"
    />
    <StoreEmpty
      v-else
      :filtered="!!(filters.q || filters.category || filters.method)"
    />
    <nav
      v-if="data && data.meta.last_page > 1"
      aria-label="Halaman katalog"
      class="filters"
    >
      <NuxtLink
        v-if="data.meta.page > 1"
        :to="{ query: { ...filters, page: data.meta.page - 1 } }"
      >Sebelumnya</NuxtLink>
      <span>{{ data.meta.page }} / {{ data.meta.last_page }}</span>
      <NuxtLink
        v-if="data.meta.page < data.meta.last_page"
        :to="{ query: { ...filters, page: data.meta.page + 1 } }"
      >Berikutnya</NuxtLink>
    </nav>
  </section>
</template>
