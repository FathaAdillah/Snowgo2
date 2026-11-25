<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import { invoiceMoney } from '~/composables/useInvoices'
import { inventoryTime } from '~/composables/useInventory'
import type { FileDownload } from '~/composables/useRequestOrders'

definePageMeta({ permission: 'payments.payment.view', key: r => r.path })
const number = String(useRoute().params.number)
const url = `/api/v1/admin/payments/${encodeURIComponent(number)}`
type Detail = paths['/admin/payments/{number}']['get']['responses'][200]['content']['application/json']
const { data, status, refresh } = useFetch<Detail>(url, inventoryFetch)
const p = computed(() => data.value?.data)
const { can } = useAdminAuth()
const reason = ref('')
const error = ref('')
const action = ref<{ method: 'POST', url: string, body: Record<string, unknown>, label: string }>()
function confirm(path: string, body: Record<string, unknown>, label: string) {
  action.value = { method: 'POST', url: `${url}/${path}`, body, label }
}
async function download(id: string) {
  try {
    const response = await $fetch<FileDownload>(`/api/v1/admin/files/${id}/download`, { cache: 'no-store' })
    window.location.assign(response.data.url)
  } catch (e) { error.value = authErrorMessage(e) }
}
async function saved() {
  action.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-5">
    <UButton
      to="/finance/payments"
      variant="link"
      label="Daftar pembayaran"
    />
    <h1 class="text-2xl font-bold">
      Pembayaran {{ number }}
    </h1>
    <UAlert
      v-if="status === 'error'"
      color="error"
      title="Pembayaran tidak dapat dimuat."
      :actions="[{ label: 'Coba lagi', onClick: () => refresh() }]"
    />
    <template v-else-if="p">
      <UCard>
        <p>{{ p.status }} · {{ invoiceMoney(p.amount) }}</p>
        <p>Invoice: {{ p.invoice_numbers.join(', ') }} · Batas: {{ inventoryTime(p.expires_at) }}</p>
        <p v-if="p.claim_until">
          Klaim sampai {{ inventoryTime(p.claim_until) }}
        </p>
        <p v-if="p.reason_code">
          Alasan: {{ p.reason_code }}
        </p>
        <template v-if="can('payments.proof.view') && can('foundation.file.view') && can('foundation.file.download')">
          <UButton
            v-for="(id, i) in p.proof_ids"
            :key="id"
            variant="outline"
            :label="`Unduh bukti ${i + 1}`"
            @click="download(id)"
          />
        </template>
        <UAlert
          v-if="error"
          :title="error"
          color="error"
        />
      </UCard>
      <div class="flex flex-wrap items-end gap-3">
        <template v-if="can('payments.payment.review')">
          <UButton
            v-if="p.status === 'WAITING_CONFIRMATION' || (p.claim_until && Date.parse(p.claim_until) <= Date.now())"
            label="Ambil review"
            @click="confirm('review-claims', { action: 'CLAIM' }, `Ambil review ${number}`)"
          />
          <UButton
            v-if="p.status === 'UNDER_REVIEW' && p.claimed_by_me"
            label="Lepas review"
            variant="outline"
            @click="confirm('review-claims', { action: 'RELEASE' }, `Lepas review ${number}`)"
          />
        </template>
        <template v-if="p.status === 'UNDER_REVIEW' && p.claimed_by_me && can('payments.payment.verify')">
          <UFormField label="Kode alasan penolakan">
            <UInput
              v-model="reason"
              maxlength="48"
            />
          </UFormField>
          <UButton
            label="Tolak"
            color="error"
            :disabled="!reason.trim()"
            @click="confirm('decisions', { decision: 'REJECT', reason_code: reason.trim() }, `Tolak ${number}: ${reason}`)"
          />
        </template>
        <UButton
          variant="outline"
          label="Perbarui"
          @click="refresh()"
        />
      </div>
    </template>
    <p
      v-else
      role="status"
    >
      Memuat pembayaran…
    </p>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </section>
</template>
