export default defineEventHandler((event) => {
  setResponseHeader(event, 'content-type', 'text/plain')
  const privatePaths = ['/akun', '/keranjang', '/favorit', '/pesanan', '/invoice/', '/pembayaran/', '/pengiriman/', '/purnajual', '/request-order', '/masuk', '/verify-email', '/reset-password', '/auth/', '/api/']
  return ['User-agent: *', 'Allow: /', ...privatePaths.map(path => 'Disallow: ' + path), 'Sitemap: ' + new URL('/sitemap.xml', useRuntimeConfig(event).public.siteUrl).href].join('\n')
})
