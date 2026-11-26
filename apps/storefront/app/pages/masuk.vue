<script setup lang="ts">
const route = useRoute()
const tab = ref<'login' | 'register' | 'reset'>('login')
const title = computed(() => ({ login: 'Masuk', register: 'Buat akun', reset: 'Lupa password' })[tab.value])
const { busy, problem, notice, run } = useCustomerAction()
const name = ref('')
const email = ref('')
const password = ref('')
async function submit() {
  await run(async () => {
    if (tab.value === 'reset') {
      await customerWrite('/auth/password-reset-requests', 'POST', { email: email.value })
      return
    }
    if (tab.value === 'register') {
      await customerWrite('/auth/register', 'POST', { email: email.value, name: name.value, password: password.value })
      password.value = ''
      tab.value = 'login'
      return
    }
    const response = await customerWrite<StoreRead<'/auth/me'>>('/auth/login', 'POST', { email: email.value, password: password.value })
    password.value = ''
    const next = typeof route.query.next === 'string' && /^\/(?!\/)[A-Za-z0-9/_-]*(\?[^\\]*)?$/.test(route.query.next) ? route.query.next : '/akun'
    await navigateTo(response.data.email_verified ? next : '/verify-email')
  }, tab.value === 'login' ? '' : 'Periksa email kamu untuk melanjutkan.')
}
useSeoMeta({ title: 'Akun · Snowe GO', robots: 'noindex' })
</script>

<template>
  <section class="panel narrow stack">
    <h1>{{ title }}</h1>
    <form @submit.prevent="submit">
      <label v-if="tab === 'register'">Nama<input
        v-model="name"
        required
        autocomplete="name"
      >
      </label>
      <label>Email<input
        v-model="email"
        type="email"
        required
        autocomplete="email"
      >
      </label>
      <label v-if="tab !== 'reset'">Password<input
        v-model="password"
        type="password"
        required
        :minlength="tab === 'register' ? 12 : 1"
        :autocomplete="tab === 'login' ? 'current-password' : 'new-password'"
      >
      </label>
      <button :disabled="busy">
        {{ tab === 'reset' ? 'Kirim tautan' : title }}
      </button>
    </form>
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
    <nav class="actions">
      <button
        class="subtle"
        @click="tab = tab === 'login' ? 'register' : 'login'"
      >
        {{ tab === 'login' ? 'Buat akun' : 'Masuk' }}
      </button>
      <button
        class="subtle"
        @click="tab = 'reset'"
      >
        Lupa password
      </button>
      <a href="/api/v1/auth/google/redirect">Masuk dengan Google</a>
    </nav>
  </section>
</template>
