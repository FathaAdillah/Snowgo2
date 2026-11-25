<script setup lang="ts">
import type { InvoiceDetail } from '~/composables/useInvoices'
import { invoiceMoney, invoicePdfStatus, invoicePurpose, invoiceStatus, invoicesApi } from '~/composables/useInvoices'
import { inventoryTime } from '~/composables/useInventory'
import type { FileDownload } from '~/composables/useRequestOrders'

definePageMeta({ permission: 'invoicing.invoice.view', key: route => route.path })
const number = String(useRoute().params.number)
const url = `${invoicesApi}/${encodeURIComponent(number)}`
useHead({ title: `${number} · Invoice` })
const { data, status, refresh } = useFetch<InvoiceDetail>(url, inventoryFetch)
const invoice = computed(() => data.value?.data)
const { can } = useAdminAuth()
const may = (action: string) => can('invoicing.invoice.manage') && can(`invoicing.invoice.${action}`)
const mutable = computed(() => !!invoice.value && ['PENDING_PAYMENT', 'CONFIRMED'].includes(invoice.value.order_status))
const canVoid = computed(() => mutable.value && invoice.value?.status === 'ISSUED' && may('void'))
const canIssue = computed(() => mutable.value && invoice.value?.status === 'VOID' && !invoice.value.replaced_by_invoice_number && may('issue'))
const canRevise = computed(() => invoice.value?.order_status === 'CONFIRMED' && invoice.value.status === 'ISSUED' && Date.parse(invoice.value.due_at) < Date.now() && may('revise_due'))
const reason = ref('')
const itemRef = ref('')
const chargeType = ref('IMPORT_TAX')
const qty = ref(1)
const fee = ref<number>()
const description = ref('')
const quote = ref('')
const due = ref('')
const dueIso = computed(() => due.value && Number.isFinite(Date.parse(due.value)) ? new Date(due.value).toISOString() : '')
const error = ref('')
const action = ref<{ method: 'POST', url: string, label: string, body?: Record<string, unknown> }>()
async function download() {
  error.value = ''
  try {
    const response = await $fetch<FileDownload>(`${url}/pdf`, { cache: 'no-store' })
    window.location.assign(response.data.url)
  } catch (err) { error.value = authErrorMessage(err) }
}
async function saved() {
  action.value = undefined
  reason.value = due.value = ''
  await refresh()
}
</script>

