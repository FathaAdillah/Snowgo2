<script setup lang="ts">
import type { Batch, BatchAction } from '~/composables/useProcurement'
import { batchStages } from '~/composables/useProcurement'

const props = defineProps<{ batch: Batch }>()
const emit = defineEmits<{ saved: [] }>()
const { may } = useProcurement()
const mode = ref<'stage' | 'receipt' | 'close'>()
const stage = ref<string>()
const refId = ref<string>()
const note = ref('')
const reason = ref('')
const eta = ref('')
const received = ref(0)
const failed = ref(0)
const action = ref<BatchAction>()
const available = (i: Batch['items'][number]) => i.qty - i.received_qty - i.failed_qty
const items = computed(() => props.batch.items.filter(i => available(i) > 0))
const item = computed(() => items.value.find(i => i.order_item_id === refId.value))
const canStage = computed(() => may('stage') && props.batch.next_stages.length > 0)
const canReceive = computed(() => may('receipt') && props.batch.status === 'IN_PROGRESS' && props.batch.stage !== 'FAILED' && items.value.length > 0)
const canClose = computed(() => may('close') && !!props.batch.closure_target)
const closeLabel = computed(() => props.batch.closure_target === 'CANCELLED' ? 'Batalkan batch' : 'Tutup batch')
function open(value: typeof mode.value) {
  mode.value = value
  stage.value = props.batch.next_stages[0]
  refId.value = items.value[0]?.order_item_id
  reason.value = note.value = eta.value = ''
  received.value = failed.value = 0
}
function submit() {
  const base = `${batchApi}/${props.batch.number}/`
  if (mode.value === 'stage' && canStage.value && stage.value && props.batch.next_stages.includes(stage.value)) {
    action.value = { url: `${base}stage-events`, method: 'POST', label: `${props.batch.number}: ubah tahap menjadi ${batchStages[stage.value]}?`, body: { stage: stage.value, ...(note.value.trim() && { note: note.value.trim() }), ...(eta.value && { eta_date: eta.value }) } }
  } else if (mode.value === 'receipt' && canReceive.value && item.value) {
    const r = Number(received.value)
    const f = Number(failed.value)
    if (![r, f].every(n => Number.isInteger(n) && n >= 0) || r + f < 1 || r + f > available(item.value) || (f && !reason.value.trim())) return
    action.value = { url: `${base}receipts`, method: 'POST', label: `${item.value.order_number} · ${item.value.sku ?? refId.value}: terima ${r} unit, gagal final ${f} unit?`, body: { ...(f && { reason_code: reason.value.trim() }), ...(note.value.trim() && { note: note.value.trim() }), items: [{ order_item_id: item.value.order_item_id, received_qty: r, failed_qty: f }] } }
  } else if (mode.value === 'close' && canClose.value) {
    action.value = { url: `${base}closures`, method: 'POST', label: `${closeLabel.value} ${props.batch.number}?`, body: { ...(reason.value.trim() && { reason_code: reason.value.trim() }) } }
  }
}
function saved() {
  action.value = undefined
  mode.value = undefined
  emit('saved')
}
</script>

<template>
  <UCard v-if="canStage || canReceive || canClose">
    <div class="flex flex-wrap gap-2">
      <UButton
        v-if="canStage"
        label="Catat tahap"
        @click="open('stage')"
      />
      <UButton
        v-if="canReceive"
        label="Catat penerimaan atau kekurangan"
        @click="open('receipt')"
      />
      <UButton
        v-if="canClose"
        :label="closeLabel"
        color="warning"
        variant="outline"
        @click="open('close')"
      />
    </div>
    <form
      v-if="mode"
      class="mt-4 space-y-3"
      @submit.prevent="submit"
    >
      <template v-if="mode === 'stage'">
        <UFormField label="Tahap berikutnya">
          <USelect
            v-model="stage"
            :items="batch.next_stages.map(s => ({ label: batchStages[s] ?? s, value: s }))"
          />
        </UFormField>
        <UFormField label="Estimasi tiba baru">
          <UInput
            v-model="eta"
            type="date"
          />
        </UFormField>
        <p
          v-if="stage === 'FAILED'"
          class="text-sm text-muted"
        >
          Untuk mencoba pemasok lain, batalkan sisa penugasan setelah tahap gagal. Ini bukan kekurangan final pembeli.
        </p>
      </template>
      <template v-if="mode === 'receipt'">
        <UFormField label="Item pesanan">
          <USelect
            v-model="refId"
            :items="items.map(i => ({ value: i.order_item_id, label: `${i.order_number} · ${i.sku ?? i.order_item_id} · sisa ${available(i)}` }))"
          />
        </UFormField>
        <div class="flex flex-wrap gap-2">
          <UFormField label="Jumlah diterima">
            <UInput
              v-model.number="received"
              type="number"
              min="0"
              step="1"
              :max="item ? available(item) - Number(failed) : 0"
              required
            />
          </UFormField>
          <UFormField label="Jumlah gagal final">
            <UInput
              v-model.number="failed"
              type="number"
              min="0"
              step="1"
              :max="item ? available(item) - Number(received) : 0"
              required
            />
          </UFormField>
        </div>
        <p
          v-if="failed > 0"
          class="text-sm text-muted"
        >
          Unit gagal final tidak bisa ditugaskan lagi dan akan masuk review refund.
        </p>
      </template>
      <UFormField
        v-if="(mode === 'receipt' && failed > 0) || mode === 'close'"
        label="Kode alasan pengadaan"
      >
        <UInput
          v-model="reason"
          :required="mode === 'receipt' || batch.stage === 'FAILED' || batch.closure_target === 'CANCELLED'"
          maxlength="48"
        />
      </UFormField>
      <UFormField
        v-if="mode !== 'close'"
        label="Catatan"
      >
        <UTextarea
          v-model="note"
          :required="mode === 'stage' && stage === 'FAILED'"
          maxlength="1000"
        />
      </UFormField>
      <div class="flex gap-2">
        <UButton
          type="submit"
          label="Tinjau perubahan"
          :disabled="mode === 'receipt' && Number(received) + Number(failed) < 1"
        /><UButton
          label="Batal"
          variant="outline"
          @click="mode = undefined"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </UCard>
</template>
