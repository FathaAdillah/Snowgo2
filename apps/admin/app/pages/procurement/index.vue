<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Batch, BatchList } from '~/composables/useProcurement'
import { batchStages, batchStatuses } from '~/composables/useProcurement'

definePageMeta({ permission: 'procurement.batch.view' })
useHead({ title: 'Batch Pengadaan · Snowego Admin' })
const { may } = useProcurement()
const creating = ref(false)
const number = ref('')
const state = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ number: number.value.trim() || undefined, status: state.value === 'all' ? undefined : state.value }))
const { data, status, refresh } = useFetch<BatchList>(batchApi, { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(b => ({ ...b, id: b.number })))
const columns: TableColumn<Batch & { id: string }>[] = [
  { accessorKey: 'number', header: 'Nomor batch' }, { id: 'source', header: 'Sumber', cell: ({ row }) => row.original.campaign_number ?? row.original.request_number },
  { id: 'status', header: 'Status' }, { id: 'stage', header: 'Tahap', cell: ({ row }) => batchStages[row.original.stage] }, { accessorKey: 'eta_date', header: 'ETA' }, { id: 'actions', header: 'Aksi' }
]
async function saved() {
  creating.value = false
  number.value = ''
  state.value = 'all'
  changePage(1)
  await refresh()
}
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Batch Pengadaan
        </h1>
        <p class="text-sm text-muted">
          Pantau pembelian pemasok dan jumlah diterima untuk setiap pesanan.
        </p>
      </div>
      <UButton
        v-if="may('create') && !creating"
        icon="i-lucide-plus"
        label="Tambah Batch"
        @click="creating = true"
      />
    </header>
    <ProcurementCreate
      v-if="creating && may('create')"
      @cancel="creating = false"
      @saved="saved"
    />
    <InventoryListTable
      title="Batch pengadaan"
      :columns="columns"
      :rows="rows"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="Nomor batch">
          <UInput
            v-model="number"
            type="search"
            maxlength="40"
            placeholder="Nomor lengkap"
          />
        </UFormField>
        <UFormField label="Status">
          <USelect
            v-model="state"
            :items="[{ label: 'Semua status', value: 'all' }, ...Object.entries(batchStatuses).map(([value, label]) => ({ label, value }))]"
          />
        </UFormField>
      </template>
      <template #status="{ item }">
        <UBadge variant="subtle">
          {{ batchStatuses[item.status] }}
        </UBadge>
      </template>
      <template #actions="{ item }">
        <AdminRowAction
          :to="`/procurement/${item.number}`"
          :label="`Lihat batch ${item.number}`"
          icon="i-lucide-eye"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
