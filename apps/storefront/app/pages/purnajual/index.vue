<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
const refundPage = ref(1)
const returnPage = ref(1)
const refunds = await useFetch<StoreRead<'/refunds'>>('/api/v1/refunds', { server: false, query: { page: refundPage } })
const returns = await useFetch<StoreRead<'/returns'>>('/api/v1/returns', { server: false, query: { page: returnPage } })
</script>

<template>
  <section class="stack">
    <h1>Refund & Retur</h1>
    <CustomerLinks />
    <h2>Refund</h2>
    <StoreResult
      :status="refunds.status.value"
      :empty="!refunds.data.value?.data.length"
      empty-label="Belum ada refund."
      @retry="refunds.refresh()"
    >
      <NuxtLink
        v-for="r in refunds.data.value?.data"
        :key="r.number"
        class="list-row"
        :to="'/purnajual/refund/' + r.number"
      >{{ r.number }} · {{ rupiah(r.requested_amount) }} <span class="badge">{{ r.status }}</span>
      </NuxtLink>
    </StoreResult>
    <nav class="actions">
      <button
        v-if="refundPage > 1"
        @click="refundPage--"
      >
        Sebelumnya
      </button>
      <button
        v-if="refundPage < (refunds.data.value?.meta.last_page ?? 1)"
        @click="refundPage++"
      >
        Berikutnya
      </button>
    </nav>
    <h2>Retur</h2>
    <StoreResult
      :status="returns.status.value"
      :empty="!returns.data.value?.data.length"
      empty-label="Belum ada retur."
      @retry="returns.refresh()"
    >
      <NuxtLink
        v-for="r in returns.data.value?.data"
        :key="r.number"
        class="list-row"
        :to="'/purnajual/retur/' + r.number"
      >{{ r.number }} · {{ r.shipment_number }} <span class="badge">{{ r.status }}</span>
      </NuxtLink>
    </StoreResult>
    <nav class="actions">
      <button
        v-if="returnPage > 1"
        @click="returnPage--"
      >
        Sebelumnya
      </button>
      <button
        v-if="returnPage < (returns.data.value?.meta.last_page ?? 1)"
        @click="returnPage++"
      >
        Berikutnya
      </button>
    </nav>
    <NuxtLink to="/pesanan">Buka pesanan untuk mengajukan klaim →</NuxtLink>
  </section>
</template>