<template>
  <section class="min-w-0 space-y-5">
    <UButton
      to="/invoices"
      variant="link"
      icon="i-lucide-arrow-left"
      label="Daftar invoice"
    />
    <h1 class="text-2xl font-bold text-highlighted">
      Invoice {{ number }}
    </h1>
    <UAlert
      v-if="status === 'error'"
      color="error"
      title="Invoice tidak dapat dimuat."
      :actions="[{ label: 'Coba lagi', onClick: () => refresh() }]"
    />
    <p
      v-else-if="!invoice"
      role="status"
    >
      Memuat tagihan…
    </p>
    <template v-else>
      <UCard v-if="invoice.order_status === 'CONFIRMED' && (may('issue') || may('credit_adjust') || (can('orders.order.view') && can('orders.order.manage') && can('orders.charge.propose')))">
        <template #header>
          Termin dan biaya pesanan
        </template>
        <div class="flex flex-wrap items-end gap-3">
          <UFormField label="Batas waktu">
            <UInput
              v-model="due"
              type="datetime-local"
            />
          </UFormField>
          <UButton
            v-if="invoice.purpose === 'DEPOSIT' && invoice.status === 'PAID' && may('issue')"
            label="Tagih saldo barang diterima"
            :disabled="!dueIso"
            @click="action = { method: 'POST', url: `/api/v1/admin/orders/${invoice.order_number}/invoices`, body: { purpose: 'BALANCE', due_at: dueIso }, label: 'Hitung sisa setelah DP untuk unit diterima yang belum ditagih' }"
          />
          <UFormField label="Item pesanan">
            <USelect
              v-model="itemRef"
              :items="invoice.items.filter(x => x.order_item_id).map(x => ({ label: x.description, value: x.order_item_id! }))"
            />
          </UFormField>
          <template v-if="invoice.status === 'ISSUED' && invoice.purpose === 'BALANCE' && may('credit_adjust')">
            <UFormField label="Kode alasan shortage">
              <UInput v-model="reason" />
            </UFormField>
            <UButton
              label="Koreksi shortage"
              :disabled="!itemRef || !reason"
              @click="action = { method: 'POST', url: `/api/v1/admin/orders/${invoice.order_number}/credit-adjustments`, body: { invoice_number: invoice.number, order_item_id: itemRef, reason_code: reason }, label: 'Batalkan dan hitung ulang cakupan invoice akibat shortage' }"
            />
          </template>
          <template v-if="can('orders.order.view') && can('orders.order.manage') && can('orders.charge.propose')">
            <UFormField label="Jenis biaya">
              <USelect
                v-model="chargeType"
                :items="['IMPORT_TAX', 'INTERNATIONAL_SHIPPING', 'SERVICE_FEE', 'DOMESTIC_SHIPPING']"
              />
            </UFormField>
            <UFormField
              v-if="chargeType === 'DOMESTIC_SHIPPING'"
              label="ID quote paket"
            >
              <UInput v-model="quote" />
            </UFormField>
            <template v-else>
              <UFormField label="Jumlah unit">
                <UInput
                  v-model.number="qty"
                  type="number"
                  min="1"
                />
              </UFormField>
              <UFormField label="Biaya per unit (IDR)">
                <UInput
                  v-model.number="fee"
                  type="number"
                  min="1"
                />
              </UFormField>
              <UFormField label="Rincian biaya">
                <UInput v-model="description" />
              </UFormField>
            </template>
            <UButton
              label="Ajukan biaya ke pelanggan"
              :disabled="!dueIso || (chargeType === 'DOMESTIC_SHIPPING' ? !quote : !itemRef || !fee || !description || qty < 1)"
              @click="action = { method: 'POST', url: `/api/v1/admin/orders/${invoice.order_number}/charge-consents`, body: { type: chargeType, expires_at: dueIso, ...(chargeType === 'DOMESTIC_SHIPPING' ? { quote_id: quote } : { order_item_id: itemRef, qty, unit_amount: fee, description }) }, label: 'Kirim usulan; tagihan baru terbit setelah diterima pelanggan' }"
            />
          </template>
        </div>
      </UCard>
      <UCard>
        <p>Pesanan {{ invoice.order_number }} · {{ invoicePurpose[invoice.purpose] }} · {{ invoiceStatus[invoice.status] }}</p>
        <p>Terbit {{ inventoryTime(invoice.issued_at) }} · Jatuh tempo {{ inventoryTime(invoice.due_at) }}</p>
        <UButton
          v-if="invoice.replaces_invoice_number"
          variant="link"
          :to="`/invoices/${invoice.replaces_invoice_number}`"
          :label="`Menggantikan ${invoice.replaces_invoice_number}`"
        />
        <UTable
          :data="invoice.items"
          :columns="[{ accessorKey: 'description', header: 'Uraian' }, { accessorKey: 'qty', header: 'Jumlah' }, { accessorKey: 'amount', header: 'Nilai', cell: ({ row }) => invoiceMoney(row.original.amount) }]"
        />
        <UButton
          v-if="invoice.replaced_by_invoice_number"
          variant="link"
          :to="`/invoices/${invoice.replaced_by_invoice_number}`"
          :label="`Lihat invoice pengganti ${invoice.replaced_by_invoice_number}`"
        />
        <p>Subtotal {{ invoiceMoney(invoice.subtotal_amount) }} · Diskon {{ invoiceMoney(invoice.discount_amount) }} · Pajak {{ invoiceMoney(invoice.tax_amount) }} · Ongkir {{ invoiceMoney(invoice.shipping_amount) }}</p>
        <p class="font-bold">
          Total tagihan: {{ invoiceMoney(invoice.total_amount) }}
        </p>
      </UCard>
      <UCard>
        <div class="flex flex-wrap items-center gap-3">
          <span>PDF: {{ invoicePdfStatus[invoice.pdf_status] }}</span>
          <UButton
            v-if="invoice.pdf_ready && can('invoicing.invoice.download') && can('foundation.file.view') && can('foundation.file.download')"
            label="Unduh PDF privat"
            @click="download"
          />
          <UButton
            v-if="!invoice.pdf_ready"
            variant="outline"
            label="Perbarui status"
            @click="refresh()"
          />
          <UButton
            v-if="invoice.pdf_status === 'FAILED' && may('retry_pdf')"
            label="Ulangi pembuatan PDF"
            @click="action = { method: 'POST', url: `${url}/pdf-requests`, label: `Buat ulang PDF ${number}` }"
          />
        </div>
        <UAlert
          v-if="error"
          color="error"
          :title="error"
        />
      </UCard>
      <UCard v-if="canVoid || canRevise || canIssue">
        <div class="flex flex-wrap items-end gap-3">
          <UFormField
            v-if="invoice.status === 'ISSUED'"
            label="Kode alasan aktif"
          >
            <UInput
              v-model="reason"
              maxlength="48"
            />
          </UFormField>
          <UFormField
            v-if="canIssue || canRevise"
            label="Jatuh tempo baru (waktu perangkat)"
          >
            <UInput
              v-model="due"
              type="datetime-local"
            />
          </UFormField>
          <UButton
            v-if="canVoid"
            color="error"
            label="Batalkan invoice"
            :disabled="!reason.trim()"
            @click="action = { method: 'POST', url: `${url}/voids`, label: `Batalkan ${number} dengan alasan ${reason}`, body: { reason_code: reason.trim() } }"
          />
          <UButton
            v-if="canRevise"
            label="Revisi jatuh tempo"
            :disabled="!dueIso || !reason.trim()"
            @click="action = { method: 'POST', url: `${url}/due-date-revisions`, label: `Ganti ${number} dengan tagihan jatuh tempo ${due}`, body: { due_at: dueIso, reason_code: reason.trim() } }"
          />
          <UButton
            v-if="canIssue"
            label="Terbitkan pengganti"
            :disabled="!dueIso"
            @click="action = { method: 'POST', url: `/api/v1/admin/orders/${invoice.order_number}/invoices`, label: `Terbitkan pengganti ${number} dengan nominal yang sama, jatuh tempo ${due}`, body: { purpose: invoice.purpose, due_at: dueIso, replaces_invoice_number: number } }"
          />
        </div>
      </UCard>
    </template>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="saved"
    />
  </section>
</template>
