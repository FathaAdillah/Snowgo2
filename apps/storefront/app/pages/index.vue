<script setup lang="ts">
const { data: products, status, error, refresh } = await useFetch<StoreRead<'/products'>>('/api/v1/products', { query: { per_page: 8 } })
const { data: categories } = await useFetch<StoreRead<'/categories'>>('/api/v1/categories', { query: { per_page: 100 } })
const { data: content } = await useFetch<StoreRead<'/content'>>('/api/v1/content')
const banner = computed(() => content.value?.data.find(e => e.type === 'BANNER' && e.media_url))
const featured = computed(() => content.value?.data.filter(e => e.type === 'FEATURED') ?? [])
const services = [
  { icon: 'truck', title: 'Pengiriman Cepat', detail: 'Dari luar negeri ke Indonesia' },
  { icon: 'package', title: 'Packing Aman', detail: 'Dengan extra protection' },
  { icon: 'headphones', title: 'CS Ramah', detail: 'Siap membantu kamu' }
]
useStoreSeo(() => 'Snowe GO · Siap Kirim, Preorder & Request Order', () => 'Belanja koleksi pilihan dari Indonesia dan luar negeri di Snowe GO.', () => '/')
</script>

<template>
  <div class="home">
    <h1 class="sr-only">
      Snowe GO
    </h1>
    <NuxtLink
      class="hero"
      :to="banner ? '/konten/' + banner.slug : '/shop'"
    >
      <img
        :src="banner?.media_url || '/brand/snow-hero.jpeg'"
        :alt="banner?.title || 'Selamat datang di Snowe GO. Belanja sekarang.'"
        fetchpriority="high"
        width="1280"
        height="720"
      >
    </NuxtLink>
    <div class="service-strip">
      <div
        v-for="s in services"
        :key="s.title"
      >
        <SnowIcon :name="s.icon" />
        <span>
          <strong>{{ s.title }}</strong>
          <small>{{ s.detail }} ♡</small>
        </span>
      </div>
    </div>
    <section v-if="categories?.data.length">
      <div class="section-title">
        <h2>Belanja Berdasarkan Kategori ♡</h2>
        <NuxtLink to="/shop">Lihat semua →</NuxtLink>
      </div>
      <div class="category-tiles">
        <NuxtLink
          v-for="c in categories.data.slice(0, 8)"
          :key="c.slug"
          :to="{ path: '/shop', query: { category: c.slug } }"
        >
          <SnowIcon name="shopping-bag" />{{ c.name }}</NuxtLink>
      </div>
    </section>
    <section
      v-for="p in featured"
      :key="p.slug"
    >
      <NuxtLink
        class="feature-link"
        :to="'/konten/' + p.slug"
      >
        <img
          v-if="p.media_url"
          :src="p.media_url"
          alt=""
        >{{ p.title }} →</NuxtLink>
    </section>
    <section>
      <div class="section-title">
        <h2>Produk Terbaru</h2>
        <NuxtLink to="/shop">Lihat semua →</NuxtLink>
      </div>
      <p
        v-if="status === 'pending' || status === 'idle'"
        role="status"
      >
        Memuat produk…
      </p>
      <p
        v-else-if="error"
        role="alert"
      >
        Produk belum dapat dimuat. <button @click="refresh()">
          Coba lagi
        </button>
      </p>
      <ProductGrid
        v-else-if="products?.data.length"
        :products="products.data"
      />
      <StoreEmpty v-else />
    </section>
  </div>
</template>
