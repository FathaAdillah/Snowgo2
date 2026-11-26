<script setup lang="ts">
const page = ref(1)
const { data, status, refresh } = await useFetch<StoreRead<'/preorders'>>('/api/v1/preorders', { query: { page } })
useStoreSeo(() => 'Drops & Preorder · Snowe GO', () => 'Jadwal dan koleksi preorder Snowe GO.', () => '/drops')
</script>

<template>
  <section class="stack">
    <h1>Drops & Preorder</h1>
    <StoreResult
      :status="status"
      :empty="!data?.data.length"
      empty-label="Belum ada kampanye preorder."
      @retry="refresh()"
    >
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
