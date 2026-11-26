<script setup lang="ts">
defineProps<{ filtered?: boolean }>()
</script>

<template>
  <div
    class="store-empty"
    role="status"
  >
    <span
      class="empty-icon"
      aria-hidden="true"
    >
      <svg
        viewBox="0 0 24 24"
        width="30"
        height="30"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linejoin="round"
      >
        <path d="m2 6 6-3 4 3-6 3-4-3Zm10 0 4-3 6 3-4 3-6-3Zm-6 3 6 3-4 3-6-3 4-3Zm12 0 4 3-6 3-4-3 6-3ZM4 14v4l8 4 8-4v-4m-8-2v10" />
      </svg>
    </span>
    <h2>{{ filtered ? 'Produk tidak ditemukan' : 'Produk belum tersedia' }}</h2>
    <p>{{ filtered ? 'Coba kata kunci atau kategori lain.' : 'Produk aktif akan muncul di halaman ini.' }}</p>
    <NuxtLink
      v-if="filtered"
      to="/shop"
    >Lihat semua produk</NuxtLink>
  </div>
</template>

<style scoped>
.store-empty{min-height:312px;border:1px dashed #e2cbd4;border-radius:20px;background:linear-gradient(110deg,#fff,#fcf8fa);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px 18px 44px;gap:0;color:#103950}
.empty-icon{display:grid;place-items:center;width:65px;height:65px;border-radius:50%;background:#f8e6ef;color:#8e245c;margin-bottom:17px}
.store-empty h2{font-size:24px;font-weight:400;line-height:1.4;margin:0}
.store-empty p{font-size:14px;color:#65738a;margin:9px 0 0}
</style>
