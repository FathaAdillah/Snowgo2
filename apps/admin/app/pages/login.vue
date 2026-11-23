<script setup lang="ts">
import type { FormError, FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: false })
useHead({ title: 'Masuk · Snowego Admin' })

const { login, verifyMfa } = useAdminAuth()

const step = ref<'credentials' | 'mfa'>('credentials')
const loading = ref(false)
const error = ref<string | null>(null)
const state = reactive({ email: '', password: '' })
const otp = ref('')

function validate(value: typeof state): FormError[] {
  const errors: FormError[] = []
  if (!/^\S+@\S+\.\S+$/.test(value.email)) {
    errors.push({ name: 'email', message: 'Masukkan email yang valid.' })
  }
  if (!value.password) {
    errors.push({ name: 'password', message: 'Password wajib diisi.' })
  }
  return errors
}

async function submitCredentials(event: FormSubmitEvent<typeof state>) {
  loading.value = true
  error.value = null
  try {
    await login(event.data.email, event.data.password)
    state.password = ''
    step.value = 'mfa'
  } catch (err) {
    error.value = authErrorMessage(err)
  } finally {
    loading.value = false
  }
}

async function submitMfa() {
  loading.value = true
  error.value = null
  try {
    await verifyMfa(otp.value.trim())
    await navigateTo('/')
  } catch (err) {
    error.value = authErrorMessage(err)
    otp.value = ''
  } finally {
    loading.value = false
  }
}

const points = [
  'Pesanan siap kirim, preorder, dan request order',
  'Verifikasi pembayaran dan rekonsiliasi',
  'Stok multi-gudang dan pengiriman'
]
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-[minmax(380px,46%)_1fr]">
    <aside class="login-brand relative hidden flex-col overflow-hidden p-10 lg:flex">
      <AppLogo />

      <div class="relative my-auto max-w-md">
        <h1 class="mb-3 text-3xl leading-tight font-bold text-highlighted">
          Commerce &amp; Operations Platform
        </h1>
        <p class="mb-7 text-[0.9375rem] text-toned">
          Snowego — kelola katalog, pesanan, pembayaran, dan pengiriman dalam satu dashboard internal.
        </p>
        <ul class="flex flex-col gap-3 text-sm text-toned">
          <li
            v-for="point in points"
            :key="point"
            class="flex items-center gap-2.5"
          >
            <span class="size-2 shrink-0 rounded-full bg-primary" />
            {{ point }}
          </li>
        </ul>
      </div>

      <p class="relative text-xs text-muted">
        © {{ new Date().getFullYear() }} Snowego
      </p>
    </aside>

    <main class="flex items-center justify-center bg-default p-6">
      <div class="w-full max-w-100 rounded-2xl border border-default bg-default p-9 shadow-[0_12px_32px_rgb(14_165_233/0.10)]">
        <div class="mb-6 lg:hidden">
          <AppLogo />
        </div>

        <template v-if="step === 'credentials'">
          <h2 class="text-[1.375rem] font-bold text-highlighted">
            Masuk
          </h2>
          <p class="mt-1 mb-7 text-sm text-muted">
            Masukkan email dan password Anda untuk masuk
          </p>
        </template>
        <template v-else>
          <h2 class="text-[1.375rem] font-bold text-highlighted">
            Verifikasi 2 Langkah
          </h2>
          <p class="mt-1 mb-7 text-sm text-muted">
            Masukkan kode 6 digit dari authenticator atau satu kode pemulihan.
          </p>
        </template>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :description="error"
          class="mb-4"
        />

        <UForm
          v-if="step === 'credentials'"
          :state="state"
          :validate="validate"
          :validate-on="['blur']"
          class="flex flex-col gap-4"
          @submit="submitCredentials"
        >
          <UFormField
            label="Email"
            name="email"
          >
            <UInput
              v-model="state.email"
              type="email"
              placeholder="nama@snowego.id"
              autocomplete="username"
              icon="i-lucide-mail"
              size="lg"
              class="w-full"
              :disabled="loading"
            />
          </UFormField>
          <UFormField
            label="Password"
            name="password"
          >
            <UInput
              v-model="state.password"
              type="password"
              placeholder="Masukkan password"
              autocomplete="current-password"
              icon="i-lucide-lock"
              size="lg"
              class="w-full"
              :disabled="loading"
            />
          </UFormField>
          <UButton
            type="submit"
            size="lg"
            block
            :loading="loading"
            :label="loading ? 'Memproses...' : 'Masuk'"
          />
        </UForm>

        <form
          v-else
          class="flex flex-col gap-4"
          @submit.prevent="submitMfa"
        >
          <UInput
            v-model="otp"
            type="text"
            autocomplete="one-time-code"
            placeholder="Kode verifikasi atau pemulihan"
            size="lg"
            autofocus
            aria-label="Kode verifikasi"
            :disabled="loading"
            class="w-full"
          />
          <UButton
            type="submit"
            size="lg"
            block
            :loading="loading"
            :disabled="otp.length < 6"
            label="Verifikasi"
          />
          <UButton
            label="Kembali"
            color="neutral"
            variant="ghost"
            block
            @click="step = 'credentials'; error = null"
          />
        </form>
      </div>
    </main>
  </div>
</template>
