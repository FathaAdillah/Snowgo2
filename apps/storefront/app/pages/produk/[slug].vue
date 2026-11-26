<script setup lang="ts">
const route = useRoute()
const slug = computed(() => String(route.params.slug))
const { data, error, refresh } = await useFetch<StoreRead<'/products/{slug}'>>(() => '/api/v1/products/' + encodeURIComponent(slug.value))
if (error.value?.statusCode === 404) throw createError({ statusCode: 404, statusMessage: 'Produk tidak ditemukan' })
const product = computed(() => data.value?.data)
useStoreSeo(() => product.value?.seo_title || (product.value?.name ?? 'Produk') + ' · Snowe GO', () => product.value?.seo_description || product.value?.description || '', () => '/produk/' + encodeURIComponent(slug.value))
useHead(() => ({ script: product.value
  ? [{ type: 'application/ld+json', textContent: JSON.stringify({
      '@context': 'https://schema.org', '@type': 'Product', 'name': product.value.name, 'description': product.value.description, 'image': product.value.media.map(m => m.url),
      'offers': product.value.variants.flatMap(v => v.offers.map(o => ({ '@type': 'Offer', 'sku': v.sku, 'price': o.unit_amount, 'priceCurrency': 'IDR', 'availability': 'https://schema.org/' + (o.available ? o.method === 'PO' ? 'PreOrder' : 'InStock' : 'OutOfStock') })))
    }).replaceAll('<', '\\u003c') }]
  : [] }))
</script>

<template>
  <section
    v-if="error"
    role="alert"
  >
    Produk tidak dapat dimuat. <button @click="refresh()">
      Coba lagi
    </button>
  </section>
  <article
    v-else-if="product"
    class="product-detail"
  >
    <div class="product-gallery">
      <img
        v-for="m in product.media"
        :key="m.url"
        :src="m.url"
        :alt="m.alt"
      ><div
        v-if="!product.media.length"
        class="cover"
      >
        Snowe GO
      </div>
    </div>
    <div>
      <NuxtLink to="/shop">Shop</NuxtLink><h1>{{ product.name }}</h1><p class="prose-copy">
        {{ product.description }}
      </p>
      <ul>
        <li
          v-for="o in product.offers"
          :key="o.campaign_slug || o.method"
        >
          {{ o.method }} · Mulai {{ rupiah(o.from_amount) }}
        </li>
      </ul>
    </div>
  </article>
</template>
