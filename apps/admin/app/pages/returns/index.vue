<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Page = paths['/admin/returns']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'returns.return.view' })
const { can } = useAdminAuth()
const creating = ref(false)
const statuses = [{ label: 'Semua status', value: 'all' }, ...['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'AWAITING_SHIPMENT', 'IN_TRANSIT', 'RECEIVED', 'INSPECTING', 'ACCEPTED', 'INSPECTION_FAILED', 'RESOLVED', 'REJECTED', 'CANCELLED'].map(value => ({ label: value, value }))]
const filter = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ status: filter.value === 'all' ? undefined : filter.value }))
const { data, status, refresh } = useFetch<Page>('/api/v1/admin/returns', { ...inventoryFetch, query: params })
const columns = [{ accessorKey: 'number', header: 'Nomor' }, { accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'status', header: 'Status' }, { id: 'actions', header: 'Aksi' }]
</script>

<template>
  <section class="space-y-5">
    <header class="flex justify-between">
      <h1 class="text-2xl font-bold">
        Return
      </h1>
      <UButton
        v-if="can('returns.return.create') && !creating"
        label="Ajukan Return"
        @click="creating = true"
      />
    </header>
    <CaseCreate
      v-if="creating && can('returns.return.create')"
      mode="return"
      @close="creating = false"
      @saved="creating = false; changePage(1); refresh()"
    />
    <InventoryListTable
      title="Antrean Return"
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
        <UButton
          label="Detail"
          variant="outline"
          :to="`/returns/${item.number}`"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
