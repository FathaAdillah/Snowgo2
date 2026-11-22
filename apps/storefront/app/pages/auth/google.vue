<script setup lang="ts">
import { createApiClient } from '@snowego/api-types'

const client = createApiClient()
const link = ref('')
const currentEmail = ref('')
const email = ref('')
const password = ref('')
const busy = ref(true)
const message = ref('')

onMounted(async () => {
  link.value = new URLSearchParams(location.hash.slice(1)).get('link') ?? ''
  history.replaceState(history.state, '', location.pathname)
  try {
    const { data } = await client.GET('/auth/me')
    currentEmail.value = data?.data.email ?? ''
  } catch {
    message.value = 'Sesi belum dapat diperiksa. Coba lagi.'
  } finally {
    busy.value = false
  }
})

async function csrf() {
  await $fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
  return { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '') }
}

async function confirm() {
  busy.value = true
  message.value = ''
  try {
    if (!currentEmail.value) {
      const login = await client.POST('/auth/login', {
        body: { email: email.value, password: password.value }, params: { header: await csrf() }
      })
      if (!login.data) throw new Error()
      currentEmail.value = login.data.data.email
    }
    const result = await client.POST('/auth/google/links', {
      body: { token: link.value, password: password.value }, params: { header: await csrf() }
    })
    if (!result.data) throw new Error()
    link.value = ''
  } catch {
    message.value = 'Pengaitan gagal. Periksa password akun lama atau mulai ulang login Google.'
  } finally {
    password.value = ''
    busy.value = false
  }
}
</script>

<template>
  <section class="max-w-md space-y-4">
    <h1 class="text-2xl font-semibold">
      Akun Google
    </h1>
    <p
      v-if="busy"
      role="status"
    >
      Memeriksa akun…
    </p>
    <form
      v-else-if="link"
      class="space-y-4"
      @submit.prevent="confirm"
    >
      <p>Untuk menautkan Google, konfirmasi password akun Snowego {{ currentEmail }}. Jika belum punya password, lakukan reset lalu ulangi login Google.</p>
      <label
        v-if="!currentEmail"
        class="block"
      >Email Snowego
        <input
          v-model="email"
          type="email"
          required
          autocomplete="username"
          class="block w-full rounded border p-2"
        >
      </label>
      <label class="block">Password akun lama
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="block w-full rounded border p-2"
        >
      </label>
      <button
        type="submit"
        class="rounded bg-slate-900 px-4 py-2 text-white"
      >
        Tautkan akun
      </button>
    </form>
    <p v-else-if="currentEmail">
      Anda masuk sebagai {{ currentEmail }}.
    </p>
    <p
      v-if="message"
      role="alert"
    >
      {{ message }}
    </p>
    <a
      v-if="!busy && (!currentEmail || link)"
      href="/api/v1/auth/google/redirect"
      class="block underline"
    >Mulai login Google</a>
    <NuxtLink
      to="/"
      class="block underline"
    >Kembali ke storefront</NuxtLink>
  </section>
</template>
