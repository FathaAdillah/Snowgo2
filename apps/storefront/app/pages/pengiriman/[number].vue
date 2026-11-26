<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/shipments/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/shipments/{number}'>>('/api/v1' + base, { server: false })
const s = computed(() => data.value?.data)
const quantities = reactive<Record<string, number>>({})
const note = ref('')
const reason = ref('')
const { busy, problem, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
async function claim() {
  if (!s.value) return
  const items = s.value.items.filter(i => (quantities[i.id] ?? 0) > 0).map(i => ({ shipment_item_id: i.id, qty: quantities[i.id] }))
  const response = await write<{ data: { number: string } }>('/returns', { order_number: s.value.order_number, shipment_number: s.value.number, reason_code: reason.value, items, ...(note.value ? { note: note.value } : {}) })
  await navigateTo('/purnajual/retur/' + response.data.number)
}
</script>

<template>
  <section class="stack">
    <h1>Pelacakan paket</h1>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="s">
        <section class="panel stack">
          <h2>{{ s.number }}</h2>
          <NuxtLink :to="'/pesanan/' + s.order_number">{{ s.order_number }}</NuxtLink>
          <p>{{ s.carrier_label }} · {{ s.service_code }} · {{ s.status }}</p>
          <p>Resi {{ s.tracking_number || 'belum tersedia' }}</p>
          <p
            v-for="item in s.items"
            :key="item.id"
          >
            {{ item.qty }} × {{ item.name }}
          </p>
        </section>
        <section class="panel stack">
          <h2>Riwayat pengiriman</h2>
          <p v-if="!s.tracking.length">
            Belum ada pembaruan kurir.
          </p>
          <p
            v-for="(e, i) in s.tracking"
            :key="i"
          >
            {{ e.occurred_at }} · {{ e.status }}
          </p>
        </section>
        <details
          v-if="s.status === 'DELIVERED'"
          class="panel"
        >
          <summary>Ajukan retur</summary>
          <form @submit.prevent="run(claim)">
            <label
              v-for="item in s.items"
              :key="item.id"
            >{{ item.name }} · jumlah diretur<input
              v-model.number="quantities[item.id]"
              placeholder="0"
              type="number"
              min="0"
              :max="item.qty"
            >
            </label>
            <ReasonChoice
              v-model="reason"
              domain="returns"
            />
            <label>Catatan<textarea
              v-model="note"
              maxlength="1000"
            />
            </label>
            <button :disabled="busy || !reason || !Object.values(quantities).some(n => n > 0)">
              Kirim klaim retur
            </button>
          </form>
        </details>
      </template>
    </StoreResult>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </section>
</template>
