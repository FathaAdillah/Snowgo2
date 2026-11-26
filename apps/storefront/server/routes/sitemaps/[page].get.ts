export default defineEventHandler(async (event) => {
  const match = /^([1-9]\d{0,7})\.xml$/.exec(getRouterParam(event, 'page') ?? '')
  if (!match) throw createError({ statusCode: 404 })
  const page = Number(match[1])
  const { data, meta } = await sitemapProducts(event, page)
  if (page > meta.last_page) throw createError({ statusCode: 404 })
  return sitemapXml(event, [...(page === 1 ? ['/', '/shop'] : []), ...data.map(slug => '/produk/' + encodeURIComponent(slug))])
})
