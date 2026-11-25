<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import { invoiceMoney } from '~/composables/useInvoices'

type Page = paths['/admin/payment-reconciliations']['get']['responses'][200]['content']['application/json']
type Receipt = Page['data'][number]
definePageMeta({ permission: 'payments.reconciliation.view' })
const { can, csrf, stepUp } = useAdminAuth()
const { params, changePage } = useInventoryPaging(() => ({}))
const base = '/api/v1/admin/payment-reconciliations'
const { data, status, refresh } = useFetch<Page>(base, { ...inventoryFetch, query: params })
const selected = ref<Receipt>()
const payment = ref('')
const reference = ref('')
const amount = ref<number>()
const reason = ref('')
const note = ref('')
const proof = ref('')
const uploadError = ref('')
const password = ref('')
const totp = ref('')
async function upload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!selected.value || !file) return
  const form = new FormData()
  form.set('file', file)
  form.set('owner_type', 'payment_reconciliation')
  form.set('owner_ref', String(selected.value.id))
  form.set('collection', 'reconciliation_proof')
  const id = selected.value.id
  try {
    await stepUp(password.value, totp.value)
    const result = await $fetch<{ data: { id: string } }>('/api/v1/admin/files', { method: 'POST', body: form, headers: { 'X-XSRF-TOKEN': await csrf(), 'Idempotency-Key': crypto.randomUUID() } })
    if (selected.value?.id === id) proof.value = result.data.id
    uploadError.value = ''
  } catch (e) { uploadError.value = authErrorMessage(e) } finally { password.value = totp.value = ''; (event.target as HTMLInputElement).value = '' }
}
const action = ref<{ url: string, method: 'POST', body: Record<string, unknown>, label: string }>()
function ask(url: string, body: Record<string, unknown>, label: string) {
  action.value = { url, method: 'POST', body, label }
}
function select(id: unknown) {
  selected.value = data.value?.data.find(r => r.id === id)
  reference.value = reason.value = note.value = proof.value = ''
}
async function saved() {
  action.value = selected.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Rekonsiliasi pembayaran
    </h1>
    <InventoryListTable
      title="Mutasi pengecualian"
      :rows="data?.data ?? []"
      :status="status"
      :meta="data?.meta"
      :columns="[{ accessorKey: 'payment_number', header: 'Pembayaran' }, { accessorKey: 'bank_tx_ref', header: 'Mutasi' }, { accessorKey: 'status', header: 'Status' }, { id: 'actions', header: 'Aksi' }]"
      @page="changePage"
      @retry="refresh()"
    >
      <template #actions="{ item }">
        <UButton
          label="Detail"
          variant="outline"
          @click="select(item.id)"
        />
      </template>
    </InventoryListTable>
    <UCard v-if="selected">
      <p>{{ selected.payment_number }} · {{ invoiceMoney(selected.amount) }} · {{ selected.bank_tx_ref }} · {{ selected.reason_code }}</p>
      <p>{{ selected.review_note }} {{ selected.decision_note }} {{ selected.refund_transfer_ref }}</p>
      <div class="flex flex-wrap items-end gap-2">
        <template v-if="selected.status === 'OPEN' && !selected.opened_by_me && can('payments.reconciliation.review')">
          <UFormField label="Catatan pemeriksa">
            <UInput v-model="note" />
          </UFormField>
          <UButton
            label="Setujui kasus"
            :disabled="!note.trim()"
            @click="ask(`${base}/${selected.id}/reviews`, { decision: 'APPROVE', note }, 'Setujui hasil pencocokan bank')"
          />
          <UFormField label="Kode alasan penolakan">
            <UInput v-model="reason" />
          </UFormField>
          <UButton
            label="Tolak kasus"
            :disabled="!note.trim() || !reason.trim()"
            @click="ask(`${base}/${selected.id}/reviews`, { decision: 'REJECT', reason_code: reason, note }, 'Tolak rekonsiliasi')"
          />
        </template>
        <template v-if="selected.status === 'APPROVED' && !selected.reviewed_by_me && can('payments.payment.recover')">
          <UFormField label="Catatan keputusan">
            <UInput v-model="note" />
          </UFormField>
          <UButton
            label="Terapkan dana"
            :disabled="!note.trim()"
            @click="ask(`${base}/${selected.id}/decisions`, { decision: 'APPLY', note }, 'Pulihkan dan lunasi seluruh invoice terkait')"
          />
          <UFormField label="Referensi transfer balik">
            <UInput v-model="reference" />
          </UFormField>
          <template v-if="can('foundation.file.create')">
            <UFormField label="Password untuk konfirmasi unggah"><UInput v-model="password" type="password" autocomplete="current-password" /></UFormField>
            <UFormField label="Kode authenticator"><UInput v-model="totp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" /></UFormField>
          </template>
          <UFormField
            v-if="can('foundation.file.create')"
            label="Unggah bukti, tunggu pemindaian sebelum mencatat transfer"
          >
            <input
              :disabled="!password || totp.length !== 6"
              type="file"
              accept="image/png,image/jpeg,application/pdf"
              @change="upload"
            >
          </UFormField>
          <UAlert
            v-if="uploadError"
            :title="uploadError"
            color="error"
          />
          <UFormField label="ID bukti transfer balik READY">
            <UInput v-model="proof" />
          </UFormField>
          <UButton
            label="Catat transfer balik"
            :disabled="!note.trim() || !reference.trim() || !proof.trim()"
            @click="ask(`${base}/${selected.id}/decisions`, { decision: 'RETURN', note, refund_transfer_ref: reference, proof_file_id: proof }, 'Catat dana dikembalikan tanpa capture')"
          />
        </template>
        <UButton
          label="Tutup detail"
          variant="outline"
          @click="selected = undefined"
        />
      </div>
    </UCard>
    <UCard v-else-if="can('payments.payment.recover')">
      <form
        class="flex flex-wrap items-end gap-2"
        @submit.prevent="ask(`/api/v1/admin/payments/${encodeURIComponent(payment.trim())}/reconciliations`, { bank_tx_ref: reference, received_amount: amount, reason_code: reason }, 'Buka kasus tanpa mengakui pembayaran')"
      >
        <UFormField label="Nomor pembayaran">
          <UInput
            v-model="payment"
            required
          />
        </UFormField>
        <UFormField label="Referensi bank">
          <UInput
            v-model="reference"
            required
          />
        </UFormField>
        <UFormField label="Dana diterima (IDR)">
          <UInput
            v-model.number="amount"
            required
            type="number"
            min="1"
          />
        </UFormField>
        <UFormField label="Kode alasan">
          <UInput
            v-model="reason"
            required
          />
        </UFormField>
        <UButton
          type="submit"
          label="Buka rekonsiliasi"
        />
      </form>
    </UCard>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </section>
</template>
