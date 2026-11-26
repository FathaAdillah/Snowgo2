<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import type { InventoryAction } from '~/composables/useInventory'

type Page = paths['/admin/shipments']['get']['responses'][200]['content']['application/json']
type Package = Page['data'][number]
definePageMeta({ permission: 'shipping.shipment.view' })
const { can, csrf } = useAdminAuth()
const allowed = (action: string) => can('shipping.shipment.manage') && can(`shipping.shipment.${action}`)
const filter = ref('Semua')
const { params, changePage } = useInventoryPaging(() => ({ status: filter.value === 'Semua' ? undefined : filter.value }))
const url = `${inventoryApi}/shipments`
const { data, status, refresh } = useFetch<Page>(url, { ...inventoryFetch, query: params })
const rows = computed(() => (data.value?.data ?? []).map(s => ({ ...s, id: s.number })))
const columns = [{ accessorKey: 'number', header: 'Paket' }, { accessorKey: 'order_number', header: 'Pesanan' }, { accessorKey: 'packing_status', header: 'Packing' }, { accessorKey: 'status', header: 'Status' }, { accessorKey: 'tracking_number', header: 'Resi' }, { id: 'actions', header: 'Aksi' }]
const order = ref('')
const warehouse = ref<number>()
const service = ref('')
const weight = ref<number>()
type RateOption = paths['/admin/shipment-rate-options']['get']['responses'][200]['content']['application/json']['data'][number]
const chosenRate = ref<RateOption>()
const options = ref<RateOption[]>([])
const rateProblem = ref('')
const loadingRates = ref(false)
watch([order, warehouse, weight], () => {
  if (chosenRate.value) {
    chosenRate.value = undefined
    service.value = ''
    serviceId.value = undefined
  }
  options.value = []
  rateProblem.value = ''
})
async function lookup() {
  const query = { order_number: order.value, warehouse_id: warehouse.value, measured_weight_g: weight.value }
  loadingRates.value = true
  options.value = []
  rateProblem.value = ''
  try {
    const result = await $fetch<{ data: RateOption[] }>(`${inventoryApi}/shipment-rate-options`, { query })
    if (order.value === query.order_number && warehouse.value === query.warehouse_id && weight.value === query.measured_weight_g) options.value = result.data
  } catch (err) {
    rateProblem.value = authErrorMessage(err)
  } finally {
    loadingRates.value = false
  }
}
function chooseRate(rate: RateOption) {
  chosenRate.value = rate
  service.value = rate.service_code
  serviceId.value = rate.service_id
}
const serviceId = ref<number>()
const lines = ref([{ order_item_id: '', qty: 1 }])
const selected = ref<Package>()
const tracking = ref('')
const nextStatus = ref('IN_TRANSIT')
const eventTime = ref('')
const eventNote = ref('')
const trackingOptions: Record<string, string[]> = {
  PICKED_UP: ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED', 'RETURNED'],
  IN_TRANSIT: ['OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED', 'RETURNED'],
  OUT_FOR_DELIVERY: ['DELIVERED', 'FAILED', 'RETURNED'],
  FAILED: ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'RETURNED']
}
const reason = ref('')
const quote = ref('')
const action = ref<InventoryAction>()
const stages: Record<string, string> = { PENDING: 'PACK_START', PACKING: 'PACK_FINISH', PACKED: 'READY_FOR_DISPATCH' }
function select(row: Package) {
  selected.value = row
  nextStatus.value = trackingOptions[row.status]?.[0] ?? ''
  eventNote.value = eventTime.value = ''
  tracking.value = reason.value = quote.value = ''
}
function ask(path: string, body: Record<string, unknown>, label: string) {
  action.value = { url: `${inventoryApi}/${path}`, body, method: 'POST', label }
}
async function showQuote(row: Package) {
  try {
    const result = await $fetch<{ data: { id: string, amount: number }[] }>(`${inventoryApi}/shipping-quotes`, { method: 'POST', body: { order_number: row.order_number, shipment_number: row.number }, headers: { 'X-XSRF-TOKEN': await csrf(), 'Idempotency-Key': crypto.randomUUID() } })
    if (selected.value?.number === row.number && result.data[0]) quote.value = `ID quote: ${result.data[0].id} · Rp ${result.data[0].amount} (untuk proposal di Commerce → Invoice)`
  } catch (error) { if (selected.value?.number === row.number) quote.value = authErrorMessage(error) }
}
async function saved() {
  selected.value = action.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Paket &amp; Dispatch
    </h1>
    <InventoryListTable
      title="Paket"
      :columns="columns"
      :rows="rows"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <USelect
          v-model="filter"
          :items="['Semua', 'PENDING', 'PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'FAILED', 'DELIVERED', 'RETURNED', 'CANCELLED']"
        />
      </template><template #actions="{ item }">
        <UButton
          label="Detail"
          @click="select(item)"
        />
      </template>
    </InventoryListTable>
    <UCard v-if="allowed('create')">
      <h2>Rencanakan paket</h2><form
        class="space-y-3"
        @submit.prevent="ask('shipments', { order_number: order, service_code: service, warehouse_id: warehouse, ...(weight ? { measured_weight_g: weight } : {}), ...(serviceId ? { service_id: serviceId } : {}), ...(chosenRate ? { rate_key: chosenRate.rate_key } : {}), items: lines.map(l => ({ ...l })) }, `Buat paket untuk ${order}`)"
      >
        <div class="flex flex-wrap gap-3">
          <UFormField label="Nomor pesanan">
            <UInput
              v-model="order"
              required
            />
          </UFormField><UFormField label="ID gudang">
            <UInput
              v-model="warehouse"
              type="number"
              min="1"
              required
            />
          </UFormField><UFormField label="Berat terukur PO/RO (gram, opsional)">
            <UInput
              v-model="weight"
              type="number"
              min="1"
            />
          </UFormField><UFormField label="ID layanan jika kode sama">
            <UInput
              v-model="serviceId"
              @update:model-value="chosenRate = undefined"
              type="number"
              min="1"
            />
          </UFormField><UFormField label="Kode layanan">
            <UInput
              v-model="service"
              @update:model-value="chosenRate = undefined"
              required
            />
          </UFormField>
        </div>
        <div
          v-if="can('shipping.rate.view') && can('shipping.quote.create')"
          class="space-y-2"
        >
          <UButton
            label="Cek layanan dan ongkir PO/RO"
            :disabled="!order || !warehouse || !weight"
            :loading="loadingRates"
            @click="lookup"
          />
          <UAlert
            v-if="rateProblem"
            color="error"
            :title="rateProblem"
          />
          <div
            v-for="rate in options"
            :key="rate.service_id"
          >
            <UButton
              variant="outline"
              :label="`${rate.carrier_label} · ${rate.service_code} · Rp ${rate.amount.toLocaleString('id-ID')} (${rate.source === 'MANUAL' ? 'Manual' : 'API.co.id'})`"
              @click="chooseRate(rate)"
            />
          </div>
        </div>
        <div
          v-for="(line, i) in lines"
          :key="i"
          class="flex gap-2 items-end"
        >
          <UFormField label="ID item pesanan">
            <UInput
              v-model="line.order_item_id"
              required
            />
          </UFormField><UFormField label="Jumlah">
            <UInput
              v-model="line.qty"
              type="number"
              min="1"
              required
            />
          </UFormField><UButton
            v-if="lines.length > 1"
            variant="ghost"
            label="Hapus baris"
            @click="lines.splice(i, 1)"
          />
        </div><UButton
          variant="outline"
          label="Tambah baris"
          @click="lines.push({ order_item_id: '', qty: 1 })"
        /><UButton
          type="submit"
          label="Tinjau paket"
        />
      </form>
    </UCard>
    <UCard v-if="selected">
      <h2>{{ selected.number }} · {{ selected.carrier_label }} / {{ selected.service_code }}</h2><p
        v-for="line in selected.items"
        :key="line.id"
      >
        {{ line.name }} · {{ line.qty }} unit
      </p><div
        v-if="selected.status === 'PENDING'"
        class="space-y-3"
      >
        <UButton
          v-if="can('orders.packing.manage') && stages[selected.packing_status]"
          :label="stages[selected.packing_status]"
          @click="ask(`orders/${selected.order_number}/packing-events`, { action: stages[selected.packing_status], shipment_number: selected.number }, `Ubah packing ${selected.number}`)"
        /><UButton
          v-if="can('shipping.rate.view') && can('shipping.quote.create') && selected.method !== 'READY_TO_SHIP' && selected.shipping_amount > 0"
          label="Quote ongkir PO/RO"
          @click="showQuote(selected)"
        /><p v-if="quote">
          {{ quote }}
        </p><div v-if="allowed('dispatch') && selected.packing_status === 'READY_FOR_DISPATCH'">
          <UFormField label="Nomor resi">
            <UInput v-model="tracking" />
          </UFormField><UButton
            label="Dispatch"
            :disabled="!tracking.trim()"
            @click="ask(`shipments/${selected.number}/dispatches`, { service_code: selected.service_code, tracking_number: tracking }, `Kirim ${selected.number} · ${tracking}`)"
          />
        </div><div v-if="allowed('cancel')">
          <UFormField label="Kode alasan pembatalan">
            <UInput v-model="reason" />
          </UFormField><UButton
            label="Batalkan paket"
            variant="outline"
            :disabled="!reason.trim()"
            @click="ask(`shipments/${selected.number}/cancellations`, { reason_code: reason }, `Batalkan ${selected.number}`)"
          />
        </div>
      </div>
      <ol
        v-if="selected.tracking.length"
        aria-label="Riwayat pelacakan"
      >
        <li
          v-for="(event, i) in selected.tracking"
          :key="i"
        >
          {{ event.status }} · {{ new Date(event.occurred_at).toLocaleString('id-ID') }}
        </li>
      </ol>
      <form
        v-if="can('shipping.tracking.manage') && trackingOptions[selected.status]"
        class="space-y-3"
        @submit.prevent="ask(`shipments/${selected.number}/tracking-events`, { status: nextStatus, occurred_at: new Date(eventTime).toISOString(), ...(eventNote ? { note: eventNote } : {}) }, `Catat ${nextStatus} untuk ${selected.number}`)"
      >
        <UFormField label="Status pelacakan">
          <USelect
            v-model="nextStatus"
            :items="trackingOptions[selected.status]"
          />
        </UFormField>
        <UFormField label="Waktu kejadian">
          <UInput
            v-model="eventTime"
            type="datetime-local"
            required
          />
        </UFormField>
        <UFormField label="Catatan">
          <UTextarea
            v-model="eventNote"
            :required="['FAILED', 'RETURNED'].includes(nextStatus)"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Catat pelacakan"
        />
      </form>
    </UCard>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </section>
</template>
