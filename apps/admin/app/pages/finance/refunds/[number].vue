<script setup lang="ts">
import { formatRupiah } from '~/composables/useDashboard'
import type { paths } from '@snowego/api-types'

type Detail = paths['/admin/refunds/{number}']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'refunds.refund.view', key: route => route.fullPath })
const { can } = useAdminAuth()
const number = String(useRoute().params.number)
const base = '/api/v1/admin/refunds/' + encodeURIComponent(number)
const { data, status, refresh } = useFetch<Detail>(base, inventoryFetch)
const row = computed(() => data.value?.data)
const { action, ask, finish } = useCaseAction(base, refresh)
const note = ref('')
const reason = ref('')
const amount = ref(0)
const checked = ref(false)
const proof = ref('')
const refBank = ref('')
const sentAt = ref('')
const allocations = ref([{ payment_number: '', invoice_number: '', amount: 0 }])
const items = ref<{ order_item_id: string, approved_amount: number }[]>([])
watch(row, (value) => {
  if (!value) return
  checked.value = false
  amount.value = value.approved_amount ?? value.requested_amount
  items.value = value.items.map(l => ({ order_item_id: l.order_item_id, approved_amount: l.approved_amount ?? l.requested_amount }))
}, { immediate: true })
const approval = computed(() => items.value.length ? items.value.reduce((a, l) => a + Number(l.approved_amount), 0) : amount.value)
const mayReview = computed(() => can('refunds.refund.review'))
const mayExecute = computed(() => can('refunds.refund.execute') && !row.value?.reviewed_by_me)
function transfer() {
  ask('/transfers', { transfer_ref: refBank.value, transferred_at: new Date(sentAt.value).toISOString(), proof_file_id: proof.value, allocations: allocations.value.map(l => ({ ...l })) }, 'Catat transfer yang sudah dikonfirmasi bank')
}
</script>

