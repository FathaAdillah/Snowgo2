<script setup lang="ts">
definePageMeta({ layout: false })
useHead({ title: 'Aktivasi staf · Snowego Admin' })

const { enroll, confirm } = useAdminAuth()
const steps = [
  { label: 'Kata sandi', title: 'Buat kata sandi', description: 'Kata sandi ini akan Anda gunakan untuk masuk ke dashboard staf.' },
  { label: 'Authenticator', title: 'Hubungkan authenticator', description: 'Di aplikasi authenticator pada ponsel, pilih tambah akun lalu pindai kode QR ini.' },
  { label: 'Kode pemulihan', title: 'Simpan kode pemulihan', description: 'Gunakan salah satu kode ini jika ponsel tidak tersedia. Setiap kode hanya berlaku sekali.' }
]
const token = ref('')
const ready = ref(false)
const uri = ref('')
const recovery = ref<string[]>([])
const saved = ref(false)
const busy = ref(false)
const error = ref('')
const step = computed(() => recovery.value.length ? 2 : uri.value ? 1 : 0)
const current = computed(() => steps[step.value]!)
const missing = computed(() => ready.value && !token.value && !recovery.value.length)
const heading = useTemplateRef('heading')

onMounted(() => {
  token.value = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? ''
  history.replaceState(history.state, '', window.location.pathname)
  ready.value = true
})
watch(step, () => heading.value?.focus(), { flush: 'post' })

async function submit(value: string) {
  if (busy.value || !token.value) return
  busy.value = true
  error.value = ''
  try {
    if (uri.value) {
      recovery.value = (await confirm(token.value, value)).data.recovery_codes
      uri.value = token.value = ''
    } else {
      uri.value = (await enroll(token.value, value)).data.otpauth_uri
    }
  } catch (e) {
    const failure = e as { statusCode?: number, data?: { code?: string } }
    if (failure.statusCode === 419) {
      error.value = 'Sesi berakhir. Buka kembali tautan dari email undangan untuk memulai ulang.'
    } else if (failure.data?.code === 'INVALID_CHALLENGE') {
      error.value = uri.value
        ? 'Verifikasi gagal. Coba kode terbaru dari aplikasi. Jika undangan sudah kedaluwarsa, minta undangan baru.'
        : 'Undangan tidak valid atau kedaluwarsa. Buka email undangan terbaru atau minta undangan baru.'
    } else {
      error.value = authErrorMessage(e)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center bg-muted/40 px-4 py-6 sm:p-8">
    <div class="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-default bg-default shadow-lg md:grid-cols-[0.85fr_1.15fr]">
      <aside class="flex flex-col gap-6 border-b border-default bg-primary/5 p-5 sm:p-8 md:gap-12 md:border-r md:border-b-0 lg:p-10">
        <AppLogo />
        <div class="hidden md:block">
          <p class="mb-2 text-xs font-semibold tracking-widest text-muted uppercase">
            Aktivasi akun staf
          </p>
          <p class="text-3xl font-semibold tracking-tight text-highlighted">
            Siapkan akses admin Anda.
          </p>
        </div>
        <ol
          v-if="ready && !missing"
          aria-label="Tahap aktivasi"
          class="hidden space-y-4 md:block"
        >
          <li
            v-for="(item, i) in steps"
            :key="item.label"
            :aria-current="step === i ? 'step' : undefined"
            class="flex items-center gap-3 text-sm"
            :class="i === step ? 'font-semibold text-highlighted' : 'text-muted'"
          >
            <span
              class="grid size-8 shrink-0 place-items-center rounded-full border font-mono text-xs"
              :class="i === step ? 'border-primary bg-primary text-inverted' : 'border-default bg-default'"
            >
              <UIcon
                v-if="i < step"
                name="i-lucide-check"
                aria-label="Selesai"
              />
              <template v-else>{{ i + 1 }}</template>
            </span>
            {{ item.label }}
          </li>
        </ol>
      </aside>

      <section class="flex min-w-0 flex-col justify-center p-5 sm:p-8 lg:p-12">
        <p
          v-if="!ready"
          role="status"
        >
          Memuat undangan…
        </p>
        <UAlert
          v-else-if="missing"
          color="warning"
          icon="i-lucide-mail"
          title="Buka tautan dari email undangan"
          description="Halaman ini perlu tautan aktivasi pribadi. Jika undangan kedaluwarsa, minta undangan baru kepada admin."
        />
        <template v-else>
          <p class="text-xs font-semibold tracking-wider text-muted uppercase">
            Langkah {{ step + 1 }} dari {{ steps.length }}
          </p>
          <h1
            ref="heading"
            tabindex="-1"
            class="mt-2 text-2xl font-semibold tracking-tight text-highlighted"
          >
            {{ current.title }}
          </h1>
          <p class="mt-2 mb-6 text-sm leading-relaxed text-muted">
            {{ current.description }}
          </p>
          <UAlert
            v-if="error"
            role="alert"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :title="error"
            class="mb-4"
          />
          <ActivationPassword
            v-if="step === 0"
            :busy="busy"
            @submit="submit"
          />
          <ActivationAuthenticator
            v-else-if="step === 1"
            :uri="uri"
            :busy="busy"
            @submit="submit"
          />
          <div
            v-else
            class="space-y-5"
          >
            <ul class="grid grid-cols-1 gap-2 rounded-xl border border-default bg-muted p-4 text-center font-mono text-sm text-highlighted sm:grid-cols-2">
              <li
                v-for="item in recovery"
                :key="item"
              >
                {{ item }}
              </li>
            </ul>
            <p class="text-sm text-muted">
              Simpan di pengelola kata sandi atau tempat aman. Kode tidak akan ditampilkan lagi.
            </p>
            <UCheckbox
              v-model="saved"
              label="Saya sudah menyimpan kode pemulihan."
            />
            <UButton
              block
              size="lg"
              :disabled="!saved"
              label="Lanjut ke login"
              @click="navigateTo('/login', { replace: true })"
            />
            <p class="text-sm text-muted">
              Saat login, tunggu kode authenticator berikutnya.
            </p>
          </div>
        </template>
      </section>
    </div>
  </main>
</template>
