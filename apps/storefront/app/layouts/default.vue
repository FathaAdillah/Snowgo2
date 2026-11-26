<script setup lang="ts">
const links = [
  { label: 'Home', path: '/', icon: 'house' }, { label: 'Shop', path: '/shop', icon: 'shopping-bag' },
  { label: 'Drops', path: '/drops', icon: 'sparkles' }, { label: 'Favorit', path: '/favorit', icon: 'heart' },
  { label: 'Keranjang', path: '/keranjang', icon: 'shopping-cart' }, { label: 'Akun', path: '/akun', icon: 'user-round' }
]
const route = useRoute()
const search = ref('')
watch(() => route.query.q, (q) => {
  search.value = typeof q === 'string' ? q : ''
}, { immediate: true })
const { data: categories } = await useFetch<StoreRead<'/categories'>>('/api/v1/categories', { query: { per_page: 100 } })
const { data: pages } = await useFetch<StoreRead<'/content'>>('/api/v1/content')
const policies = computed(() => pages.value?.data.filter(p => p.type === 'PAGE' || p.type === 'FAQ') ?? [])
</script>

<template>
  <div>
    <a
      class="skip-link"
      href="#main"
    >Langsung ke isi</a>
    <header class="shop-header">
      <div class="top-bar">
        <NuxtLink
          to="/"
          class="brand"
          aria-label="Snowe GO, beranda"
        >
          <img
            src="/brand/snow-logo.webp"
            alt=""
            width="56"
            height="56"
          >Snowe GO</NuxtLink>
        <form
          class="search-bar"
          role="search"
          @submit.prevent="navigateTo({ path: '/shop', query: search.trim() ? { q: search.trim() } : {} })"
        >
          <input
            v-model="search"
            aria-label="Cari produk"
            type="search"
            maxlength="120"
            placeholder="Cari produk, karakter, atau kategori…"
          >
          <button aria-label="Cari">
            <SnowIcon name="search" />
          </button>
        </form>
        <nav
          aria-label="Utama"
          class="icon-nav"
        >
          <NuxtLink
            v-for="link in links"
            :key="link.path"
            :to="link.path"
          >
            <SnowIcon :name="link.icon" />
            <span>{{ link.label }}</span>
          </NuxtLink>
        </nav>
      </div>
      <nav
        class="category-bar"
        aria-label="Kategori dan layanan"
      >
        <NuxtLink to="/shop">
          <SnowIcon name="menu" /> Semua Kategori</NuxtLink>
        <NuxtLink
          v-for="c in categories?.data.slice(0, 6)"
          :key="c.slug"
          :to="{ path: '/shop', query: { category: c.slug } }"
        >{{ c.name }}</NuxtLink>
        <template v-if="!categories?.data.length">
          <NuxtLink to="/shop?method=RTS">Siap Kirim</NuxtLink>
          <NuxtLink to="/drops">Preorder</NuxtLink>
        </template>
        <NuxtLink to="/request-order">Request Order</NuxtLink>
      </nav>
    </header>
    <main
      id="main"
      class="store-main"
      tabindex="-1"
    >
      <slot />
    </main>
    <footer class="store-footer">
      <div>
        <section>
          <NuxtLink
            class="brand"
            to="/"
          >
            <img
              src="/brand/snow-logo.webp"
              alt=""
              width="48"
              height="48"
            >Snowe GO</NuxtLink>
          <p>Layanan belanja produk pilihan melalui stok Indonesia, pre-order atau group order, dan request produk dari luar negeri.</p>
        </section>
        <section>
          <h2>Belanja</h2>
          <NuxtLink to="/shop">Siap Kirim</NuxtLink>
          <NuxtLink to="/drops">Preorder</NuxtLink>
          <NuxtLink to="/request-order">Request Order</NuxtLink>
        </section>
        <section v-if="policies.length">
          <h2>Informasi & Kebijakan</h2>
          <NuxtLink
            v-for="p in policies"
            :key="p.slug"
            :to="'/konten/' + p.slug"
          >{{ p.title }}</NuxtLink>
        </section>
        <section>
          <h2>Akun</h2>
          <NuxtLink to="/akun">Profil</NuxtLink>
          <NuxtLink to="/pesanan">Pesanan saya</NuxtLink>
          <NuxtLink to="/favorit">Favorit</NuxtLink>
        </section>
      </div>
      <small>© {{ new Date().getFullYear() }} Snowe GO</small>
    </footer>
  </div>
</template>
