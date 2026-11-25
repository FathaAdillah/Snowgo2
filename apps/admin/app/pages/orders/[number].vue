<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Detail = paths['/admin/orders/{number}']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'orders.order.view', key: route => route.fullPath })
const { can } = useAdminAuth()
const number = String(useRoute().params.number)
const base = '/api/v1/admin/orders/' + encodeURIComponent(number)
const { data, status, refresh } = useFetch<Detail>(base, inventoryFetch)
const row = computed(() => data.value?.data)
const { action, ask, finish } = useCaseAction(base, refresh)
const reason = ref('')
const note = ref('')
function change(next: 'CANCEL' | 'COMPLETE') {
  ask('/actions/' + next, { ...(reason.value && { reason_code: reason.value }), ...(note.value && { note: note.value }) }, next === 'CANCEL' ? 'Batalkan pesanan dan lepaskan reservasi' : 'Konfirmasi pesanan selesai')
}
</script>

<template>
  <section class="space-y-5">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">
        Pesanan {{ number }}
      </h1><UButton
        variant="ghost"
        label="Muat ulang"
        @click="refresh()"
      />
    </header>
    <UAlert
      v-if="status === 'error'"
      color="error"
      title="Detail gagal dimuat"
    />
    <p
      v-else-if="!row"
      role="status"
    >
      Memuat…
    </p>
    <template v-else>
      <UCard>
        <p>{{ row.method }} · {{ row.status }} · {{ row.payment_status }} · {{ row.fulfillment_status }}</p><p>{{ formatRupiah(row.total_amount) }}</p>
        <p
          v-for="item in row.items"
          :key="String(item.id)"
        >
          {{ item.name }} · {{ item.sku }} · {{ item.qty }} unit · {{ item.id }}
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            v-for="invoice in row.invoice_numbers"
            v-show="can('invoicing.invoice.view')"
            :key="invoice"
            :label="invoice"
            variant="link"
            :to="'/invoices/' + invoice"
          />
          <UButton
            v-for="payment in row.payment_numbers"
            v-show="can('payments.payment.view')"
            :key="payment"
            :label="payment"
            variant="link"
            :to="'/finance/payments/' + payment"
          />
        </div>
        <p
          v-for="entry in row.history"
          :key="entry.occurred_at + entry.dimension"
        >
          {{ entry.occurred_at }} · {{ entry.dimension }} · {{ entry.to_status }}
        </p>
      </UCard>
      <div
        v-if="can('orders.order.manage') && ((can('orders.order.cancel') && row.status === 'PENDING_PAYMENT') || (can('orders.order.complete') && row.status === 'CONFIRMED' && row.fulfillment_status === 'DELIVERED'))"
        class="flex flex-wrap items-end gap-2"
      >
        <UFormField label="Kode alasan">
          <UInput v-model="reason" />
        </UFormField>
        <UFormField label="Catatan">
          <UInput v-model="note" />
        </UFormField>
        <UButton
          v-if="row.status === 'PENDING_PAYMENT'"
          label="Batalkan pesanan"
          @click="change('CANCEL')"
        />
        <UButton
          v-else
          label="Selesaikan pesanan"
          @click="change('COMPLETE')"
        />
      </div>
    </template>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="finish"
      @cancel="action = undefined"
    />
  </section>
</template>
