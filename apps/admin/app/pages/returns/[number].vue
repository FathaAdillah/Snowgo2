<script setup lang="ts">
import type { paths } from '@snowego/api-types'

type Detail = paths['/admin/returns/{number}']['get']['responses'][200]['content']['application/json']
definePageMeta({ permission: 'returns.return.view', key: route => route.fullPath })
const { can } = useAdminAuth()
const number = String(useRoute().params.number)
const base = '/api/v1/admin/returns/' + encodeURIComponent(number)
const { data, status, refresh } = useFetch<Detail>(base, inventoryFetch)
const row = computed(() => data.value?.data)
const { action, ask, finish } = useCaseAction(base, refresh)
const reason = ref('')
const instructions = ref('')
const note = ref('')
const refundDeclined = computed(() => row.value?.resolution === 'REFUND' && ['REJECTED', 'CANCELLED'].includes(row.value.refund_status ?? ''))
const resolution = ref('REPLACEMENT')
const outcome = ref('ACCEPT')
const done = ref(false)
const lines = ref<{ shipment_item_id: string, accepted_qty: number, disposition: string }[]>([])
watch(row, (value) => {
  if (!value) return
  lines.value = value.items.map(l => ({ shipment_item_id: l.shipment_item_id, accepted_qty: l.qty, disposition: 'NO_RECEIPT' }))
  resolution.value = refundDeclined.value ? 'REPLACEMENT' : value.resolution ?? (value.status === 'INSPECTION_FAILED' ? 'REJECTED' : 'REPLACEMENT')
}, { immediate: true })
const review = computed(() => can('returns.return.review'))
const inspect = computed(() => can('returns.return.inspect'))
</script>

<template>
  <section class="space-y-5">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">
        Retur {{ number }}
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
        <p>{{ row.order_number }} · {{ row.shipment_number }} · {{ row.status }} · {{ row.reason_code }}</p>
        <p>{{ row.instructions }} {{ row.customer_tracking_number }}</p>
        <p
          v-for="l in row.items"
          :key="l.shipment_item_id"
        >
          {{ l.shipment_item_id }} · {{ l.qty }} diklaim · {{ l.accepted_qty }} diterima {{ l.disposition }}
        </p>
        <p>{{ row.resolution }} {{ row.outcome_note }} {{ row.replacement_number }} {{ row.refund_number }}</p>
        <CaseEvidence
          owner="return"
          :number="number"
          collection="return_evidence"
          :evidence="row.evidence"
          :upload-allowed="(review && ['REQUESTED', 'UNDER_REVIEW'].includes(row.status)) || (inspect && ['RECEIVED', 'INSPECTING'].includes(row.status))"
          @uploaded="refresh()"
        />
      </UCard>
      <div class="flex flex-wrap items-end gap-3">
        <UButton
          v-if="review && row.status === 'REQUESTED'"
          label="Mulai review"
          @click="ask('/reviews', { decision: 'START' }, 'Mulai review retur')"
        />
        <UFormField
          v-if="review && ['UNDER_REVIEW', 'APPROVED'].includes(row.status)"
          label="Kode alasan"
        >
          <UInput v-model="reason" />
        </UFormField>
        <template v-if="review && row.status === 'UNDER_REVIEW'">
          <UButton
            label="Setujui retur"
            @click="ask('/reviews', { decision: 'APPROVE' }, 'Setujui permintaan retur')"
          />
          <UButton
            label="Tolak retur"
            :disabled="!reason.trim()"
            @click="ask('/reviews', { decision: 'REJECT', reason_code: reason }, 'Tolak permintaan retur')"
          />
        </template>
        <template v-if="review && row.status === 'APPROVED'">
          <UFormField label="Instruksi untuk pelanggan">
            <UTextarea v-model="instructions" />
          </UFormField>
          <UButton
            label="Kirim instruksi"
            :disabled="!instructions.trim()"
            @click="ask('/reviews', { decision: 'GIVE_INSTRUCTIONS', instructions }, 'Kirim instruksi pengembalian')"
          />
          <UButton
            label="Bebaskan pengiriman"
            :disabled="!reason.trim()"
            @click="ask('/reviews', { decision: 'WAIVE_SHIPMENT', reason_code: reason }, 'Lanjutkan inspeksi tanpa pengiriman')"
          />
        </template>
        <UButton
          v-if="inspect && row.status === 'IN_TRANSIT'"
          label="Terima paket retur"
          @click="ask('/receipts', {}, 'Konfirmasi penerimaan fisik')"
        />
        <template v-if="inspect && ['RECEIVED', 'INSPECTING'].includes(row.status)">
          <UFormField label="Hasil inspeksi">
            <USelect
              v-model="outcome"
              :items="['ACCEPT', 'FAIL']"
            />
          </UFormField>
          <UFormField label="Catatan inspeksi">
            <UTextarea v-model="note" />
          </UFormField>
          <div
            v-for="line in lines"
            :key="line.shipment_item_id"
            class="flex flex-wrap items-end gap-2"
          >
            <UFormField :label="'Unit diterima ' + line.shipment_item_id">
              <UInput
                v-model="line.accepted_qty"
                type="number"
                min="0"
              />
            </UFormField>
            <UFormField label="Kondisi">
              <USelect
                v-model="line.disposition"
                :items="['RESTOCK', 'DAMAGE', 'NO_RECEIPT']"
              />
            </UFormField>
          </div>
          <UButton
            label="Simpan inspeksi"
            :disabled="!note.trim()"
            @click="ask('/inspections', { result: outcome, note, items: lines }, 'Simpan inspeksi dan mutasi stok')"
          />
        </template>
        <template v-if="review && can('returns.return.resolve') && ['ACCEPTED', 'INSPECTION_FAILED'].includes(row.status)">
          <UFormField label="Resolusi">
            <USelect
              v-model="resolution"
              :disabled="!!row.resolution && !refundDeclined"
              :items="row.status === 'INSPECTION_FAILED' ? ['REJECTED'] : refundDeclined ? ['REPLACEMENT', 'SHIP_MISSING_ITEM', 'REPAIR'] : ['REPLACEMENT', 'SHIP_MISSING_ITEM', 'REPAIR', 'REFUND']"
            />
          </UFormField>
          <UFormField label="Catatan hasil">
            <UTextarea v-model="note" />
          </UFormField>
          <UCheckbox
            v-if="row.resolution === 'REPAIR'"
            v-model="done"
            label="Pekerjaan perbaikan selesai"
          />
          <UButton
            label="Catat hasil resolusi"
            :disabled="!note.trim()"
            @click="ask('/resolutions', { resolution, note, work_completed: done }, 'Catat hasil resolusi retur')"
          />
        </template>
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