<template>
  <section class="space-y-5">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">
        Refund {{ number }}
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
        <p>{{ row.order_number }} · {{ row.kind }} · {{ row.status }}</p><p>Diminta {{ formatRupiah(row.requested_amount) }} · {{ row.reason_code }}</p><p v-if="row.approved_amount">
          Disetujui {{ formatRupiah(row.approved_amount) }}
        </p>
        <p v-if="row.cancel_order">
          Pesanan akan dibatalkan setelah transfer.
        </p>
        <p
          v-for="l in row.items"
          :key="l.order_item_id"
        >
          {{ l.order_item_id }} · {{ l.qty }} unit · {{ formatRupiah(l.requested_amount) }}
        </p>
        <CaseEvidence
          owner="refund"
          :number="number"
          collection="refund_evidence"
          :evidence="row.evidence"
          :upload-allowed="mayReview && ['REQUESTED', 'UNDER_REVIEW'].includes(row.status)"
          @uploaded="refresh()"
        />
      </UCard>
      <div class="flex flex-wrap items-end gap-3">
        <UFormField
          v-if="mayReview && ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'FAILED'].includes(row.status)"
          label="Catatan keputusan"
        >
          <UInput v-model="note" />
        </UFormField>
        <UFormField
          v-if="mayReview || (mayExecute && row.executor_is_me && row.status === 'PROCESSING')"
          label="Kode alasan penolakan / kegagalan"
        >
          <UInput v-model="reason" />
        </UFormField>
        <UButton
          v-if="mayReview && row.status === 'REQUESTED'"
          label="Mulai review"
          :disabled="!note.trim()"
          @click="ask('/reviews', { decision: 'START', note }, 'Mulai review refund')"
        />
        <template v-if="mayReview && row.status === 'UNDER_REVIEW' && !row.requested_by_me">
          <UFormField
            v-if="!items.length"
            label="Nominal disetujui"
          >
            <UInput
              v-model="amount"
              type="number"
              min="1"
              :max="row.requested_amount"
            />
          </UFormField>
          <UFormField
            v-for="item in items"
            :key="item.order_item_id"
            :label="'Nominal item ' + item.order_item_id"
          >
            <UInput
              v-model="item.approved_amount"
              type="number"
              min="0"
            />
          </UFormField>
          <UButton
            label="Setujui"
            :disabled="!note.trim() || approval <= 0"
            @click="ask('/reviews', { decision: 'APPROVE', note, approved_amount: approval, items }, 'Setujui ' + formatRupiah(approval))"
          />
          <UButton
            label="Tolak"
            :disabled="!reason.trim() || !note.trim()"
            @click="ask('/reviews', { decision: 'REJECT', note, reason_code: reason }, 'Tolak refund')"
          />
        </template>
        <UCheckbox
          v-if="['APPROVED', 'FAILED'].includes(row.status) && (mayExecute || can('refunds.refund.cancel'))"
          v-model="checked"
          label="Saya telah memeriksa mutasi bank untuk duplikat"
        />
        <UButton
          v-if="mayExecute && ['APPROVED', 'FAILED'].includes(row.status)"
          label="Mulai proses transfer"
          :disabled="!checked"
          @click="ask('/execution-attempts', { action: row.status === 'FAILED' ? 'RETRY' : 'START', bank_checked: checked }, 'Proses transfer manual')"
        />
        <UButton
          v-if="mayReview && can('refunds.refund.cancel') && (['REQUESTED', 'UNDER_REVIEW'].includes(row.status) || (['APPROVED', 'FAILED'].includes(row.status) && !row.executor_is_me))"
          label="Batalkan refund"
          :disabled="!note.trim() || !reason.trim() || (['APPROVED', 'FAILED'].includes(row.status) && !checked)"
          @click="ask('/cancellations', { reason_code: reason, note, bank_checked: checked }, 'Batalkan refund')"
        />
      </div>
      <UCard v-if="mayExecute && row.status === 'PROCESSING' && row.executor_is_me">
        <form
          class="flex flex-wrap items-end gap-3"
          @submit.prevent="transfer"
        >
          <UFormField label="Referensi mutasi bank">
            <UInput
              v-model="refBank"
              required
            />
          </UFormField>
          <UFormField label="Waktu transfer">
            <UInput
              v-model="sentAt"
              type="datetime-local"
              required
            />
          </UFormField>
          <CaseEvidence
            owner="refund"
            :number="number"
            collection="refund_transfer_proof"
            upload-allowed
            @uploaded="id => proof = id"
          />
          <UFormField label="ID bukti transfer privat">
            <UInput
              v-model="proof"
              required
            />
          </UFormField>
          <div
            v-for="(l, index) in allocations"
            :key="index"
            class="flex flex-wrap items-end gap-2"
          >
            <UFormField label="Nomor pembayaran">
              <UInput
                v-model="l.payment_number"
                required
              />
            </UFormField>
            <UFormField label="Nomor invoice">
              <UInput
                v-model="l.invoice_number"
                required
              />
            </UFormField>
            <UFormField label="Nominal alokasi">
              <UInput
                v-model="l.amount"
                type="number"
                min="1"
                required
              />
            </UFormField>
            <AdminRowAction
              v-if="allocations.length > 1"
              :label="`Hapus alokasi ${index + 1}`"
              icon="i-lucide-trash-2"
              @click="allocations.splice(index, 1)"
            />
          </div>
          <UButton
            label="Tambah alokasi"
            variant="outline"
            @click="allocations.push({ payment_number: '', invoice_number: '', amount: 0 })"
          />
          <UButton
            type="submit"
            label="Konfirmasi transfer"
          />
          <UButton
            label="Catat gagal transfer"
            :disabled="!reason.trim()"
            @click="ask('/execution-attempts', { action: 'FAIL', reason_code: reason }, 'Catat transfer gagal')"
          />
        </form>
      </UCard>
    </template>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="finish"
      @cancel="action = undefined"
    />
  </section>
</template>
