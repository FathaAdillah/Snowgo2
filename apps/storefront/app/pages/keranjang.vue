<script setup lang="ts">
import type { paths } from '@snowego/api-types'

definePageMeta({ middleware: 'customer' })
type Preview = paths['/checkout/previews']['post']['responses'][200]['content']['application/json']['data']
const { data, status, refresh } = await useFetch<StoreRead<'/cart'>>('/api/v1/cart', { server: false })
const { problem, notice, busy, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
const quantities = reactive<Record<string, number>>({})
const address = ref('')
const shipping = ref('')
const preview = ref<Preview>()
const result = ref<string[]>([])
const notes = ref('')
const version = computed(() => data.value?.data.version ?? 0)
watch([address, version], () => {
  shipping.value = ''
  preview.value = undefined
}, { flush: 'sync' })
watch(data, (v) => {
  for (const item of v?.data.items ?? []) quantities[item.id] = item.qty
}, { immediate: true })
async function change(id: string, remove: boolean) {
  await run(async () => {
    const path = '/cart/items/' + id
    try {
      if (remove) await customerWrite(path + '?cart_version=' + version.value, 'DELETE')
      else await customerWrite(path, 'PATCH', { qty: quantities[id], cart_version: version.value })
    } finally {
      await refresh()
    }
  })
}
function input() {
  return { cart_version: version.value, address_id: address.value, ...(shipping.value ? { shipping_quote_id: shipping.value } : {}) }
}
async function quote() {
  preview.value = undefined
  await run(async () => {
    const requested = input()
    try {
      const response = await customerWrite<{ data: Preview }>('/checkout/previews', 'POST', requested)
      if (requested.address_id === address.value && requested.cart_version === version.value) preview.value = response.data
    } catch (err) {
      if ((err as { status?: number }).status === 409) {
        shipping.value = ''
        await refresh()
      }
      throw err
    }
  })
}
async function checkout() {
  if (!preview.value?.quote_hash) return
  const body = { ...input(), quote_hash: preview.value.quote_hash, ...(notes.value ? { notes: notes.value } : {}) }
  await run(async () => {
    try {
      const response = await write<{ data: { order_numbers: string[] } }>('/checkout', body)
      result.value = response.data.order_numbers
      preview.value = undefined
      await refresh()
    } catch (err) {
      if ((err as { status?: number, data?: { code?: string } }).status === 409 && (err as { data?: { code?: string } }).data?.code !== 'REQUEST_IN_PROGRESS') {
        shipping.value = ''
        preview.value = undefined
        await refresh()
      }
      throw err
    }
  }, 'Checkout berhasil.')
}
</script>

<template>
  <section class="stack">
    <h1>Keranjang</h1>
    <div
      v-if="result.length"
      class="panel stack"
    >
      <h2>Pesanan dibuat</h2>
      <NuxtLink
        v-for="n in result"
        :key="n"
        :to="'/pesanan/' + n"
      >Lihat pesanan {{ n }} →</NuxtLink>
    </div>
    <StoreResult
      :status="status"
      :empty="!data?.data.items.length"
      empty-label="Keranjang masih kosong."
      @retry="refresh()"
    >
      <template v-if="data">
        <article
          v-for="item in data.data.items"
          :key="item.id"
          class="list-row"
        >
          <div>
            <h3>{{ item.sku }}</h3>
            <small>{{ item.campaign_slug || 'Siap kirim' }} · {{ item.term === 'DEPOSIT' ? 'DP' : 'Penuh' }}</small>
            <p>{{ rupiah(item.display_unit_amount) }}</p>
            <p v-if="item.availability !== 'AVAILABLE'">
              {{ item.availability === 'PRICE_CHANGED' ? 'Harga berubah. Periksa harga di pratinjau checkout.' : 'Penawaran tidak tersedia.' }}
            </p>
          </div>
          <form
            class="filters"
            @submit.prevent="change(item.id, false)"
          >
            <label>Jumlah<input
              v-model.number="quantities[item.id]"
              required
              type="number"
              min="1"
              max="10000"
            >
            </label>
            <button :disabled="busy">
              Simpan
            </button>
            <button
              type="button"
              class="subtle"
              :disabled="busy"
              @click="change(item.id, true)"
            >
              Hapus
            </button>
          </form>
        </article>
        <div class="panel stack">
          <h2>Checkout</h2>
          <p>Perkiraan harga produk {{ rupiah(data.data.estimated_total_amount) }}</p>
          <AddressSelect v-model="address" />
          <div>
            <button
              :disabled="busy || !address"
              @click="quote()"
            >
              Hitung tagihan & ongkir
            </button>
          </div>
          <template v-if="preview">
            <label v-if="preview.shipping_quotes.length">Pilih pengiriman<select
              v-model="shipping"
              :disabled="busy"
              @change="quote"
            >
              <option value="">Pilih layanan</option>
              <option
                v-for="o in preview.shipping_quotes"
                :key="o.id"
                :value="o.id"
              >{{ o.carrier_label }} · {{ o.service_code }} · {{ rupiah(o.amount) }} · {{ o.estimated_days_from }}–{{ o.estimated_days_to }} hari · {{ o.source === 'MANUAL' ? 'Tarif toko' : 'Tarif kurir' }}</option>
            </select>
            </label>
            <article
              v-for="g in preview.groups"
              :key="g.campaign_slug || g.method"
              class="list-row"
            >
              <div>
                <h3>{{ g.campaign_slug || 'Siap kirim' }}</h3>
                <small>Barang {{ rupiah(g.subtotal_amount) }} · Diskon {{ rupiah(g.discount_amount) }} · Pajak {{ rupiah(g.tax_amount) }} · Ongkir {{ rupiah(g.shipping_amount) }}</small>
              </div>
              <div>
                {{ rupiah(g.total_amount) }}<small>Bayar sekarang {{ rupiah(g.due_now_amount) }}</small>
              </div>
            </article>
            <template v-if="preview.quote_hash">
              <p>Penawaran berlaku sampai {{ preview.expires_at }}</p>
              <label>Catatan pesanan<textarea
                v-model="notes"
                maxlength="1000"
              />
              </label>
              <button
                :disabled="busy"
                @click="checkout"
              >
                Konfirmasi pesanan
              </button>
            </template>
          </template>
        </div>
      </template>
    </StoreResult>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }}
    </p>
    <NuxtLink to="/shop">Lanjut belanja →</NuxtLink>
  </section>
</template>
