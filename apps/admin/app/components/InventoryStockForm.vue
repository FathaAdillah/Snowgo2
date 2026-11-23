<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import type { InventoryAction } from '~/composables/useInventory'

const props = defineProps<{ mode: 'receipt' | 'adjustment', initialLevel?: number }>()
const emit = defineEmits<{ cancel: [], saved: [] }>()
const { can } = useAdminAuth()
const warehouse = ref<number>()
const level = ref(props.initialLevel)
const sku = ref('')
const type = ref<'INITIAL' | 'PURCHASE'>('PURCHASE')
const quantity = ref<number>()
const reason = ref('')
const note = ref('')
const action = ref<InventoryAction>()
const receipt = computed(() => props.mode === 'receipt')
const valid = computed(() => {
  const id = receipt.value ? warehouse.value : level.value
  const n = Number(quantity.value)
  return Number.isInteger(Number(id)) && Number(id) > 0 && Number(id) <= 2147483647 && reason.value.trim().length > 0
    && Number.isInteger(n) && (receipt.value ? n > 0 && n <= 10000 && sku.value.trim().length > 0 : n !== 0 && Math.abs(n) <= 2147483647)
})
function submit() {
  if (!valid.value || !can(receipt.value ? 'inventory.receipt.create' : 'inventory.adjustment.create')) return
  const common = { reason_code: reason.value.trim(), note: note.value.trim() || null }
  if (receipt.value) {
    const body = { ...common, warehouse_id: Number(warehouse.value), sku: sku.value.trim(), type: type.value, qty: Number(quantity.value) } satisfies paths['/admin/stock-receipts']['post']['requestBody']['content']['application/json']
    action.value = { method: 'POST', url: `${inventoryApi}/stock-receipts`, body, label: `${movementLabels[body.type]}: tambahkan ${body.qty} unit ${body.sku} ke gudang #${body.warehouse_id}?` }
  } else {
    const body = { ...common, level_id: Number(level.value), delta: Number(quantity.value) } satisfies paths['/admin/stock-adjustments']['post']['requestBody']['content']['application/json']
    action.value = { method: 'POST', url: `${inventoryApi}/stock-adjustments`, body, label: `${body.delta < 0 ? 'Kurangi' : 'Tambah'} ${Math.abs(body.delta)} unit pada saldo #${body.level_id} dengan alasan ${body.reason_code}?` }
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        {{ receipt ? 'Terima stok' : 'Koreksi stok' }}
      </h2>
    </template>
    <form
      class="grid gap-4 sm:grid-cols-2"
      @submit.prevent="submit"
    >
      <template v-if="receipt">
        <UFormField
          v-if="can('inventory.warehouse.view')"
          label="Gudang aktif"
        >
          <InventoryWarehousePicker v-model="warehouse" />
        </UFormField>
        <UFormField
          v-else
          label="ID gudang aktif"
        >
          <UInput
            v-model.number="warehouse"
            type="number"
            min="1"
            max="2147483647"
            required
            class="w-full"
          />
        </UFormField>
        <UFormField label="SKU aktif">
          <UInput
            v-model.trim="sku"
            required
            maxlength="80"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Jenis penerimaan">
          <USelect
            v-model="type"
            :items="[{ label: 'Penerimaan pembelian', value: 'PURCHASE' }, { label: 'Saldo pembuka (mutasi pertama)', value: 'INITIAL' }]"
            class="w-full"
          />
        </UFormField>
      </template>
      <UFormField
        v-else
        label="ID saldo"
        description="Gunakan ID dari halaman Saldo Stok."
      >
        <UInput
          v-model.number="level"
          type="number"
          min="1"
          max="2147483647"
          required
          class="w-full"
        />
      </UFormField>
      <UFormField
        :label="receipt ? 'Jumlah diterima' : 'Selisih unit'"
        :description="receipt ? undefined : 'Angka positif menambah stok, angka negatif mengurangi stok.'"
      >
        <UInput
          v-model.number="quantity"
          type="number"
          step="1"
          :min="receipt ? 1 : -2147483647"
          :max="receipt ? 10000 : 2147483647"
          required
          class="w-full"
        />
      </UFormField>
      <UFormField
        label="Kode alasan"
        description="Gunakan kode alasan aktif dari domain inventory."
      >
        <UInput
          v-model.trim="reason"
          required
          maxlength="48"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Catatan (opsional)">
        <UTextarea
          v-model="note"
          maxlength="1000"
          class="w-full"
        />
      </UFormField>
      <div class="flex justify-end gap-2 sm:col-span-2">
        <UButton
          label="Batal"
          variant="outline"
          @click="emit('cancel')"
        />
        <UButton
          type="submit"
          label="Tinjau transaksi"
          :disabled="!valid"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="emit('saved')"
    />
  </UCard>
</template>
