<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { InventoryList } from '~/composables/useInventory'

definePageMeta({ permission: 'inventory.reservation.view' })
useHead({ title: 'Reservasi Stok · Snowego Admin' })
type Reservations = InventoryList<'/admin/stock-reservations'>
const order = ref('')
const state = ref('all')
const before = ref('')
const statuses: Record<string, string> = { ACTIVE: 'Aktif', COMMITTED: 'Dialokasikan', EXPIRED: 'Kedaluwarsa', RELEASED: 'Dilepas', CONSUMED: 'Selesai' }
const statusOptions = [{ label: 'Semua status', value: 'all' }, ...Object.entries(statuses).map(([value, label]) => ({ label, value }))]
const { params, changePage } = useInventoryPaging(() => ({
  order: order.value.trim() || undefined, status: state.value === 'all' ? undefined : state.value,
  expires_before: before.value ? `${before.value}T00:00:00+07:00` : undefined
}))
const { data, status, refresh } = useFetch<Reservations>(`${inventoryApi}/stock-reservations`, { ...inventoryFetch, query: params })
const columns: TableColumn<Reservations['data'][number]>[] = [
  { accessorKey: 'id', header: 'ID' }, { accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'sku', header: 'SKU' },
  { id: 'status', header: 'Status' }, { accessorKey: 'qty', header: 'Jumlah' }, { accessorKey: 'shipped_qty', header: 'Dikirim' },
  { accessorKey: 'released_qty', header: 'Dilepas' },
  { accessorKey: 'expires_at', header: 'Batas hold', cell: ({ row }) => inventoryTime(row.original.expires_at) }
]
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-highlighted">
        Reservasi Stok
      </h1>
      <p class="text-sm text-muted">
        Pantau stok siap kirim yang ditahan, dialokasikan, atau sudah dilepas per pesanan.
      </p>
    </header>
    <InventoryListTable
      title="Reservasi stok"
      :columns="columns"
      :rows="data?.data ?? []"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="Nomor pesanan">
          <UInput
            v-model="order"
            type="search"
            maxlength="40"
            placeholder="Nomor lengkap"
          />
        </UFormField>
        <UFormField label="Status">
          <USelect
            v-model="state"
            :items="statusOptions"
          />
        </UFormField>
        <UFormField label="Jatuh tempo sebelum (WIB)">
          <UInput
            v-model="before"
            type="date"
          />
        </UFormField>
      </template>
      <template #status="{ item }">
        <UBadge
          variant="subtle"
          :color="item.status === 'ACTIVE' ? 'warning' : item.status === 'COMMITTED' ? 'success' : 'neutral'"
        >
          {{ statuses[item.status] ?? item.status }}
        </UBadge>
      </template>
    </InventoryListTable>
  </section>
</template>
