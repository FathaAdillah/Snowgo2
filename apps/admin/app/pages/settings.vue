<script setup lang="ts">
definePageMeta({ permission: 'admin.profile.view' })
useHead({ title: 'Setting · Snowego Admin' })

type Action = 'name' | 'email' | 'email/confirm' | 'password' | 'mfa'
const { user, can, refresh, post } = useAdminAuth()
const toast = useToast()
const name = ref(user.value?.display_name ?? '')
const action = ref<Action>()
const busy = ref(false)
const notice = ref('')
const fields = reactive({ email: '', password: '', password_confirmation: '', current_password: '', code: '', token: '' })
const mfaRequired = computed(() => user.value?.login_mfa_required !== false)
const mfaLabel = computed(() => mfaRequired.value ? 'Matikan MFA saat login' : 'Aktifkan MFA saat login')
const titles = computed(() => ({
  'name': 'Simpan nama',
  'password': 'Ganti password',
  'mfa': mfaLabel.value,
  'email': 'Ganti email login',
  'email/confirm': 'Verifikasi email baru'
}))
const canManageMfa = computed(() => can('admin.profile.manage_mfa') && !user.value?.roles.includes('super_admin'))

function open(next?: Action) {
  action.value = next
  notice.value = ''
  Object.assign(fields, { email: '', password: '', password_confirmation: '', current_password: '', code: '', token: '' })
}

async function save(kind: Action) {
  if (busy.value) return
  if (kind === 'password' && fields.password !== fields.password_confirmation) {
    notice.value = 'Konfirmasi password baru tidak sama.'
    return
  }
  const proof = { current_password: fields.current_password, code: fields.code }
  const bodies = {
    'name': { display_name: name.value.trim() },
    'email': { ...proof, email: fields.email.trim() },
    'email/confirm': { token: fields.token.trim() },
    'password': { ...proof, password: fields.password, password_confirmation: fields.password_confirmation },
    'mfa': { ...proof, enabled: !mfaRequired.value }
  }
  busy.value = true
  notice.value = ''
  try {
    await post(`/api/v1/admin/auth/profile/${kind}`, bodies[kind])
    if (kind === 'email') {
      open('email/confirm')
      toast.add({ title: 'Periksa kotak masuk email baru untuk kode verifikasi.' })
    } else if (kind === 'name') {
      await refresh()
      toast.add({ title: 'Nama tampilan disimpan.' })
    } else {
      open()
      user.value = null
      clearNuxtData()
      toast.add({ title: 'Perubahan tersimpan. Silakan masuk kembali.' })
      await navigateTo('/login', { replace: true })
    }
  } catch (error) {
    notice.value = authErrorMessage(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h1 class="text-2xl font-bold text-highlighted">
        Setting
      </h1>
      <p class="text-sm text-muted">
        Kelola profil dan keamanan akun Anda.
      </p>
    </div>
    <UCard>
      <template #header>
        <h2 class="font-semibold">
          Data akun
        </h2>
      </template>
      <form
        v-if="can('admin.profile.update')"
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="save('name')"
      >
        <UFormField label="Nama tampilan">
          <UInput
            v-model="name"
            autocomplete="name"
            maxlength="100"
            required
          />
        </UFormField>
        <UButton
          type="submit"
          label="Simpan nama"
          :loading="busy && !action"
        />
      </form>
      <p v-else>
        Nama tampilan: {{ user?.display_name }}
      </p>
      <p class="mt-4">
        Email login: {{ user?.email }}
      </p>
      <div
        v-if="can('admin.profile.change_email')"
        class="mt-2 flex flex-wrap gap-2"
      >
        <UButton
          variant="outline"
          label="Ganti email login"
          @click="open('email')"
        />
        <UButton
          variant="link"
          label="Saya sudah punya kode verifikasi"
          @click="open('email/confirm')"
        />
      </div>
      <UAlert
        v-if="notice && !action"
        color="error"
        :title="notice"
        class="mt-3"
      />
    </UCard>
    <UCard>
      <template #header>
        <h2 class="font-semibold">
          Keamanan
        </h2>
      </template>
      <p>MFA saat login: {{ mfaRequired ? 'Aktif' : 'Nonaktif' }}.</p>
      <p class="text-sm text-muted">
        {{ user?.roles.includes('super_admin') ? 'Superadmin wajib menggunakan MFA.' : 'Authenticator tetap diperlukan untuk mengonfirmasi perubahan sensitif.' }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <UButton
          v-if="can('admin.profile.change_password')"
          variant="outline"
          label="Ganti password"
          @click="open('password')"
        />
        <UButton
          v-if="canManageMfa"
          variant="outline"
          :color="mfaRequired ? 'warning' : 'primary'"
          :label="mfaLabel"
          @click="open('mfa')"
        />
      </div>
    </UCard>
    <UModal
      v-if="action"
      :open="true"
      :title="titles[action]"
      :dismissible="!busy"
      :description="action === 'email' ? 'Email lama tetap berlaku sampai alamat baru diverifikasi.' : 'Setelah perubahan berhasil, semua perangkat harus login kembali.'"
      @update:open="visible => { if (!visible && !busy) open() }"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="action && save(action)"
        >
          <UAlert
            v-if="notice"
            color="error"
            :title="notice"
          />
          <UFormField
            v-if="action === 'email'"
            label="Email baru"
          >
            <UInput
              v-model="fields.email"
              type="email"
              autocomplete="email"
              maxlength="254"
              required
              class="w-full"
            />
          </UFormField>
          <UFormField
            v-if="action === 'email/confirm'"
            label="Kode dari email baru"
            description="Kode berlaku 15 menit."
          >
            <UInput
              v-model="fields.token"
              autocomplete="one-time-code"
              maxlength="24"
              required
              class="w-full"
            />
          </UFormField>
          <template v-else>
            <UFormField label="Password saat ini">
              <UInput
                v-model="fields.current_password"
                type="password"
                autocomplete="current-password"
                required
                class="w-full"
              />
            </UFormField>
            <template v-if="action === 'password'">
              <UFormField
                label="Password baru"
                description="Gunakan 12–128 karakter."
              >
                <UInput
                  v-model="fields.password"
                  type="password"
                  autocomplete="new-password"
                  minlength="12"
                  maxlength="128"
                  required
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Konfirmasi password baru">
                <UInput
                  v-model="fields.password_confirmation"
                  type="password"
                  autocomplete="new-password"
                  required
                  class="w-full"
                />
              </UFormField>
            </template>
            <UFormField
              label="Kode authenticator"
              description="Gunakan kode 6 digit yang belum dipakai."
            >
              <UInput
                v-model="fields.code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                required
                class="w-full"
              />
            </UFormField>
          </template>
          <div class="flex justify-end gap-2">
            <UButton
              label="Batal"
              variant="outline"
              :disabled="busy"
              @click="open()"
            />
            <UButton
              type="submit"
              :label="action === 'email' ? 'Kirim kode' : 'Konfirmasi'"
              :loading="busy"
            />
          </div>
        </form>
      </template>
    </UModal>
  </div>
</template>
