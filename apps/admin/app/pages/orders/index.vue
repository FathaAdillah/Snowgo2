<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Page = paths['/admin/orders']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'orders.order.view' })

const statuses = [{ label: 'Semua status', value: 'all' }, ...['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED', 'CANCELLED', 'EXPIRED'].map(value => ({ label: value, value }))]
const filter = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ status: filter.value === 'all' ? undefined : filter.value }))
const { data, status, refresh } = useFetch<Page>('/api/v1/admin/orders', { ...inventoryFetch, query: params })
const columns = [{ accessorKey: 'number', header: 'Nomor' }, { accessorKey: 'method', header: 'Cara beli' }, { accessorKey: 'payment_status', header: 'Pembayaran' }, { accessorKey: 'fulfillment_status', header: 'Pengiriman' }, { accessorKey: 'status', header: 'Status' }, { id: 'actions', header: 'Aksi' }]
</script>

<template>
  <section class="space-y-5">
    <header class="flex justify-between">
      <h1 class="text-2xl font-bold">
        Pesanan
      </h1>
    </header>

    <InventoryListTable
      title="Antrean Pesanan"
      :columns="columns"
      :rows="(data?.data ?? []).map(row => ({ ...row, id: row.number }))"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="Status">
          <USelect
            v-model="filter"
            :items="statuses"
          />
        </UFormField>
      </template>
      <template #actions="{ item }">
        <AdminRowAction
          :to="`/orders/${item.number}`"
          :label="`Lihat pesanan ${item.number}`"
          icon="i-lucide-eye"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
