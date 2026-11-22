<script setup lang="ts">
import type { PurchaseMethod } from '@snowego/api-types'

useHead({ title: 'Dashboard · Snowego Admin' })

const { range, data, status, refresh } = useDashboard()
const { user } = useAdminAuth()

const today = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())

const summary = computed(() => data.value?.summary)

const stats = computed(() => summary.value
  ? [
      { label: 'Penjualan Bersih', value: formatCompactRupiah(summary.value.net_amount), sub: `Dibayar ${formatCompactRupiah(summary.value.paid_amount)}`, icon: 'i-lucide-banknote', accent: 'bg-primary', iconClass: 'bg-primary/10 text-primary' },
      { label: 'Total Pesanan', value: summary.value.orders_count.toLocaleString('id-ID'), sub: 'pada periode ini', icon: 'i-lucide-shopping-cart', accent: 'bg-secondary', iconClass: 'bg-secondary/15 text-secondary' },
      { label: 'Pembayaran Pending', value: summary.value.pending_payments.toLocaleString('id-ID'), sub: 'menunggu verifikasi', icon: 'i-lucide-clock', accent: 'bg-warning', iconClass: 'bg-warning/15 text-warning' },
      { label: 'Stok Menipis', value: summary.value.low_stock_count.toLocaleString('id-ID'), sub: 'SKU di bawah batas', icon: 'i-lucide-package-minus', accent: 'bg-error', iconClass: 'bg-error/10 text-error' }
    ]
  : [])

const queues = computed(() => summary.value
  ? [
      { label: 'Pengiriman Gagal', value: summary.value.shipment_failures, icon: 'i-lucide-truck' },
      { label: 'Return Terbuka', value: summary.value.open_returns, icon: 'i-lucide-undo-2' },
      { label: 'Refund Terbuka', value: summary.value.open_refunds, icon: 'i-lucide-hand-coins' }
    ]
  : [])

const methodLabels: Record<PurchaseMethod, { label: string, color: string }> = {
  ready_stock: { label: 'Siap Kirim', color: 'bg-primary' },
  preorder: { label: 'Preorder', color: 'bg-secondary' },
  request_order: { label: 'Request Order', color: 'bg-warning' }
}

const methods = computed(() => {
  const rows = summary.value?.by_method ?? []
  const total = rows.reduce((sum, row) => sum + row.orders_count, 0) || 1
  return rows.map(row => ({ ...row, ...methodLabels[row.method], pct: Math.round((row.orders_count / total) * 100) }))
})
</script>

<template>
  <div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Dashboard
        </h1>
        <p class="mt-1 text-sm text-muted">
          Selamat datang kembali, {{ user?.name ?? 'Admin' }}! Berikut ringkasan toko hari ini.
        </p>
      </div>
      <div class="flex items-center gap-2 rounded-lg border border-default bg-default px-3 py-2 text-xs text-muted">
        <UIcon
          name="i-lucide-calendar-days"
          class="size-3.5"
        />
        {{ today }}
      </div>
    </div>

    <UAlert
      v-if="data?.sample"
      color="info"
      variant="subtle"
      icon="i-lucide-info"
      title="Data contoh"
      description="Endpoint laporan (API-RPT-001) belum tersedia, jadi angka di bawah adalah data contoh untuk meninjau tampilan."
    />

    <div
      v-if="status === 'pending' && !data"
      class="flex justify-center py-20"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="size-8 animate-spin text-primary"
      />
    </div>

    <template v-else-if="summary">
      <DashboardSection
        title="Ringkasan Penjualan"
        icon="i-lucide-chart-column"
      >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <DashboardStatCard
            v-for="stat in stats"
            :key="stat.label"
            v-bind="stat"
          />
        </div>

        <UCard :ui="{ header: 'p-4 sm:px-5' }">
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon
                  name="i-lucide-trending-up"
                  class="size-4 text-primary"
                />
                Tren Penjualan
              </h3>
              <div class="flex flex-wrap items-center gap-4">
                <DashboardDateRangePicker v-model="range" />
                <div class="flex items-center gap-4 text-xs text-muted">
                  <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-primary" />Penjualan (Rp)</span>
                  <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-secondary" />Jumlah Pesanan</span>
                </div>
              </div>
            </div>
          </template>
          <DashboardTrendChart :points="data?.trend ?? []" />
        </UCard>
      </DashboardSection>

      <DashboardSection
        title="Komposisi & Antrean"
        icon="i-lucide-chart-pie"
      >
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <UCard>
            <template #header>
              <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon
                  name="i-lucide-layers"
                  class="size-4 text-primary"
                />
                Pesanan per Cara Beli
              </h3>
            </template>
            <div class="flex flex-col gap-4">
              <div
                v-for="method in methods"
                :key="method.method"
                class="flex flex-col gap-1.5"
              >
                <div class="flex items-center justify-between text-sm">
                  <span class="font-medium text-highlighted">{{ method.label }}</span>
                  <span class="text-muted">{{ method.orders_count }} pesanan · {{ formatCompactRupiah(method.paid_amount) }}</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-elevated">
                  <div
                    class="h-full rounded-full"
                    :class="method.color"
                    :style="{ width: `${method.pct}%` }"
                  />
                </div>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header>
              <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon
                  name="i-lucide-list-todo"
                  class="size-4 text-primary"
                />
                Antrean Operasional
              </h3>
            </template>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div
                v-for="queue in queues"
                :key="queue.label"
                class="flex flex-col items-center gap-2 rounded-lg border border-default p-4 text-center"
              >
                <span
                  class="flex size-10 items-center justify-center rounded-lg"
                  :class="queue.value > 0 ? 'bg-warning/10 text-warning' : 'bg-primary/10 text-primary'"
                >
                  <UIcon
                    :name="queue.icon"
                    class="size-5"
                  />
                </span>
                <span class="text-2xl font-bold text-highlighted">{{ queue.value }}</span>
                <span class="text-xs text-muted">{{ queue.label }}</span>
              </div>
            </div>
            <p class="mt-4 flex items-center justify-between border-t border-default pt-3 text-sm">
              <span class="text-muted">Total refund periode ini</span>
              <span class="font-semibold text-highlighted">{{ formatRupiah(summary.refund_amount) }}</span>
            </p>
          </UCard>
        </div>
      </DashboardSection>
    </template>

    <UEmpty
      v-else
      icon="i-lucide-circle-alert"
      title="Gagal memuat dashboard"
      :actions="[{ label: 'Coba lagi', variant: 'outline', onClick: () => refresh() }]"
    />
  </div>
</template>
