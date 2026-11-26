<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
const page = ref(1)
const { data, status, refresh } = await useFetch<StoreRead<'/orders'>>('/api/v1/orders', { server: false, query: { page } })
</script>

<template>
  <section class="stack">
    <h1>Pesanan saya</h1>
    <CustomerLinks />
    <StoreResult
      :status="status"
      :empty="!data?.data.length"
      empty-label="Belum ada pesanan."
      @retry="refresh()"
    >
      <NuxtLink
        v-for="o in data?.data"
        :key="o.number"
        class="list-row"
        :to="'/pesanan/' + o.number"
      >
        <div>
          <h3>{{ o.number }}</h3>
          <small>{{ o.method }} · {{ o.status }} · {{ o.created_at }}</small>
        </div>
        <strong>{{ rupiah(o.total_amount) }}</strong>
      </NuxtLink>
    </StoreResult>
    <div class="actions">
      <button
        v-if="page > 1"
        @click="page--"
      >
        Sebelumnya
      </button>
      <button
        v-if="data && page < data.meta.last_page"
        @click="page++"
      >
        Berikutnya
      </button>
    </div>
  </section>
</template>
