<script setup lang="ts">
const { data } = await useApiHealth()
</script>

<template>
  <section class="space-y-6">
    <h1 class="text-3xl font-semibold">
      Siap kirim, preorder dan request order
    </h1>
    <p class="max-w-2xl text-slate-600">
      Kerangka storefront Snowego. Katalog, keranjang dan checkout dibangun pada E03–E13.
    </p>
    <p
      class="text-sm"
      :class="data?.data.status === 'ok' ? 'text-emerald-700' : 'text-red-700'"
      role="status"
    >
      {{ data?.data.status === 'ok' ? 'API sehat' : 'API tidak terjangkau' }}
    </p>
  </section>
</template>
