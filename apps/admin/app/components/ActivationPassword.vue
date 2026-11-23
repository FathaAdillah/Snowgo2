<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

defineProps<{ busy: boolean }>()
const emit = defineEmits<{ submit: [password: string] }>()
const state = reactive({ password: '', confirmation: '' })
const visible = ref(false)

function validate(values: typeof state): FormError[] {
  const errors: FormError[] = []
  const length = Array.from(values.password).length
  if (length < 12 || length > 128) {
    errors.push({ name: 'password', message: 'Gunakan 12–128 karakter.' })
  }
  if (!values.confirmation || values.password !== values.confirmation) {
    errors.push({ name: 'confirmation', message: 'Masukkan kembali kata sandi yang sama.' })
  }
  return errors
}
</script>

<template>
  <UForm
    :state="state"
    :validate="validate"
    :validate-on="['blur']"
    class="space-y-5"
    @submit="emit('submit', state.password)"
  >
    <UFormField
      label="Kata sandi baru"
      name="password"
      description="Gunakan 12–128 karakter."
    >
      <UInput
        v-model="state.password"
        :type="visible ? 'text' : 'password'"
        icon="i-lucide-lock-keyhole"
        autocomplete="new-password"
        size="lg"
        class="w-full"
        :disabled="busy"
      >
        <template #trailing>
          <UButton
            type="button"
            :icon="visible ? 'i-lucide-eye-off' : 'i-lucide-eye'"
            :aria-pressed="visible"
            aria-label="Tampilkan kata sandi baru"
            color="neutral"
            variant="ghost"
            size="xs"
            @click="visible = !visible"
          />
        </template>
      </UInput>
    </UFormField>
    <UFormField
      label="Konfirmasi kata sandi"
      name="confirmation"
    >
      <UInput
        v-model="state.confirmation"
        type="password"
        autocomplete="new-password"
        size="lg"
        class="w-full"
        :disabled="busy"
      />
    </UFormField>
    <UButton
      type="submit"
      size="lg"
      block
      trailing-icon="i-lucide-arrow-right"
      label="Lanjut ke authenticator"
      :loading="busy"
    />
  </UForm>
</template>
