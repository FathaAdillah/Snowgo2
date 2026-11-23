<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { InventoryList } from '~/composables/useInventory'

type Adjustments = InventoryList<'/admin/stock-adjustments'>
const page = ref(1)
const { data, status, refresh } = useFetch<Adjustments>(`${inventoryApi}/stock-adjustments`, { ...inventoryFetch, query: { page } })
defineExpose({ refresh })
const columns: TableColumn<Adjustments['data'][number]>[] = [
  { accessorKey: 'id', header: 'Koreksi' }, { accessorKey: 'created_at', header: 'Waktu', cell: ({ row }) => inventoryTime(row.original.created_at) },
  { accessorKey: 'level_id', header: 'ID saldo' }, { accessorKey: 'delta', header: 'Perubahan fisik' }, { accessorKey: 'reason_code', header: 'Alasan' }
]
</script>

<template>
  <div class="space-y-3">
    <h2 class="font-semibold text-highlighted">
      Riwayat koreksi
    </h2>
    <InventoryListTable
      title="Riwayat koreksi"
      :rows="data?.data ?? []"
      :columns="columns"
      :status="status"
      :meta="data?.meta"
      @retry="refresh()"
      @page="page = $event"
    />
  </div>
</template>
