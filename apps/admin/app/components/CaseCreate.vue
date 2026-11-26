<script setup lang="ts">
const props = defineProps<{ mode: 'refund' | 'return' }>()
const emit = defineEmits<{ saved: [], close: [] }>()
const order = ref('')
const shipment = ref('')
const kind = ref('PARTIAL')
const amount = ref<number>()
const reason = ref('')
const note = ref('')
const cancelOrder = ref(false)
const lines = ref([{ id: '', qty: 1, amount: 0 }])
const needsItems = computed(() => props.mode === 'return' || kind.value === 'ITEM')
const { action, ask, finish } = useCaseAction(`/api/v1/admin/${props.mode}s`, async () => {
  emit('saved')
})
function create() {
  const common = { order_number: order.value, reason_code: reason.value, ...(note.value && { note: note.value }) }
  if (props.mode === 'return') {
    ask('', { ...common, shipment_number: shipment.value, items: lines.value.map(l => ({ shipment_item_id: l.id, qty: l.qty })) }, 'Ajukan retur untuk paket ini')
  } else {
    const items = lines.value.map(l => ({ order_item_id: l.id, qty: l.qty, requested_amount: l.amount }))
    ask('', { ...common, kind: kind.value, cancel_order: cancelOrder.value, requested_amount: needsItems.value ? lines.value.reduce((total, l) => total + Number(l.amount), 0) : amount.value, ...(needsItems.value && { items }) }, 'Ajukan refund untuk pesanan ini')
  }
}
</script>

<template>
  <UCard>
    <form
      class="flex flex-wrap items-end gap-3"
      @submit.prevent="create"
    >
      <UFormField label="Nomor pesanan">
        <UInput
          v-model="order"
          required
        />
      </UFormField>
      <UFormField
        v-if="mode === 'return'"
        label="Nomor paket terkirim"
      >
        <UInput
          v-model="shipment"
          required
        />
      </UFormField>
      <template v-else>
        <UFormField label="Jenis refund">
          <USelect
            v-model="kind"
            :items="['PARTIAL', 'FULL', 'ITEM']"
          />
        </UFormField>
        <UFormField
          v-if="!needsItems"
          label="Jumlah refund (Rp)"
        >
          <UInput
            v-model="amount"
            type="number"
            min="1"
            required
          />
        </UFormField>
        <UCheckbox
          v-model="cancelOrder"
          label="Batalkan pesanan setelah transfer"
        />
      </template>
      <UFormField label="Kode alasan">
        <UInput
          v-model="reason"
          maxlength="48"
          required
        />
      </UFormField>
      <UFormField label="Catatan">
        <UInput
          v-model="note"
          maxlength="1000"
        />
      </UFormField>
      <template v-if="needsItems">
        <div
          v-for="(line, index) in lines"
          :key="index"
          class="flex flex-wrap items-end gap-2"
        >
          <UFormField :label="mode === 'refund' ? 'ID item pesanan' : 'ID item paket'">
            <UInput
              v-model="line.id"
              required
            />
          </UFormField>
          <UFormField label="Unit">
            <UInput
              v-model="line.qty"
              type="number"
              min="1"
              required
            />
          </UFormField>
          <UFormField
            v-if="mode === 'refund'"
            label="Jumlah baris (Rp)"
          >
            <UInput
              v-model="line.amount"
              type="number"
              min="1"
              required
            />
          </UFormField>
          <AdminRowAction
            v-if="lines.length > 1"
            :label="`Hapus baris ${index + 1}`"
            icon="i-lucide-trash-2"
            @click="lines.splice(index, 1)"
          />
        </div>
        <UButton
          label="Tambah item"
          variant="outline"
          @click="lines.push({ id: '', qty: 1, amount: 0 })"
        />
      </template>
      <UButton
        type="submit"
        label="Ajukan"
      />
      <UButton
        variant="outline"
        label="Tutup"
        @click="emit('close')"
      />
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="finish"
      @cancel="action = undefined"
    />
  </UCard>
</template>
