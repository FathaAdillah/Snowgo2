<script setup lang="ts">
defineProps<{ products: ProductCard[] }>()
function startingPrice(product: ProductCard) {
  const available = product.offers.filter(offer => offer.available)
  return Math.min(...(available.length ? available : product.offers).map(offer => offer.from_amount))
}
</script>

<template>
  <ul class="product-grid">
    <li
      v-for="p in products"
      :key="p.slug"
    >
      <NuxtLink
        class="product-card"
        :to="'/produk/' + p.slug"
      >
        <div class="cover">
          <img
            v-if="p.cover_url"
            :src="p.cover_url"
            alt=""
            loading="lazy"
          >
          <span v-else>Snowe GO</span>
        </div>
        <div class="card-copy">
          <small>{{ p.category_slugs.join(' · ') }}</small>
          <h3>{{ p.name }}</h3>
          <div v-if="p.offers.length">
            <span
              v-for="method in new Set(p.offers.map(o => o.method))"
              :key="method"
              class="badge"
            >{{ method === 'RTS' ? 'Siap kirim' : 'Preorder' }}</span>
            <small v-if="!p.offers.some(offer => offer.available)">Belum tersedia</small>
            <p>Mulai {{ rupiah(startingPrice(p)) }}</p>
          </div>
          <p v-else>Belum ada penawaran</p>
        </div>
      </NuxtLink>
    </li>
  </ul>
</template>
