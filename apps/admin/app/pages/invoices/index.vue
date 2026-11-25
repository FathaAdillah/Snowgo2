<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { InvoicePage } from '~/composables/useInvoices'
import { invoiceMoney, invoicePurpose, invoiceStatus, invoicesApi } from '~/composables/useInvoices'

definePageMeta({ permission: 'invoicing.invoice.view' })
useHead({ title: 'Invoice · Snowego Admin' })
const order = ref('')
const state = ref('all')
const { params, changePage } = useInventoryPaging(() => ({ order: order.value.trim() || undefined, status: state.value === 'all' ? undefined : state.value }))
const { data, status, refresh } = useFetch<InvoicePage>(invoicesApi, { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(r => ({ ...r, id: r.number })))
const columns: TableColumn<InvoicePage['data'][number] & { id: string }>[] = [
  { accessorKey: 'number', header: 'Invoice' }, { accessorKey: 'order_number', header: 'Pesanan' },
  { accessorKey: 'purpose', header: 'Jenis', cell: ({ row }) => invoicePurpose[row.original.purpose] },
  { accessorKey: 'total_amount', header: 'Total', cell: ({ row }) => invoiceMoney(row.original.total_amount) },
  { accessorKey: 'status', header: 'Status', cell: ({ row }) => invoiceStatus[row.original.status] },
  { accessorKey: 'due_at', header: 'Jatuh tempo', cell: ({ row }) => inventoryTime(row.original.due_at) }, { id: 'actions', header: 'Aksi' }
]
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-highlighted">
        Invoice
      </h1><p class="text-sm text-muted">
        Telusuri tagihan dan dokumen per pesanan.
      </p>
    </header>
    <InventoryListTable
      title="Daftar invoice"
      :columns="columns"
      :rows="rows"
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
            :items="[{ label: 'Semua status', value: 'all' }, ...Object.entries(invoiceStatus).map(([value, label]) => ({ value, label }))]"
          />
        </UFormField>
      </template>
      <template #actions="{ item }">
        <UButton
          icon="i-lucide-eye"
          square
          variant="outline"
          :to="`/invoices/${item.number}`"
          :aria-label="`Lihat invoice ${item.number}`"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
