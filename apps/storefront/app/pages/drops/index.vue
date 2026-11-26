<script setup lang="ts">
const page = ref(1)
const { data, status, refresh } = await useFetch<StoreRead<'/preorders'>>('/api/v1/preorders', { query: { page } })
useStoreSeo(() => 'Drops & Preorder · Snowe GO', () => 'Jadwal dan koleksi preorder Snowe GO.', () => '/drops')
</script>

<template>
  <section class="stack">
    <header class="drops-header">
      <h1>Drop tersedia</h1>
      <p>Lihat jadwal dan ketentuan tiap preorder.</p>
    </header>
    <StoreResult
      :status="status"
      :empty="!data?.data.length"
      @retry="refresh()"
    >
      <template #empty>
        <div
          class="drop-empty"
          role="status"
        >
          <img
            src="/brand/drop-cat.webp"
            alt=""
            width="920"
            height="350"
          >
          <h2>Belum ada drop</h2>
          <p>Drop aktif akan muncul di halaman ini.</p>
        </div>
      </template>
      <NuxtLink
        v-for="c in data?.data"
        :key="c.slug"
        class="list-row"
        :to="'/drops/' + c.slug"
      >
        <span>
          <h2>{{ c.name }}</h2>
          <small>{{ c.starts_at.slice(0, 10) }} – {{ c.ends_at.slice(0, 10) }}</small>
        </span>
        <span class="badge">{{ c.status }}</span>
      </NuxtLink>
    </StoreResult>
    <div class="actions">
      <button
        v-if="page > 1"
        @click="page--"
      >
        Sebelumnya
      </button>
      <button
        v-if="data && page < data.meta.last_page"
        @click="page++"
      >
        Berikutnya
      </button>
    </div>
  </section>
</template>

<style scoped>
.drops-header h1 {
  font-size: 26px;
  font-weight: 400;
}

.drop-empty {
  display: grid;
  justify-items: center;
  text-align: center;
  margin: clamp(6px, 1.4vw, 18px);
  padding: clamp(26px, 5vw, 70px) 16px clamp(24px, 4vw, 54px);
  border: 2px dashed #aed5e7;
  border-radius: clamp(20px, 3vw, 42px);
  background: linear-gradient(#f6fcfe, #f2fafe);
  box-shadow: 0 0 0 clamp(6px, 1.4vw, 18px) #f3fafb;
}

.drop-empty img {
  width: min(90%, 52rem);
  height: auto;
  mask-image: linear-gradient(to bottom, transparent, black 3%, black 97%, transparent),
    linear-gradient(to right, transparent, black 3%, black 97%, transparent);
  mask-composite: intersect;
}

.drop-empty h2 {
  margin: .6rem 0 .25rem;
  font-size: clamp(1.5rem, 4vw, 3rem);
  font-weight: 650;
  color: #214e7c;
}

.drop-empty p {
  color: var(--muted);
  font-size: clamp(1rem, 2.4vw, 1.75rem);
}

@media (min-width: 640px) {
  .drop-empty img {
    width: 60%;
  }
}
</style>
