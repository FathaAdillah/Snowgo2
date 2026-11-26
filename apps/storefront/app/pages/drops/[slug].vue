<script setup lang="ts">
const route = useRoute()
const { data, status, refresh, error } = await useFetch<StoreRead<'/preorders/{slug}'>>(() => '/api/v1/preorders/' + encodeURIComponent(String(route.params.slug)))
if (error.value?.statusCode === 404) throw createError({ statusCode: 404, statusMessage: 'Kampanye tidak tersedia' })
const campaign = computed(() => data.value?.data)
</script>

<template>
  <section class="stack">
    <NuxtLink to="/drops">← Drops</NuxtLink>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="campaign">
        <h1>{{ campaign.name }}</h1>
        <div class="panel stack">
          <p>{{ campaign.origin }} · {{ campaign.transport_mode }} · {{ campaign.status }}</p>
          <p>Periode {{ campaign.starts_at }} – {{ campaign.ends_at }}</p>
          <p>Estimasi siap {{ campaign.ready_eta_from }} – {{ campaign.ready_eta_to }}. Estimasi kirim {{ campaign.ship_eta_from }} – {{ campaign.ship_eta_to }}.</p>
          <p class="prose-copy">
            {{ campaign.terms }}
          </p>
        </div>
        <article
          v-for="v in campaign.variants"
          :key="v.sku"
          class="panel stack"
        >
          <h2>{{ v.sku }}</h2>
          <p>
            {{ rupiah(v.unit_amount) }}<span v-if="v.deposit_unit_amount != null"> · DP {{ rupiah(v.deposit_unit_amount) }}</span>
          </p>
          <p>{{ v.available_qty }} unit tersedia</p>
          <AddToCart
            v-if="campaign.status === 'OPEN' && v.available_qty >= v.min_qty"
            :sku="v.sku"
            :campaign="campaign.slug"
            :min="v.min_qty"
            :max="Math.min(v.max_qty, v.available_qty)"
            :full="campaign.payment_scheme !== 'DEPOSIT_ONLY'"
            :deposit="v.deposit_unit_amount != null && campaign.payment_scheme !== 'FULL_ONLY'"
          />
        </article>
      </template>
    </StoreResult>
  </section>
</template>
