<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { InventoryList } from '~/composables/useInventory'

definePageMeta({ permission: 'inventory.stock.view' })
useHead({ title: 'Saldo Stok · Snowego Admin' })
type Levels = InventoryList<'/admin/stock-levels'>
const { can } = useAdminAuth()
const sku = ref('')
const warehouse = ref('')
const low = ref('all')
const { params, changePage } = useInventoryPaging(() => ({
  sku: sku.value.trim() || undefined, warehouse: warehouse.value.trim() || undefined, low_stock: low.value === 'all' ? undefined : low.value
}))
const { data, status, refresh } = useFetch<Levels>(`${inventoryApi}/stock-levels`, { ...inventoryFetch, query: params })
const columns = computed<TableColumn<Levels['data'][number]>[]>(() => [
  { accessorKey: 'sku', header: 'SKU' }, { accessorKey: 'warehouse_id', header: 'ID gudang' }, { accessorKey: 'id', header: 'ID saldo' },
  { accessorKey: 'on_hand', header: 'Fisik' }, { accessorKey: 'reserved', header: 'Direservasi' }, { accessorKey: 'committed', header: 'Dialokasikan' },
  { accessorKey: 'available', header: 'Tersedia' }, { accessorKey: 'reorder_point', header: 'Batas minimum' },
  ...(can('inventory.adjustment.create') || can('inventory.movement.view') ? [{ id: 'actions', header: 'Aksi' }] : [])
])
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-highlighted">
        Saldo Stok
      </h1><p class="text-sm text-muted">
        Jumlah fisik dan stok yang masih tersedia per SKU dan gudang.
      </p>
    </header>
    <InventoryListTable
      title="Saldo stok"
      :columns="columns"
      :rows="data?.data ?? []"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UInput
          v-model="sku"
          type="search"
          icon="i-lucide-search"
          placeholder="SKU lengkap"
          aria-label="Filter SKU"
          maxlength="80"
        />
        <UInput
          v-model="warehouse"
          placeholder="Kode gudang"
          aria-label="Filter kode gudang"
          maxlength="32"
        />
        <USelect
          v-model="low"
          aria-label="Filter saldo"
          :items="[{ label: 'Semua saldo', value: 'all' }, { label: 'Stok rendah', value: '1' }, { label: 'Di atas minimum', value: '0' }]"
        />
      </template>
      <template #actions="{ item }">
        <div class="flex gap-2">
          <AdminRowAction
            v-if="can('inventory.movement.view')"
            :to="{ path: '/inventory/movements', query: { level: item.id } }"
            :label="`Lihat mutasi saldo ${item.id}`"
            icon="i-lucide-history"
          />
          <AdminRowAction
            v-if="can('inventory.adjustment.create')"
            :to="{ path: '/inventory/operations', query: { level: item.id } }"
            :label="`Koreksi saldo ${item.id}`"
            icon="i-lucide-pencil"
          />
        </div>
      </template>
    </InventoryListTable>
  </section>
</template>
