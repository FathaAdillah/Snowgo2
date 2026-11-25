<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Report = paths['/admin/transaction-reports']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'reporting.transaction.view' })
const range = ref(defaultRange())
const method = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ ...range.value, method: method.value === 'all' ? undefined : method.value }))
const { data, status, refresh } = useFetch<Report>('/api/v1/admin/transaction-reports', { ...inventoryFetch, query: params })
const columns = [{ accessorKey: 'posted_at', header: 'Waktu' }, { accessorKey: 'kind', header: 'Transaksi' }, { accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'invoice_number', header: 'Invoice' }, { accessorKey: 'payment_number', header: 'Pembayaran' }, { accessorKey: 'refund_number', header: 'Refund' }, { accessorKey: 'amount', header: 'Jumlah (Rp)' }]
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Laporan Transaksi
    </h1>
    <InventoryListTable
      title="Capture dan refund"
      :rows="(data?.data ?? []).map(row => ({ ...row, id: [row.kind, row.payment_number, row.invoice_number, row.refund_number].join('-') }))"
      :columns="columns"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <DashboardDateRangePicker v-model="range" />
        <UFormField label="Cara beli">
          <USelect
            v-model="method"
            :items="[{ value: 'all', label: 'Semua' }, { value: 'ready_stock', label: 'Siap kirim' }, { value: 'preorder', label: 'Preorder' }, { value: 'request_order', label: 'Request order' }]"
          />
        </UFormField>
      </template>
    </InventoryListTable>
  </section>
</template>
