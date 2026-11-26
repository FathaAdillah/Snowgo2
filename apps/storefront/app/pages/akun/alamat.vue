<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
type Address = StoreRead<'/addresses'>['data'][number]
const { data, error, refresh } = await useFetch<StoreRead<'/addresses'>>('/api/v1/addresses', { server: false, query: { per_page: 100 } })
const edit = ref<Address>()
const { busy, notice, problem, run } = useCustomerAction()
function open(a?: Address) {
  edit.value = a ? { ...a } : { id: '', recipient: '', phone: '', line1: '', line2: '', postal_code: '', district_code: '', is_default: false }
}
async function save() {
  if (!edit.value) return
  const { id, ...body } = edit.value
  await run(async () => {
    await customerWrite(id ? '/addresses/' + id : '/addresses', id ? 'PATCH' : 'POST', body)
    edit.value = undefined
    await refresh()
  }, 'Alamat tersimpan.')
}
async function remove(id: string) {
  if (!window.confirm('Hapus alamat ini?')) return
  await run(async () => {
    await customerWrite('/addresses/' + id, 'DELETE')
    edit.value = undefined
    await refresh()
  }, 'Alamat dihapus.')
}
</script>

<template>
  <section class="stack">
    <h1>Alamat pengiriman</h1><CustomerLinks />
    <p
      v-if="error"
      role="alert"
    >
      Alamat gagal dimuat. <button @click="refresh()">
        Coba lagi
      </button>
    </p>
    <template v-else-if="data">
      <article
        v-for="a in data.data"
        :key="a.id"
        class="list-row"
      >
        <div><h3>{{ a.recipient }} {{ a.is_default ? '· Utama' : '' }}</h3><p>{{ a.line1 }} {{ a.line2 }}, {{ a.postal_code }}</p></div><div class="actions">
          <button @click="open(a)">
            Ubah
          </button><button
            class="subtle"
            @click="remove(a.id)"
          >
            Hapus
          </button>
        </div>
      </article><p v-if="!data.data.length">
        Belum ada alamat tersimpan.
      </p>
    </template>
    <div>
      <button @click="open()">
        Tambah alamat
      </button>
    </div>
    <form
      v-if="edit"
      class="panel stack"
      @submit.prevent="save"
    >
      <div class="form-grid">
        <label>Penerima<input
          v-model="edit.recipient"
          required
        ></label><label>Telepon<input
          v-model="edit.phone"
          required
          type="tel"
        ></label><label>Jalan dan nomor rumah<input
          v-model="edit.line1"
          required
        ></label><label>Detail tambahan<input v-model="edit.line2"></label><DistrictPicker v-model="edit.district_code" /><label>Kode pos<input
          v-model="edit.postal_code"
          required
        ></label>
      </div><label><span><input
        v-model="edit.is_default"
        type="checkbox"
      > Gunakan sebagai alamat utama</span></label><div class="actions">
        <button :disabled="busy">
          Simpan
        </button><button
          type="button"
          class="subtle"
          @click="edit = undefined"
        >
          Batal
        </button>
      </div>
    </form>
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
    </p>
  </section>
</template>
