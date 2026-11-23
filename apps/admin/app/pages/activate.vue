<script setup lang="ts">
definePageMeta({ layout: false })
useHead({ title: 'Aktivasi staf · Snowego Admin' })
const { enroll, confirm } = useAdminAuth()
const token = ref('')
const password = ref('')
const secret = ref('')
const code = ref('')
const recovery = ref<string[]>([])
const busy = ref(false)
const error = ref('')
onMounted(() => {
  token.value = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? ''
  history.replaceState(null, '', window.location.pathname)
  if (!token.value) error.value = 'Buka tautan aktivasi dari email undangan.'
})
async function submit() {
  busy.value = true
  error.value = ''
  try {
    if (secret.value) {
      recovery.value = (await confirm(token.value, code.value)).data.recovery_codes
      secret.value = token.value = code.value = ''
    } else {
      secret.value = (await enroll(token.value, password.value)).data.secret
      password.value = ''
    }
  } catch (e) {
    error.value = authErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="grid min-h-dvh place-items-center p-6">
    <UCard class="w-full max-w-md">
      <template #header>
        <h1 class="text-xl font-bold">
          Aktivasi akun staf
        </h1>
      </template>
      <UAlert
        v-if="error"
        color="error"
        :title="error"
        class="mb-4"
      />
      <div
        v-if="recovery.length"
        class="space-y-4"
      >
        <p>Simpan kode pemulihan di tempat aman. Setiap kode hanya dapat dipakai sekali dan tidak akan ditampilkan lagi.</p>
        <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
          <li
            v-for="item in recovery"
            :key="item"
          >
            {{ item }}
          </li>
        </ul>
        <p>Saat masuk, pakai kode authenticator berikutnya, bukan kode yang baru dipakai untuk aktivasi.</p>
        <UButton
          to="/login"
          label="Sudah disimpan, masuk"
        />
      </div>
      <form
        v-else
        class="space-y-4"
        @submit.prevent="submit"
      >
        <template v-if="secret">
          <p>Di aplikasi authenticator, tambah akun berbasis waktu dengan kunci ini:</p>
          <code class="break-all">{{ secret }}</code>
          <UFormField label="Kode authenticator">
            <UInput
              v-model="code"
              autocomplete="one-time-code"
              inputmode="numeric"
              pattern="[0-9]{6}"
              maxlength="6"
              required
            />
          </UFormField>
        </template>
        <UFormField
          v-else
          label="Buat password"
          description="Gunakan 12–128 karakter."
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="new-password"
            minlength="12"
            maxlength="128"
            required
          />
        </UFormField>
        <UButton
          type="submit"
          :loading="busy"
          :disabled="!token"
          :label="secret ? 'Verifikasi dan tampilkan kode pemulihan' : 'Siapkan authenticator'"
        />
      </form>
    </UCard>
  </main>
</template>
