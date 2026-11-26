<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { RequestCase, RequestQueue } from '~/composables/useRequestOrders'
import { caseStatuses } from '~/composables/useRequestOrders'

definePageMeta({ permission: 'request_orders.request.view' })
useHead({ title: 'Request Order · Snowego Admin' })
const number = ref('')
const state = ref('all')
const from = ref('')
const to = ref('')
const { params, changePage } = useInventoryPaging(() => ({
  number: number.value.trim() || undefined, status: state.value === 'all' ? undefined : state.value,
  from: from.value || undefined, to: to.value || undefined
}))
const { data, status, refresh } = useFetch<RequestQueue>(caseApi, { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(r => ({ ...r, id: r.number })))
const columns: TableColumn<RequestCase & { id: string }>[] = [
  { accessorKey: 'number', header: 'Nomor' }, { accessorKey: 'title', header: 'Judul' }, { id: 'status', header: 'Status' },
  { accessorKey: 'created_at', header: 'Diajukan', cell: ({ row }) => inventoryTime(row.original.created_at) },
  { accessorKey: 'target_date', header: 'Target' }, { id: 'actions', header: 'Aksi' }
]
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-highlighted">
        Request Order
      </h1>
      <p class="text-sm text-muted">
        Tinjau barang yang diminta dan tanggapi pelanggan.
      </p>
    </header>
    <InventoryListTable
      title="Antrean request"
      :columns="columns"
      :rows="rows"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="Nomor request">
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
            :items="[{ label: 'Semua status', value: 'all' }, ...Object.entries(caseStatuses).map(([value, label]) => ({ value, label }))]"
          />
        </UFormField>
        <UFormField label="Diajukan dari (WIB)">
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
      </template>
      <template #status="{ item }">
        <UBadge variant="subtle">
          {{ caseStatuses[item.status] }}
        </UBadge>
      </template>
      <template #actions="{ item }">
        <AdminRowAction
          :to="`/request-orders/${item.number}`"
          :label="`Lihat request ${item.number}`"
          icon="i-lucide-eye"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
