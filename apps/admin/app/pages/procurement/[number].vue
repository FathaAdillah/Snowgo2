<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Batch, BatchDetail } from '~/composables/useProcurement'
import { batchStages, batchStatuses } from '~/composables/useProcurement'

definePageMeta({ permission: 'procurement.batch.view', key: route => route.path })
const number = String(useRoute().params.number)
useHead({ title: `${number} · Batch Pengadaan` })
const { data, status, refresh } = useFetch<BatchDetail>(`${batchApi}/${encodeURIComponent(number)}`, inventoryFetch)
const batch = computed(() => data.value?.data)
const columns: TableColumn<Batch['items'][number]>[] = [
  { accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'sku', header: 'SKU' }, { accessorKey: 'qty', header: 'Ditugaskan' },
  { accessorKey: 'received_qty', header: 'Diterima' }, { accessorKey: 'failed_qty', header: 'Gagal final' }
]
</script>

<template>
  <section class="min-w-0 space-y-5">
    <UButton
      to="/procurement"
      variant="link"
      icon="i-lucide-arrow-left"
      label="Daftar batch"
    />
    <h1 class="text-2xl font-bold text-highlighted">
      Batch {{ number }}
    </h1>
    <UAlert
      v-if="status === 'error'"
      title="Detail batch tidak dapat dimuat."
      color="error"
      :actions="[{ label: 'Coba lagi', onClick: () => refresh() }]"
    />
    <p
      v-else-if="status !== 'success' || !batch"
      role="status"
    >
      Memuat detail…
    </p>
    <template v-else>
      <UCard>
        <div class="flex flex-wrap items-center gap-3">
          <UBadge>{{ batchStatuses[batch.status] }}</UBadge><strong>{{ batchStages[batch.stage] }}</strong>
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              Sumber
            </dt><dd>{{ batch.campaign_number ?? batch.request_number }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Estimasi tiba
            </dt><dd>{{ batch.eta_date ?? 'Belum ditetapkan' }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Referensi pemasok
            </dt><dd>{{ batch.supplier_ref ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Catatan
            </dt><dd>{{ batch.notes ?? '—' }}</dd>
          </div>
          <div v-if="batch.closure_reason">
            <dt class="text-muted">
              Alasan penutupan
            </dt><dd>{{ batch.closure_reason }}</dd>
          </div>
        </dl>
      </UCard>
      <UCard>
        <template #header>
          <h2 class="font-semibold">
            Unit per pesanan
          </h2>
        </template><UTable
          :data="batch.items"
          :columns="columns"
          :get-row-id="item => item.order_item_id"
        />
      </UCard>
      <ProcurementActions
        :batch="batch"
        @saved="refresh()"
      />
      <UCard>
        <template #header>
          <h2 class="font-semibold">
            Riwayat tahap
          </h2>
        </template>
        <ol class="space-y-2">
          <li
            v-for="e in batch.stage_events"
            :key="e.stage"
          >
            <strong>{{ batchStages[e.stage] }}</strong> · {{ inventoryTime(e.occurred_at) }} <span v-if="e.eta_date">· ETA {{ e.eta_date }}</span><p v-if="e.note">
              {{ e.note }}
            </p>
          </li>
        </ol>
      </UCard>
    </template>
  </section>
</template>
