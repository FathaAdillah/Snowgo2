<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Page = paths['/admin/refunds/supplier-shortages']['get']['responses'][200]['content']['application/json']
const emit = defineEmits<{ decided: [] }>()
const { can } = useAdminAuth()
const base = '/api/v1/admin/refunds/supplier-shortages'
const page = ref(1)
const { data, status, refresh } = useFetch<Page>(base, { ...inventoryFetch, query: { page } })
const selected = ref<number>()
const reason = ref('')
const amount = ref(0)
const columns = [{ accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'order_item_id', header: 'Item' }, { accessorKey: 'failed_qty', header: 'Unit kurang' }, { accessorKey: 'reason_code', header: 'Alasan pemasok' }, { id: 'actions', header: 'Aksi' }]
const { ask, action, finish } = useCaseAction(base, async () => {
  selected.value = undefined
  page.value = 1
  await refresh()
  emit('decided')
})
function select(id: number) {
  selected.value = id
  reason.value = ''
  amount.value = 0
}
</script>

<template>
  <InventoryListTable
    title="Kekurangan pemasok"
    :rows="data?.data ?? []"
    :meta="data?.meta"
    :columns="columns"
    :status="status"
    @retry="refresh()"
    @page="page = $event"
  >
    <template #actions="{ item }">
      <AdminRowAction
        :label="`Periksa kekurangan ${item.order_number} #${item.id}`"
        icon="i-lucide-eye"
        @click="select(Number(item.id))"
      />
    </template>
  </InventoryListTable>
  <UCard v-if="selected">
    <div class="flex gap-3 flex-wrap items-end">
      <UFormField :label="'Kode alasan keputusan penerimaan #' + selected">
        <UInput v-model="reason" />
      </UFormField>
      <UButton
        label="Tutup tanpa refund"
        :disabled="!reason.trim()"
        @click="ask('/' + selected + '/dismissals', { reason_code: reason }, 'Tutup review kekurangan ini tanpa refund')"
      />
      <template v-if="can('refunds.refund.create')">
        <UFormField label="Refund diminta (Rp)">
          <UInput
            v-model="amount"
            type="number"
            min="1"
          />
        </UFormField>
        <UButton
          label="Ajukan refund item"
          :disabled="!reason.trim() || amount <= 0"
          @click="ask('/' + selected + '/refunds', { requested_amount: amount, reason_code: reason }, 'Ajukan refund untuk unit yang kurang')"
        />
      </template>
      <UButton
        label="Batal"
        variant="outline"
        @click="selected = undefined"
      />
    </div>
  </UCard>
  <ConfirmAdminAction
    v-if="action"
    :action="action"
    @saved="finish"
    @cancel="action = undefined"
  />
</template>
