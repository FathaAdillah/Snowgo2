<script setup lang="ts">
definePageMeta({ middleware: 'customer' })

const unread = ref(false)
const cursor = ref<string>()
const { data, status, error, refresh } = await useFetch<StoreRead<'/notifications'>>('/api/v1/notifications', {
  server: false,
  query: computed(() => ({ unread: unread.value ? '1' : undefined, cursor: cursor.value }))
})
const { busy, problem, notice, run } = useCustomerAction()
const items = computed(() => data.value?.data.filter(item => !unread.value || !item.read_at) ?? [])
const date = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' })

function toggleUnread() {
  cursor.value = undefined
  unread.value = !unread.value
}

async function markRead(id: string) {
  await run(async () => {
    await customerWrite('/notifications/' + id + '/read-receipt', 'PUT')
    await refresh()
  }, 'Notifikasi ditandai sudah dibaca.')
}
</script>

<template>
  <section class="stack">
    <h1>Notifikasi</h1>
    <CustomerLinks />
    <div class="filters">
      <button
        class="subtle"
        :aria-pressed="unread"
        :disabled="busy"
        @click="toggleUnread"
      >
        Hanya yang belum dibaca
      </button>
      <button
        v-if="cursor"
        class="subtle"
        :disabled="busy"
        @click="cursor = undefined"
      >
        Halaman pertama
      </button>
    </div>
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
    <p
      v-if="status === 'pending' || status === 'idle'"
      role="status"
    >
      Memuat notifikasi…
    </p>
    <p
      v-else-if="error"
      role="alert"
    >
      Notifikasi gagal dimuat. <button @click="refresh()">
        Coba lagi
      </button>
    </p>
    <p v-else-if="items.length === 0">
      {{ unread ? 'Tidak ada notifikasi belum dibaca di halaman ini.' : 'Tidak ada notifikasi.' }}
    </p>
    <ul
      v-else
      class="stack"
    >
      <li
        v-for="item in items"
        :key="item.id"
        class="panel stack"
      >
        <h2>{{ item.title }}</h2>
        <p>
          <time :datetime="item.created_at">{{ date.format(new Date(item.created_at)) }} WIB</time>
          · {{ item.read_at ? 'Sudah dibaca' : 'Belum dibaca' }}
        </p>
        <p>{{ item.body }}</p>
        <div class="filters">
          <NuxtLink :to="item.link">Buka detail</NuxtLink>
          <button
            v-if="!item.read_at"
            class="subtle"
            :disabled="busy"
            @click="markRead(item.id)"
          >
            Tandai sudah dibaca
          </button>
        </div>
      </li>
    </ul>
    <div v-if="data?.meta.next_cursor && status === 'success'">
      <button
        class="subtle"
        :disabled="busy"
        @click="cursor = data.meta.next_cursor ?? undefined"
      >
        Halaman berikutnya
      </button>
    </div>
  </section>
</template>
