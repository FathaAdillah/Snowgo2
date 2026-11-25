<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import { invoiceMoney } from '~/composables/useInvoices'

type Page = paths['/admin/payments']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'payments.payment.view' })
useHead({ title: 'Pembayaran · Finance' })
const order = ref('')
const state = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ order: order.value.trim() || undefined, status: state.value === 'all' ? undefined : state.value }))
const { data, status, refresh } = useFetch<Page>('/api/v1/admin/payments', { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(p => ({ ...p, id: p.number, nominal: invoiceMoney(p.amount) })))
const states = ['UNPAID', 'WAITING_CONFIRMATION', 'UNDER_REVIEW', 'REJECTED', 'CANCELLED', 'EXPIRED', 'PAID']
</script>

<template>
  <section class="min-w-0 space-y-6">
    <h1 class="text-2xl font-bold">
      Pembayaran
    </h1>
    <InventoryListTable
      title="Antrean pembayaran"
      :status="status"
      :rows="rows"
      :meta="data?.meta"
      :columns="[{ accessorKey: 'number', header: 'Nomor' }, { accessorKey: 'nominal', header: 'Jumlah' }, { accessorKey: 'status', header: 'Status' }, { id: 'actions', header: 'Aksi' }]"
      @retry="refresh()"
      @page="changePage"
    >
      <template #filters>
        <UFormField label="Nomor pesanan">
          <UInput v-model="order" />
        </UFormField>
        <UFormField label="Status">
          <USelect
            v-model="state"
            :items="[{ label: 'Semua', value: 'all' }, ...states.map(value => ({ label: value, value }))]"
          />
        </UFormField>
      </template>
      <template #actions="{ item }">
        <UButton
          variant="outline"
          icon="i-lucide-eye"
          :to="`/finance/payments/${item.number}`"
          :aria-label="`Lihat ${item.number}`"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
