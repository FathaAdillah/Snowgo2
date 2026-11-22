// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui'
  ],

  // Staff panel: client-rendered SPA; Nitro only serves assets and the API proxy.
  ssr: false,

  devtools: {
    enabled: true
  },

  app: {
    head: {
      htmlAttrs: { lang: 'id' },
      title: 'Snowego Admin',
      meta: [{ name: 'robots', content: 'noindex, nofollow' }]
    }
  },

  css: ['~/assets/css/main.css'],

  ui: {
    eslint: {
      rules: {
        'no-unknown-classes': 'error',
        'no-conflicting-classes': 'error',
        'no-palette-colors': 'error'
      }
    }
  },

  runtimeConfig: {
    // Private Laravel upstream (Nginx/PHP-FPM); override with NUXT_API_INTERNAL_ORIGIN.
    apiInternalOrigin: 'http://127.0.0.1:8000'
  },

  routeRules: {
    '/**': { headers: { 'x-frame-options': 'DENY', 'x-content-type-options': 'nosniff', 'referrer-policy': 'strict-origin-when-cross-origin' } }
  },

  devServer: {
    port: 3001
  },

  compatibilityDate: '2026-06-30',

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
