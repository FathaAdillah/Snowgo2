<script setup lang="ts">
const props = defineProps<{ sku: string, campaign?: string, min?: number, max?: number, full?: boolean, deposit?: boolean }>()
const qty = ref(props.min ?? 1)
const term = ref(props.full === false ? 'DEPOSIT' : 'FULL')
const { busy, notice, problem, run } = useCustomerAction()
const route = useRoute()
async function add() {
  try {
    const cart = await $fetch<StoreRead<'/cart'>>('/api/v1/cart')
    await customerWrite('/cart/items', 'POST', { cart_version: cart.data.version, qty: qty.value, term: term.value, sku: props.sku, ...(props.campaign ? { campaign_slug: props.campaign } : {}) })
  } catch (err) {
    if ((err as { status?: number }).status === 401) {
      await navigateTo({ path: '/masuk', query: { next: route.fullPath } })
      return
    }
    throw err
  }
}
</script>

<template>
  <form
    class="stack"
    @submit.prevent="run(add, 'Jumlah di keranjang tersimpan.')"
  >
    <div class="filters">
      <label>Jumlah<input
        v-model.number="qty"
        type="number"
        required
        :min="min ?? 1"
        :max="max ?? 10000"
        step="1"
      >
      </label>
      <label v-if="campaign">Pembayaran<select v-model="term">
        <option
          v-if="full !== false"
          value="FULL"
        >Penuh</option>
        <option
          v-if="deposit"
          value="DEPOSIT"
        >DP</option>
      </select>
      </label>
      <button :disabled="busy">
        <SnowIcon name="shopping-cart" /> Masukkan keranjang
      </button>
    </div>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }} <NuxtLink to="/keranjang">Lihat keranjang</NuxtLink>
    </p>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </form>
</template>
