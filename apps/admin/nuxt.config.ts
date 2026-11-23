// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui'
  ],

  // Staff panel: client-rendered SPA; Nitro only serves assets and the API proxy.
  ssr: false,

  $development: {
    vite: { optimizeDeps: { include: ['reka-ui', 'reka-ui/date'] } },
    hooks: {
      'vite:extendConfig'(config, { isClient }) {
        if (!isClient) return
        // Nuxt UI transpiles Reka; Nuxt consequently excludes it from Vite's prebundle.
        // Use the compiled ESM locally instead of serving hundreds of primitive modules.
        const deps = config.environments?.client?.optimizeDeps ?? config.optimizeDeps
        if (deps?.exclude) deps.exclude = deps.exclude.filter(id => id !== 'reka-ui')
      }
    }
  },

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
  },

  // Bundle icons at build time: /api/* is proxied to Laravel, so the runtime icon endpoint is unreachable.
  icon: {
    provider: 'none',
    fallbackToApi: false,
    clientBundle: { scan: { globInclude: ['app/**/*.{vue,ts}'] } }
  }
})
