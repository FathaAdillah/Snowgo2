<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { BatchAction, EligibleItem, EligibleList } from '~/composables/useProcurement'

const emit = defineEmits<{ cancel: [], saved: [] }>()
const search = ref('')
const { params, changePage } = useInventoryPaging(() => ({ number: search.value.trim() || undefined }))
const { data, status, refresh } = useFetch<EligibleList>(`${batchApi}/eligible-items`, { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(i => ({ ...i, id: i.order_item_id })))
const selected = ref<{ item: EligibleItem, qty: number }[]>([])
const source = (i: EligibleItem) => i.campaign_number ?? i.request_number
const compatible = (i: EligibleItem) => !selected.value.length || (selected.value[0]?.item.campaign_number === i.campaign_number && selected.value[0]?.item.request_number === i.request_number)
const has = (i: EligibleItem) => selected.value.some(s => s.item.order_item_id === i.order_item_id)
const columns: TableColumn<EligibleItem & { id: string }>[] = [
  { id: 'source', header: 'Sumber', cell: ({ row }) => source(row.original) }, { accessorKey: 'order_number', header: 'Pesanan' },
  { accessorKey: 'sku', header: 'SKU' }, { accessorKey: 'available_qty', header: 'Tersedia' }, { id: 'actions', header: 'Pilih' }
]
const supplier = ref('')
const eta = ref('')
const notes = ref('')
const action = ref<BatchAction>()
function submit() {
  const first = selected.value[0]?.item
  if (!first || selected.value.some(({ qty, item }) => !Number.isInteger(qty) || qty < 1 || qty > item.available_qty)) return
  const body = {
    ...(first.campaign_number ? { campaign_number: first.campaign_number } : { request_number: first.request_number }),
    ...(supplier.value.trim() && { supplier_ref: supplier.value.trim() }), ...(eta.value && { eta_date: eta.value }),
    ...(notes.value.trim() && { notes: notes.value.trim() }), items: selected.value.map(({ item, qty }) => ({ order_item_id: item.order_item_id, qty }))
  }
  action.value = { url: batchApi, method: 'POST', body, label: `Buat batch untuk ${source(first)} dengan ${selected.value.reduce((sum, s) => sum + s.qty, 0)} unit?` }
}
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        Tambah batch dari unit terkonfirmasi
      </h2>
    </template>
    <p class="mb-3 text-sm text-muted">
      Pilih item dari satu kampanye atau satu request. Jika belum ada pembayaran awal terkonfirmasi, pilihan akan kosong.
    </p>
    <InventoryListTable
      title="Item yang dapat ditugaskan"
      :columns="columns"
      :rows="rows"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="Nomor kampanye, request, atau pesanan">
          <UInput
            v-model="search"
            type="search"
            maxlength="40"
            placeholder="Nomor lengkap"
          />
        </UFormField>
      </template>
      <template #actions="{ item }">
        <UButton
          :label="has(item) ? 'Dipilih' : 'Pilih'"
          :aria-label="`Pilih ${item.order_number} ${item.sku ?? item.order_item_id}`"
          variant="outline"
          :disabled="has(item) || !compatible(item) || selected.length >= 200"
          @click="selected.push({ item, qty: 1 })"
        />
      </template>
    </InventoryListTable>
    <form
      class="mt-4 space-y-4"
      @submit.prevent="submit"
    >
      <div
        v-for="(row, index) in selected"
        :key="row.item.order_item_id"
        class="flex flex-wrap items-end gap-2"
      >
        <UFormField :label="`Jumlah · ${row.item.order_number} · ${row.item.sku ?? row.item.order_item_id}`">
          <UInput
            v-model.number="row.qty"
            type="number"
            min="1"
            :max="row.item.available_qty"
            step="1"
            required
          />
        </UFormField>
        <UButton
          label="Lepas pilihan"
          variant="ghost"
          @click="selected.splice(index, 1)"
        />
      </div>
      <div class="flex flex-wrap gap-3">
        <UFormField label="Referensi pemasok">
          <UInput
            v-model="supplier"
            maxlength="160"
          />
        </UFormField>
        <UFormField label="Estimasi tiba">
          <UInput
            v-model="eta"
            type="date"
          />
        </UFormField>
        <UFormField label="Catatan">
          <UTextarea
            v-model="notes"
            maxlength="1000"
          />
        </UFormField>
      </div>
      <div class="flex gap-2">
        <UButton
          type="submit"
          label="Buat batch"
          :disabled="!selected.length"
        /><UButton
          variant="outline"
          label="Batal"
          @click="emit('cancel')"
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
