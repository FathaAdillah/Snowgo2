<script setup lang="ts">
defineProps<{ products: ProductCard[] }>()
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
            >{{ method }}</span>
            <p>Mulai {{ rupiah(Math.min(...p.offers.map(o => o.from_amount))) }}</p>
          </div>
          <p v-else>Belum ada penawaran</p>
        </div>
      </NuxtLink>
    </li>
  </ul>
</template>
