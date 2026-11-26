import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: ['@nuxt/eslint'],

  // Public pages are server-rendered for SEO; account pages opt out per route later.
  ssr: true,

  devtools: { enabled: true },

  app: {
    head: {
      htmlAttrs: { lang: 'id' },
      title: 'Snowe GO',
      meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1' }]
    }
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // Private Laravel upstream (Nginx/PHP-FPM); override with NUXT_API_INTERNAL_ORIGIN.
    apiInternalOrigin: 'http://127.0.0.1:8000',
    public: { siteUrl: 'http://localhost' }
  },

  routeRules: {
    ...Object.fromEntries(['/akun', '/akun/**', '/favorit', '/keranjang', '/pesanan', '/pesanan/**', '/invoice/**', '/pembayaran/**', '/pengiriman/**', '/purnajual', '/purnajual/**', '/request-order', '/request-order/**', '/masuk', '/verify-email', '/reset-password', '/auth/**'].map(path => [path, { ssr: false, headers: { 'cache-control': 'private, no-store', 'x-robots-tag': 'noindex', 'referrer-policy': 'no-referrer' } }])),
    '/**': { headers: { 'x-frame-options': 'DENY', 'x-content-type-options': 'nosniff', 'referrer-policy': 'strict-origin-when-cross-origin' } }
  },

  compatibilityDate: '2026-06-30',

  vite: {
    plugins: [tailwindcss()]
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
