<script setup lang="ts">
import type { RequestCase } from '~/composables/useRequestOrders'
import { reviewLabels } from '~/composables/useRequestOrders'
import type { InventoryAction } from '~/composables/useInventory'

const props = defineProps<{ record: RequestCase }>()
const emit = defineEmits<{ saved: [] }>()
const { may } = useRequestOrders()
const reviews = computed(() => props.record.review_actions.filter(d => may(d.toLowerCase())))
const mode = ref('')
const text = ref('')
const assignee = ref('')
const reason = ref('')
const action = ref<InventoryAction>()
function choose(value: string) {
  mode.value = value
  text.value = reason.value = assignee.value = ''
  action.value = undefined
}
const message = computed(() => mode.value === 'reply' || mode.value === 'note')
function submit() {
  let body: Record<string, unknown>
  let label: string
  if (message.value) {
    if (!props.record.can_message || !may(mode.value) || !text.value.trim()) return
    const internal = mode.value === 'note'
    body = { visibility: internal ? 'STAFF' : 'CUSTOMER', body: text.value.trim() }
    label = internal ? 'Simpan catatan ini untuk staf saja?' : 'Kirim balasan ini ke pelanggan?'
  } else {
    if (!reviews.value.includes(mode.value)) return
    body = { decision: mode.value }
    if (may('assign') && assignee.value !== '') {
      if (!Number.isSafeInteger(Number(assignee.value)) || Number(assignee.value) < 1) return
      body.assignee_id = Number(assignee.value)
    }
    if (reason.value.trim()) body.reason_code = reason.value.trim()
    if (may('note') && text.value.trim()) body.note = text.value.trim()
    label = `${reviewLabels[mode.value]} untuk ${props.record.number}?`
  }
  action.value = { url: `${caseApi}/${encodeURIComponent(props.record.number)}/${message.value ? 'messages' : 'reviews'}`, method: 'POST', body, label }
}
function saved() {
  choose('')
  emit('saved')
}
</script>

<template>
  <UCard v-if="reviews.length || (record.can_message && (may('reply') || may('note')))">
    <template #header>
      <h2 class="font-semibold">
        Tindakan staf
      </h2>
    </template>
    <div class="flex flex-wrap gap-2">
      <UButton
        v-for="d in reviews"
        :key="d"
        :label="reviewLabels[d]"
        variant="outline"
        @click="choose(d)"
      />
      <UButton
        v-if="record.can_message && may('reply')"
        label="Balas pelanggan"
        icon="i-lucide-message-circle"
        @click="choose('reply')"
      />
      <UButton
        v-if="record.can_message && may('note')"
        label="Catatan internal"
        variant="outline"
        icon="i-lucide-lock"
        @click="choose('note')"
      />
    </div>
    <form
      v-if="mode"
      class="mt-4 space-y-3"
      @submit.prevent="submit"
    >
      <template v-if="!message">
        <h3 class="font-semibold">
          {{ reviewLabels[mode] }}
        </h3>
        <UFormField
          v-if="may('assign')"
          label="ID staf penanggung jawab (opsional)"
        >
          <UInput
            v-model="assignee"
            inputmode="numeric"
            pattern="[1-9][0-9]*"
          />
        </UFormField>
        <UFormField label="Kode alasan aktif (opsional)">
          <UInput
            v-model="reason"
            maxlength="48"
          />
        </UFormField>
      </template>
      <UFormField
        v-if="message || may('note')"
        :label="mode === 'reply' ? 'Balasan yang akan dilihat pelanggan' : 'Catatan internal — hanya terlihat oleh staf'"
      >
        <UTextarea
          v-model="text"
          :maxlength="message ? 5000 : 1000"
          :required="message"
          class="w-full"
        />
      </UFormField>
      <div class="flex gap-2">
        <UButton
          type="submit"
          :label="message ? (mode === 'reply' ? 'Kirim balasan' : 'Simpan catatan') : 'Terapkan review'"
        />
        <UButton
          variant="ghost"
          label="Batal"
          @click="choose('')"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="saved"
    />
  </UCard>
</template>
