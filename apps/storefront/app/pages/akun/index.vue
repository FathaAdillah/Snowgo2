<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
const { data, error, refresh } = await useFetch<StoreRead<'/profile'>>('/api/v1/profile', { server: false })
const { busy, problem, notice, run } = useCustomerAction()
const profile = reactive({ display_name: '', phone: '', marketing_opt_in: false })
watch(data, (v) => {
  if (v) Object.assign(profile, { display_name: v.data.display_name, phone: v.data.phone || '', marketing_opt_in: v.data.marketing_opt_in })
}, { immediate: true })
async function save() {
  await run(async () => {
    await customerWrite('/profile', 'PATCH', { ...profile, phone: profile.phone || null })
    await refresh()
  }, 'Profil tersimpan.')
}
async function logout() {
  await customerWrite('/auth/logout', 'POST')
  clearNuxtData()
  await navigateTo('/')
}
</script>

<template>
  <section class="stack">
    <h1>Akun saya</h1><CustomerLinks /><div class="panel stack">
      <p
        v-if="error"
        role="alert"
      >
        Profil gagal dimuat. <button @click="refresh()">
          Coba lagi
        </button>
      </p>
      <form
        v-else-if="data"
        @submit.prevent="save"
      >
        <p>{{ data.data.email }}</p><label>Nama<input
          v-model="profile.display_name"
          required
          autocomplete="name"
        ></label><label>Telepon<input
          v-model="profile.phone"
          type="tel"
          autocomplete="tel"
        ></label><label><span><input
          v-model="profile.marketing_opt_in"
          type="checkbox"
        > Saya ingin menerima kabar promosi</span></label><div>
          <button :disabled="busy">
            Simpan profil
          </button>
        </div>
      </form>
      <p
        v-else
        role="status"
      >
        Memuat profil…
      </p>
      <p
        v-if="problem"
        role="alert"
      >
        {{ problem }}
      </p><p
        v-if="notice"
        role="status"
      >
        {{ notice }}
      </p><div>
        <button
          class="subtle"
          :disabled="busy"
          @click="run(logout)"
        >
          Keluar
        </button>
      </div>
    </div>
  </section>
</template>
