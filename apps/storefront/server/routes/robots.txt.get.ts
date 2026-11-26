export default defineEventHandler((event) => {
  setResponseHeader(event, 'content-type', 'text/plain')
  return 'User-agent: *\nAllow: /\nDisallow: /akun\nDisallow: /keranjang\nDisallow: /pesanan\nDisallow: /auth\nDisallow: /api/\nSitemap: ' + new URL('/sitemap.xml', useRuntimeConfig(event).public.siteUrl).href
})
