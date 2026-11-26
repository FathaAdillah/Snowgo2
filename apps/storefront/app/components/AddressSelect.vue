<script setup lang="ts">
const model = defineModel<string>({ required: true })
const page = ref(1)
const { data, status, refresh } = await useFetch<StoreRead<'/addresses'>>('/api/v1/addresses', { server: false, query: { page, per_page: 100 } })
watch(data, (rows) => {
  if (rows && !rows.data.some(a => a.id === model.value)) model.value = (rows.data.find(a => a.is_default) ?? rows.data[0])?.id ?? ''
}, { immediate: true })
</script>

<template>
  <div>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <label>Alamat pengiriman<select
        v-model="model"
        required
      >
        <option
          value=""
          disabled
        >Pilih alamat</option>
        <option
          v-for="a in data?.data"
          :key="a.id"
          :value="a.id"
        >{{ a.recipient }} · {{ a.line1 }}, {{ a.postal_code }}</option>
      </select>
      </label>
      <div class="actions">
        <button
          v-if="page > 1"
          type="button"
          @click="page--"
        >
          Alamat sebelumnya
        </button>
        <button
          v-if="data && page < data.meta.last_page"
          type="button"
          @click="page++"
        >
          Alamat berikutnya
        </button>
      </div>
    </StoreResult>
    <NuxtLink to="/akun/alamat">Kelola alamat</NuxtLink>
  </div>
</template>
