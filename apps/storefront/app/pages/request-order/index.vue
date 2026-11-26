<script setup lang="ts">
definePageMeta({ middleware: 'customer' })
const page = ref(1)
const { data, status, refresh } = await useFetch<StoreRead<'/request-orders'>>('/api/v1/request-orders', { server: false, query: { page } })
const { busy, problem, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
const title = ref('')
const description = ref('')
const budget = ref<number | ''>()
const target = ref('')
const item = () => ({ name: '', qty: 1, source_url: '', options: '' })
const items = ref([item()])
async function submit() {
  await run(async () => {
    const response = await write<{ data: { number: string } }>('/request-orders', {
      title: title.value, description: description.value, ...(typeof budget.value === 'number' ? { budget_amount: budget.value } : {}),
      ...(target.value ? { target_date: target.value } : {}),
      items: items.value.map(i => ({ name: i.name, qty: i.qty, ...(i.source_url ? { source_url: i.source_url } : {}), ...(i.options ? { options: i.options } : {}) }))
    })
    await navigateTo('/request-order/' + response.data.number)
  })
}
</script>

<template>
  <section class="stack">
    <h1>Request Order</h1>
    <p>Ajukan barang dari luar negeri. Kamu bisa memeriksa harga dan menyetujui penawaran sebelum pesanan dibuat.</p>
    <form
      class="panel stack"
      @submit.prevent="submit"
    >
      <h2>Pengajuan baru</h2>
      <label>Judul<input
        v-model="title"
        maxlength="160"
        required
      >
      </label>
      <label>Deskripsi<textarea
        v-model="description"
        required
        maxlength="5000"
      />
      </label>
      <div class="form-grid">
        <label>Budget (opsional)<input
          v-model.number="budget"
          type="number"
          min="0"
        >
        </label>
        <label>Target tanggal (opsional)<input
          v-model="target"
          type="date"
        >
        </label>
      </div>
      <fieldset
        v-for="(row, i) in items"
        :key="i"
        class="form-grid"
      >
        <legend>Barang {{ i + 1 }}</legend>
        <label>Nama barang<input
          v-model="row.name"
          required
        >
        </label>
        <label>Jumlah<input
          v-model.number="row.qty"
          required
          type="number"
          min="1"
          max="10000"
        >
        </label>
        <label>Tautan produk<input
          v-model="row.source_url"
          type="url"
        >
        </label>
        <label>Varian atau opsi<input v-model="row.options">
        </label>
        <button
          v-if="items.length > 1"
          class="subtle"
          type="button"
          @click="items.splice(i, 1)"
        >
          Hapus barang
        </button>
      </fieldset>
      <div class="actions">
        <button
          class="subtle"
          type="button"
          @click="items.push(item())"
        >
          Tambah barang
        </button>
        <button :disabled="busy">
          Kirim pengajuan
        </button>
      </div>
      <p
        v-if="problem"
        role="alert"
      >
        {{ problem }}
      </p>
    </form>
    <h2>Pengajuan saya</h2>
    <StoreResult
      :status="status"
      :empty="!data?.data.length"
      empty-label="Belum ada pengajuan."
      @retry="refresh()"
    >
      <NuxtLink
        v-for="r in data?.data"
        :key="r.number"
        class="list-row"
        :to="'/request-order/' + r.number"
      >{{ r.title }} <span class="badge">{{ r.status }}</span>
      </NuxtLink>
    </StoreResult>
    <div class="actions">
      <button
        v-if="page > 1"
        @click="page--"
      >
        Sebelumnya
      </button>
      <button
        v-if="data && page < data.meta.last_page"
        @click="page++"
      >
        Berikutnya
      </button>
    </div>
  </section>
</template>
