<script setup lang="ts">
const props = defineProps<{ order: StoreRead<'/orders/{number}'>['data'] }>()
const emit = defineEmits<{ refresh: [] }>()
const kind = ref('PARTIAL')
const reason = ref('')
const amount = ref<number>()
const qty = ref(1)
const item = ref('')
const note = ref('')
const cancel = ref(false)
const { busy, problem, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
async function submit() {
  const body = { kind: kind.value, order_number: props.order.number, requested_amount: kind.value === 'FULL' ? props.order.refundable_amount : amount.value, reason_code: reason.value,
    cancel_order: cancel.value, ...(note.value ? { note: note.value } : {}), ...(kind.value === 'ITEM' ? { items: [{ order_item_id: item.value, qty: qty.value, requested_amount: amount.value }] } : {}) }
  try {
    const result = await write<{ data: { number: string } }>('/refunds', body)
    await navigateTo('/purnajual/refund/' + result.data.number)
  } catch (err) {
    if ((err as { data?: { code?: string } }).data?.code === 'REFUND_LIMIT') emit('refresh')
    throw err
  }
}
</script>

<template>
  <details class="panel">
    <summary>Ajukan refund</summary>
    <form @submit.prevent="run(submit)">
      <label>Jenis refund<select v-model="kind">
        <option value="FULL">Seluruh dana yang tersedia</option>
        <option value="PARTIAL">Sebagian dana</option>
        <option value="ITEM">Per barang</option>
      </select>
      </label>
      <template v-if="kind === 'ITEM'">
        <label>Barang<select
          v-model="item"
          required
        >
          <option value="">Pilih barang</option>
          <template
            v-for="(i, n) in order.items"
            :key="n"
          >
            <option
              v-if="i.id"
              :value="i.id"
            >{{ i.name }} · {{ i.variant }}</option>
          </template>
        </select>
        </label>
        <label>Jumlah unit<input
          v-model.number="qty"
          type="number"
          min="1"
          required
        >
        </label>
      </template>
      <p>Dana yang dapat diajukan untuk refund · {{ rupiah(order.refundable_amount ?? 0) }}</p>
      <label v-if="kind !== 'FULL'">Nominal refund (Rp)<input
        v-model.number="amount"
        required
        type="number"
        min="1"
        step="1"
        :max="order.refundable_amount"
      >
      </label>
      <ReasonChoice
        v-model="reason"
        domain="refunds"
      />
      <label>Catatan<textarea
        v-model="note"
        maxlength="1000"
      />
      </label>
      <label v-if="order.status === 'CONFIRMED' && order.items.every(i => i.shipped_qty === 0)">
        <span>
          <input
            v-model="cancel"
            type="checkbox"
          > Ajukan pembatalan pesanan bersama refund</span>
      </label>
      <button :disabled="busy || !reason">
        Kirim permintaan refund
      </button>
      <p
        v-if="problem"
        role="alert"
      >
        {{ problem }}
      </p>
    </form>
  </details>
</template>
