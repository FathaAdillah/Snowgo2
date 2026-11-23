<script setup lang="ts">
import type { PermissionCatalog } from '@snowego/api-types'

const props = defineProps<{ catalog: PermissionCatalog['data'], readonly?: boolean }>()
const selected = defineModel<string[]>({ required: true })
const labels: Record<string, string> = {
  'admin': 'Administrasi', 'foundation': 'Berkas', 'reporting': 'Laporan', 'dashboard': 'Dashboard',
  'staff': 'Pengguna', 'staff.sessions': 'Sesi pengguna', 'roles': 'Role & Izin', 'file': 'File privat', 'profile': 'Profil',
  'create': 'Buat', 'view': 'Lihat', 'update': 'Ubah', 'delete': 'Hapus', 'download': 'Unduh',
  'assign_roles': 'Tetapkan role', 'change_status': 'Ubah status', 'assign_permissions': 'Atur izin'
}
function label(key: string) {
  return labels[key] ?? key.replaceAll('_', ' ')
}
const section = ref('')
const resource = ref('')
const sections = computed(() => [...new Set(props.catalog.map(p => p.section))])
const currentSection = computed(() => section.value || sections.value[0])
const sectionItems = computed(() => props.catalog.filter(p => p.section === currentSection.value))
const resources = computed(() => [...new Set(sectionItems.value.map(p => p.resource))])
const currentResource = computed(() => resource.value || resources.value[0])
const actions = computed(() => sectionItems.value.filter(p => p.resource === currentResource.value))
function chooseSection(value: string) {
  section.value = value
  resource.value = ''
}
function count(key: string) {
  return sectionItems.value.filter(p => p.resource === key && selected.value.includes(p.name)).length
}
function toggle(item: PermissionCatalog['data'][number]) {
  if (props.readonly || !item.assignable) return
  selected.value = selected.value.includes(item.name) ? selected.value.filter(p => p !== item.name) : [...selected.value, item.name]
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">
          Izin akses
        </h2>
        <UBadge
          color="primary"
          variant="subtle"
        >
          {{ selected.length }} izin dipilih
        </UBadge>
      </div>
    </template>
    <div
      class="flex flex-wrap gap-2 border-b border-default pb-4"
      aria-label="Bagian"
    >
      <UButton
        v-for="key in sections"
        :key="key"
        :variant="key === currentSection ? 'solid' : 'ghost'"
        :aria-pressed="key === currentSection"
        :label="label(key)"
        @click="chooseSection(key)"
      />
    </div>
    <div class="mt-4 grid gap-5 md:grid-cols-[14rem_1fr]">
      <div class="space-y-1">
        <UButton
          v-for="key in resources"
          :key="key"
          color="neutral"
          :variant="key === currentResource ? 'soft' : 'ghost'"
          class="w-full justify-between"
          :aria-pressed="key === currentResource"
          @click="resource = key"
        >
          {{ label(key) }} <UBadge
            color="neutral"
            variant="subtle"
          >
            {{ count(key) }}
          </UBadge>
        </UButton>
      </div>
      <div>
        <h3
          v-if="currentResource"
          class="mb-3 font-semibold"
        >
          {{ label(currentResource) }}
        </h3>
        <div class="flex flex-wrap gap-2">
          <UButton
            v-for="item in actions"
            :key="item.name"
            :variant="selected.includes(item.name) ? 'soft' : 'outline'"
            :color="selected.includes(item.name) ? 'primary' : 'neutral'"
            :icon="selected.includes(item.name) ? 'i-lucide-check' : 'i-lucide-plus'"
            :aria-pressed="selected.includes(item.name)"
            :disabled="readonly || !item.assignable"
            :title="item.name"
            :label="label(item.action)"
            @click="toggle(item)"
          />
        </div>
      </div>
    </div>
  </UCard>
</template>
