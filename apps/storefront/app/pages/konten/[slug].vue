<script setup lang="ts">
const route = useRoute()
const { data, error, refresh } = await useFetch<StoreRead<'/content/{slug}'>>(() => '/api/v1/content/' + encodeURIComponent(String(route.params.slug)))
if (error.value?.statusCode === 404) throw createError({ statusCode: 404, statusMessage: 'Halaman tidak tersedia' })
useStoreSeo(() => (data.value?.data.title ?? 'Informasi') + ' · Snowe GO', () => data.value?.data.title ?? '', () => route.path)
</script>

<template>
  <article
    v-if="data"
    class="panel content-body"
  >
    <h1>{{ data.data.title }}</h1><img
      v-if="data.data.media_url"
      :src="data.data.media_url"
      alt=""
    ><div v-html="data.data.body" />
  </article>
  <p
    v-else
    role="alert"
  >
    Halaman tidak dapat dimuat. <button @click="refresh()">
      Coba lagi
    </button>
  </p>
</template>
