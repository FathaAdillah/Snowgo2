<script setup lang="ts">
import { renderSVG } from 'uqr'

const props = defineProps<{ uri: string, busy: boolean }>()
const emit = defineEmits<{ submit: [code: string] }>()
const digits = ref<number[]>([])
const code = computed(() => digits.value.join(''))
const complete = computed(() => /^\d{6}$/.test(code.value))
const image = computed(() => {
  const svg = renderSVG(props.uri, { border: 4, ecc: 'M' })
  return `data:image/svg+xml,${encodeURIComponent(svg)}`
})
</script>

<template>
  <form
    class="space-y-5"
    @submit.prevent="complete && !busy && emit('submit', code)"
  >
    <figure class="rounded-xl border border-default bg-muted p-4 text-center">
      <img
        :src="image"
        alt="Kode QR pribadi untuk menambahkan akun staf ke authenticator"
        width="252"
        height="252"
        class="mx-auto h-auto max-w-full rounded-md"
      >
      <figcaption class="mt-3 text-xs text-muted">
        Jangan bagikan QR atau tangkapan layarnya.
      </figcaption>
    </figure>
    <UFormField
      label="Kode verifikasi"
      description="Masukkan 6 digit yang muncul di aplikasi."
      name="code"
    >
      <UPinInput
        v-model="digits"
        type="number"
        :length="6"
        otp
        required
        :disabled="busy"
        size="lg"
        class="max-w-full"
        :ui="{ base: 'min-w-0' }"
      />
    </UFormField>
    <UButton
      type="submit"
      size="lg"
      block
      icon="i-lucide-shield-check"
      label="Verifikasi kode"
      :loading="busy"
      :disabled="!complete"
    />
  </form>
</template>
