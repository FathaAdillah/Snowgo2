<script setup lang="ts">
const model = defineModel<string>({ required: true })
const levels = ['COUNTRY', 'PROVINCE', 'CITY', 'DISTRICT'] as const
const names = ['Negara', 'Provinsi', 'Kota', 'Kecamatan']
type Region = StoreRead<'/regions'>['data'][number]
const options = ref<Region[][]>([[], [], [], []])
const selected = ref(['', '', '', ''])
const problem = ref('')
let revision = 0
async function load(index: number, parent?: string) {
  const current = ++revision
  try {
    const rows: Region[] = []
    let page = 1
    let last: number
    do {
      const response = await $fetch<StoreRead<'/regions'>>('/api/v1/regions', { query: { kind: levels[index], parent, per_page: 100, page } })
      rows.push(...response.data)
      last = response.meta.last_page
      page++
    } while (page <= last && current === revision)
    if (current === revision) options.value[index] = rows
  } catch {
    if (current === revision) problem.value = 'Wilayah gagal dimuat. Pilih ulang atau masukkan kode kecamatan.'
  }
}
onMounted(() => load(0))
function choose(index: number) {
  revision++
  problem.value = ''
  selected.value.fill('', index + 1)
  options.value.fill([], index + 1)
  model.value = index === 3 ? selected.value[3] ?? '' : ''
  if (index < 3 && selected.value[index]) void load(index + 1, selected.value[index])
}
</script>

<template>
  <div class="stack">
    <label
      v-for="(level, i) in levels"
      v-show="options[i]?.length"
      :key="level"
    >{{ names[i] }}<select
      v-model="selected[i]"
      @change="choose(i)"
    >
      <option value="">Pilih {{ names[i] }}</option>
      <option
        v-for="r in options[i]"
        :key="r.code"
        :value="r.code"
      >{{ r.name }}</option>
    </select>
    </label>
    <label>Kode kecamatan<input
      v-model="model"
      required
    >
    </label>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </div>
</template>
