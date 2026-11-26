<script setup lang="ts">
const props = defineProps<{ reset?: boolean }>()
const route = useRoute()
const token = ref('')
const email = ref('')
const password = ref('')
const done = ref(false)
const { busy, problem, notice, run } = useCustomerAction()
onMounted(() => {
  token.value = String(route.query.token ?? '')
  email.value = String(route.query.email ?? '')
  if (token.value) history.replaceState(history.state, '', location.pathname)
})
async function submit() {
  await run(async () => {
    if (props.reset) await customerWrite('/auth/password-resets', 'POST', { email: email.value, token: token.value, password: password.value })
    else await customerWrite('/auth/email-verifications', 'POST', { token: token.value })
    password.value = ''
    done.value = true
  }, props.reset ? 'Password baru tersimpan.' : 'Alamat email berhasil diverifikasi.')
}
</script>

<template>
  <section class="panel narrow stack">
    <h1>{{ reset ? 'Atur ulang password' : 'Verifikasi email' }}</h1>
    <form
      v-if="!done && (token || reset)"
      @submit.prevent="submit"
    >
      <template v-if="reset">
        <label>Email<input
          v-model="email"
          type="email"
          required
          autocomplete="email"
        >
        </label>
        <label>Password baru<input
          v-model="password"
          required
          minlength="12"
          type="password"
          autocomplete="new-password"
        >
        </label>
      </template>
      <button :disabled="busy || !token">
        {{ reset ? 'Simpan password' : 'Verifikasi alamat email' }}
      </button>
    </form>
    <template v-else-if="!done">
      <p>Buka tautan verifikasi dalam email untuk mengaktifkan akun.</p>
      <button
        :disabled="busy"
        @click="run(() => customerWrite('/auth/email-verification-requests', 'POST'), 'Email verifikasi diminta.')"
      >
        Kirim ulang
      </button>
    </template>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }}
    </p>
    <NuxtLink to="/masuk">Kembali ke login</NuxtLink>
  </section>
</template>
