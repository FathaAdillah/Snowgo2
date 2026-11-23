<script setup lang="ts">
import type { ApiError } from '@snowego/api-types'

const props = defineProps<{
  action: { url: string, method: 'POST' | 'PATCH' | 'DELETE', body?: Record<string, unknown>, label: string }
}>()
const emit = defineEmits<{ saved: [], cancel: [] }>()
const { stepUp, csrf } = useAdminAuth()
const toast = useToast()
const key = crypto.randomUUID()
const verify = ref(false)
const password = ref('')
const code = ref('')
const busy = ref(false)
const notice = ref('')
async function submit() {
  if (busy.value) return
  busy.value = true
  notice.value = ''
  try {
    if (verify.value) {
      await stepUp(password.value, code.value)
      verify.value = false
    }
    await $fetch(props.action.url, {
      method: props.action.method, body: props.action.body,
      headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': await csrf(), 'Idempotency-Key': key }
    })
    toast.add({ title: 'Perubahan tersimpan', color: 'success' })
    emit('saved')
  } catch (err) {
    const data = (err as { data?: Partial<ApiError> }).data
    if (data?.code === 'STEP_UP_REQUIRED') verify.value = true
    else notice.value = Object.values(data?.errors ?? {}).flat().join(' ') || authErrorMessage(err)
  } finally {
    password.value = code.value = ''
    busy.value = false
  }
}
</script>

<template>
  <UModal
    :open="true"
    :dismissible="!busy"
    title="Konfirmasi perubahan"
    :description="action.label"
    @update:open="open => { if (!open && !busy) emit('cancel') }"
  >
    <template #body>
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UAlert
          v-if="notice"
          color="error"
          :title="notice"
        />
        <template v-if="verify">
          <p class="text-sm text-muted">
            Masukkan password dan kode authenticator baru untuk melanjutkan.
          </p>
          <UFormField label="Password">
            <UInput
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full"
            />
          </UFormField>
          <UFormField label="Kode authenticator">
            <UInput
              v-model="code"
              autocomplete="one-time-code"
              inputmode="numeric"
              pattern="[0-9]{6}"
              maxlength="6"
              required
              class="w-full"
            />
          </UFormField>
        </template>
        <div class="flex justify-end gap-2">
          <UButton
            label="Batal"
            variant="outline"
            :disabled="busy"
            @click="emit('cancel')"
          />
          <UButton
            label="Konfirmasi"
            type="submit"
            :loading="busy"
          />
        </div>
      </form>
    </template>
  </UModal>
</template>
