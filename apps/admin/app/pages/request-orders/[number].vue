<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { FileDownload, RequestCase, RequestDetail } from '~/composables/useRequestOrders'
import { caseStatuses } from '~/composables/useRequestOrders'

definePageMeta({ permission: 'request_orders.request.view', key: route => route.path })
const number = String(useRoute().params.number)
useHead({ title: `${number} · Request Order` })
const { data, status, refresh } = useFetch<RequestDetail>(`${caseApi}/${encodeURIComponent(number)}`, inventoryFetch)
const record = computed(() => data.value?.data)
const { can } = useAdminAuth()
const columns: TableColumn<RequestCase['items'][number]>[] = [
  { accessorKey: 'name', header: 'Barang' }, { accessorKey: 'qty', header: 'Jumlah' }, { accessorKey: 'options', header: 'Opsi' },
  { accessorKey: 'source_url', header: 'Sumber (URL)' }, { accessorKey: 'notes', header: 'Catatan' }
]
const downloadError = ref('')
async function download(id: string) {
  downloadError.value = ''
  try {
    const response = await $fetch<FileDownload>(`/api/v1/admin/files/${encodeURIComponent(id)}/download`, { cache: 'no-store' })
    window.location.assign(response.data.url)
  } catch (err) {
    downloadError.value = authErrorMessage(err)
  }
}
</script>

<template>
  <section class="min-w-0 space-y-5">
    <UButton
      to="/request-orders"
      variant="link"
      icon="i-lucide-arrow-left"
      label="Antrean request"
    />
    <h1 class="text-2xl font-bold text-highlighted">
      Request {{ number }}
    </h1>
    <UAlert
      v-if="status === 'error'"
      title="Detail request tidak dapat dimuat."
      color="error"
      :actions="[{ label: 'Coba lagi', onClick: () => refresh() }]"
    />
    <p
      v-else-if="status !== 'success' || !record"
      role="status"
    >
      Memuat detail…
    </p>
    <template v-else>
      <UCard>
        <div class="flex flex-wrap items-center gap-3">
          <h2 class="text-lg font-semibold">
            {{ record.title }}
          </h2><UBadge>{{ caseStatuses[record.status] }}</UBadge>
        </div>
        <p class="my-3 whitespace-pre-wrap break-words">
          {{ record.description }}
        </p>
        <dl class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              Anggaran
            </dt><dd>{{ record.budget_amount === null ? 'Tidak ditentukan' : `Rp ${record.budget_amount.toLocaleString('id-ID')}` }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Target
            </dt><dd>{{ record.target_date ?? 'Tidak ditentukan' }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Penanggung jawab
            </dt><dd>{{ record.assigned_to ? `Staf #${record.assigned_to}` : 'Belum ditugaskan' }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Diajukan
            </dt><dd>{{ inventoryTime(record.created_at) }}</dd>
          </div>
        </dl>
      </UCard>
      <UCard>
        <template #header>
          <h2 class="font-semibold">
            Barang yang diminta
          </h2>
        </template>
        <UTable
          :data="record.items"
          :columns="columns"
          :get-row-id="item => item.id"
        />
      </UCard>
      <UCard v-if="can('foundation.file.view')">
        <template #header>
          <h2 class="font-semibold">
            Lampiran pelanggan
          </h2>
        </template>
        <p v-if="!record.files.length">
          Belum ada lampiran.
        </p>
        <UAlert
          v-if="downloadError"
          color="error"
          :title="downloadError"
        />
        <ul class="space-y-2">
          <li
            v-for="(file, i) in record.files"
            :key="file.id"
            class="flex flex-wrap items-center gap-2"
          >
            Lampiran {{ i + 1 }} · {{ file.mime }} · {{ file.status }}
            <UButton
              v-if="file.status === 'READY' && can('foundation.file.download')"
              label="Unduh"
              variant="outline"
              @click="download(file.id)"
            />
          </li>
        </ul>
      </UCard>
      <UCard>
        <template #header>
          <h2 class="font-semibold">
            Percakapan dan catatan
          </h2>
        </template>
        <p v-if="!record.messages.length">
          Belum ada pesan.
        </p>
        <ol class="space-y-3">
          <li
            v-for="(m, i) in record.messages"
            :key="i"
            class="rounded-lg border border-default p-3"
          >
            <UBadge
              :color="m.visibility === 'STAFF' ? 'warning' : 'primary'"
              variant="subtle"
            >
              {{ m.visibility === 'STAFF' ? 'Internal — hanya staf' : 'Terlihat oleh pelanggan' }}
            </UBadge>
            <span class="ml-2 text-sm text-muted">{{ m.author_label }} · {{ inventoryTime(m.created_at) }}</span>
            <p class="mt-1 whitespace-pre-wrap break-words">
              {{ m.body }}
            </p>
          </li>
        </ol>
      </UCard>
      <RequestCaseActions
        :record="record"
        @saved="refresh()"
      />
    </template>
  </section>
</template>
