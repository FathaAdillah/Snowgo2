<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

definePageMeta({ permission: 'admin.profile.view' })

useHead({ title: 'Pengaturan · Snowego Admin' })

const { user, can } = useAdminAuth()
const toast = useToast()

const account = reactive({ name: user.value?.display_name ?? '', email: user.value?.email ?? '' })
const password = reactive({ current: '', next: '', confirm: '' })

function validateAccount(state: typeof account): FormError[] {
  const errors: FormError[] = []
  if (!state.name.trim()) {
    errors.push({ name: 'name', message: 'Nama wajib diisi.' })
  }
  if (!/^\S+@\S+\.\S+$/.test(state.email)) {
    errors.push({ name: 'email', message: 'Masukkan email yang valid.' })
  }
  return errors
}

function validatePassword(state: typeof password): FormError[] {
  const errors: FormError[] = []
  if (!state.current) {
    errors.push({ name: 'current', message: 'Password saat ini wajib diisi.' })
  }
  if (state.next.length < 8) {
    errors.push({ name: 'next', message: 'Minimal 8 karakter.' })
  }
  if (state.confirm !== state.next) {
    errors.push({ name: 'confirm', message: 'Konfirmasi tidak sama.' })
  }
  return errors
}

function notAvailable() {
  toast.add({ title: 'Belum tersedia', description: 'Endpoint akun staff belum tersedia di API.', color: 'warning', icon: 'i-lucide-construction' })
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h1 class="text-2xl font-bold text-highlighted">
        Pengaturan
      </h1>
      <p class="mt-1 text-sm text-muted">
        Kelola data akun dan keamanan login Anda.
      </p>
    </div>

    <UCard>
      <template #header>
        <h2 class="font-semibold text-highlighted">
          Data Akun
        </h2>
        <p class="text-xs text-muted">
          Nama dan email dipakai untuk login serta tampilan profil.
        </p>
      </template>
      <UForm
        v-if="can('admin.profile.update')"
        :state="account"
        :validate="validateAccount"
        class="grid grid-cols-1 gap-4 md:grid-cols-2"
        @submit="notAvailable"
      >
        <UFormField
          label="Nama"
          name="name"
          required
        >
          <UInput
            v-model="account.name"
            autocomplete="name"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Email"
          name="email"
          required
        >
          <UInput
            v-model="account.email"
            type="email"
            autocomplete="email"
            class="w-full"
          />
        </UFormField>
        <div class="flex justify-end md:col-span-2">
          <UButton
            type="submit"
            label="Simpan"
          />
        </div>
      </UForm>
      <p v-else>
        {{ account.email }}
      </p>
    </UCard>

    <UCard v-if="can('admin.profile.change_password')">
      <template #header>
        <h2 class="font-semibold text-highlighted">
          Ganti Password
        </h2>
        <p class="text-xs text-muted">
          Minimal 8 karakter. Masukkan password saat ini untuk konfirmasi.
        </p>
      </template>
      <UForm
        :state="password"
        :validate="validatePassword"
        class="grid grid-cols-1 gap-4 md:grid-cols-2"
        @submit="notAvailable"
      >
        <UFormField
          label="Password Saat Ini"
          name="current"
          required
          class="md:col-span-2"
        >
          <UInput
            v-model="password.current"
            type="password"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Password Baru"
          name="next"
          required
        >
          <UInput
            v-model="password.next"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Konfirmasi Password Baru"
          name="confirm"
          required
        >
          <UInput
            v-model="password.confirm"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <div class="flex justify-end md:col-span-2">
          <UButton
            type="submit"
            label="Ganti Password"
          />
        </div>
      </UForm>
    </UCard>
  </div>
</template>
