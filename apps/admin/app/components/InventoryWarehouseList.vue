<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { InventoryAction, InventoryList, Warehouse } from '~/composables/useInventory'

const emit = defineEmits<{ edit: [warehouse: Warehouse] }>()
const { can } = useAdminAuth()
const search = ref('')
const active = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ q: search.value.trim() || undefined, active: active.value === 'all' ? undefined : active.value }))
const { data, status, refresh } = useFetch<InventoryList<'/admin/warehouses'>>(`${inventoryApi}/warehouses`, { ...inventoryFetch, query: params })
defineExpose({ refresh })
const action = ref<InventoryAction>()
const canEdit = computed(() => can('inventory.warehouse.update'))
const columns = computed<TableColumn<Warehouse>[]>(() => [
  { accessorKey: 'id', header: 'ID' }, { accessorKey: 'code', header: 'Kode' }, { accessorKey: 'name', header: 'Nama' },
  { accessorKey: 'district_code', header: 'Kecamatan' }, { accessorKey: 'address', header: 'Alamat' }, { id: 'status', header: 'Status' },
  ...(canEdit.value ? [{ id: 'actions', header: 'Aksi' }] : [])
])
function changeStatus(w: Warehouse) {
  if (!canEdit.value || (w.active && !can('inventory.warehouse.archive'))) return
  action.value = { url: `${inventoryApi}/warehouses/${w.id}`, method: 'PATCH', body: { active: !w.active }, label: `${w.active ? 'Arsipkan' : 'Aktifkan'} gudang ${w.code}?` }
}
async function saved() {
  action.value = undefined
  await refresh()
  if (data.value && data.value.meta.page > data.value.meta.last_page) changePage(data.value.meta.last_page)
}
</script>

<template>
  <InventoryListTable
    title="Daftar gudang"
    :columns="columns"
    :rows="data?.data ?? []"
    :status="status"
    :meta="data?.meta"
    @page="changePage"
    @retry="refresh()"
  >
    <template #filters>
      <UInput
        v-model="search"
        type="search"
        icon="i-lucide-search"
        placeholder="Cari kode atau nama gudang"
        aria-label="Cari gudang"
        maxlength="120"
      />
      <USelect
        v-model="active"
        aria-label="Status gudang"
        :items="[{ label: 'Semua status', value: 'all' }, { label: 'Aktif', value: '1' }, { label: 'Diarsipkan', value: '0' }]"
      />
    </template>
    <template #status="{ item }">
      <UBadge
        :color="item.active ? 'success' : 'neutral'"
        variant="subtle"
      >
        {{ item.is_default ? 'Utama' : item.active ? 'Aktif' : 'Diarsipkan' }}
      </UBadge>
    </template>
    <template #actions="{ item }">
      <div
        v-if="canEdit"
        class="flex gap-2"
      >
        <UButton
          square
          icon="i-lucide-pencil"
          color="neutral"
          variant="outline"
          title="Edit gudang"
          :aria-label="`Edit gudang ${item.code}`"
          @click="emit('edit', item)"
        />
        <UButton
          v-if="!item.active || can('inventory.warehouse.archive')"
          square
          :icon="item.active ? 'i-lucide-archive' : 'i-lucide-check'"
          :color="item.active ? 'error' : 'neutral'"
          variant="outline"
          :title="item.active ? 'Arsipkan' : 'Aktifkan'"
          :aria-label="`${item.active ? 'Arsipkan' : 'Aktifkan'} gudang ${item.code}`"
          @click="changeStatus(item)"
        />
      </div>
    </template>
  </InventoryListTable>
  <ConfirmAdminAction
    v-if="action"
    :action="action"
    @cancel="action = undefined"
    @saved="saved"
  />
</template>
