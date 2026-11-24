<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { paths } from '@snowego/api-types'
import { movementLabels, type InventoryList } from '~/composables/useInventory'

definePageMeta({ permission: 'inventory.movement.view', key: route => route.fullPath })
useHead({ title: 'Mutasi Stok · Snowego Admin' })
type Ledger = InventoryList<'/admin/stock-movements'>
type Query = NonNullable<paths['/admin/stock-movements']['get']['parameters']['query']>
const route = useRoute()
const initial = Number(route.query.level)
const level = ref(Number.isInteger(initial) && initial > 0 && initial <= 2147483647 ? String(initial) : '')
const type = ref<NonNullable<Query['type']> | 'all'>('all')
const from = ref('')
const to = ref('')
const pages = ref<string[]>([])
const filters = ref<Query>({ level_id: Number(level.value) || undefined })
const query = computed(() => ({ ...filters.value, cursor: pages.value.at(-1) }))
const { data, status, refresh } = useFetch<Ledger>(`${inventoryApi}/stock-movements`, { ...inventoryFetch, query })
function apply() {
  pages.value = []
  filters.value = {
    level_id: Number(level.value) || undefined, type: type.value === 'all' ? undefined : type.value,
    from: from.value ? `${from.value}T00:00:00+07:00` : undefined,
    to: to.value ? `${to.value}T23:59:59.999999+07:00` : undefined
  }
}
function next() {
  if (status.value === 'success' && data.value?.meta.next_cursor) pages.value.push(data.value.meta.next_cursor)
}
const columns: TableColumn<Ledger['data'][number]>[] = [
  { accessorKey: 'id', header: 'Mutasi' }, { accessorKey: 'occurred_at', header: 'Waktu', cell: ({ row }) => inventoryTime(row.original.occurred_at) },
  { accessorKey: 'level_id', header: 'ID saldo' }, { accessorKey: 'type', header: 'Jenis', cell: ({ row }) => movementLabels[row.original.type] ?? row.original.type },
  { accessorKey: 'on_hand_delta', header: 'Perubahan fisik' }, { accessorKey: 'reason_code', header: 'Alasan' }
]
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-highlighted">
        Mutasi Stok
      </h1><p class="text-sm text-muted">
        Riwayat penerimaan dan koreksi, dari yang terbaru. Mutasi yang sudah tercatat tidak dapat diedit.
      </p>
    </header>
    <InventoryListTable
      title="Riwayat mutasi"
      :rows="data?.data ?? []"
      :columns="columns"
      :status="status"
      @retry="refresh()"
    >
      <template #filters>
        <form
          class="flex flex-wrap items-end gap-2"
          @submit.prevent="apply"
        >
          <UFormField label="ID saldo">
            <UInput
              v-model="level"
              type="number"
              min="1"
              max="2147483647"
              placeholder="Semua saldo"
            />
          </UFormField>
          <UFormField label="Jenis">
            <USelect
              v-model="type"
              :items="[{ label: 'Semua jenis', value: 'all' }, ...Object.entries(movementLabels).map(([value, label]) => ({ value, label }))]"
            />
          </UFormField>
          <UFormField label="Dari (WIB)">
            <UInput
              v-model="from"
              type="date"
              :max="to || undefined"
            />
          </UFormField>
          <UFormField label="Sampai (WIB)">
            <UInput
              v-model="to"
              type="date"
              :min="from || undefined"
            />
          </UFormField>
          <UButton
            type="submit"
            label="Terapkan"
            variant="outline"
          />
        </form>
      </template>
      <template #cursor>
        <template v-if="data?.data.length || pages.length">
          <UButton
            label="Sebelumnya"
            icon="i-lucide-chevron-left"
            variant="outline"
            color="neutral"
            :disabled="!pages.length"
            @click="pages.pop()"
          />
          <span class="text-sm text-muted">Halaman {{ pages.length + 1 }}</span>
          <UButton
            label="Berikutnya"
            trailing-icon="i-lucide-chevron-right"
            variant="outline"
            color="neutral"
            :disabled="!data?.meta.next_cursor"
            @click="next"
          />
        </template>
      </template>
    </InventoryListTable>
  </section>
</template>
