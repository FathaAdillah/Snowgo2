import { getRequestHost, getRequestProtocol, getRequestURL, proxyRequest } from 'h3'

// Same-origin proxy for Laravel: browser cookies (session, XSRF) stay host-only on this origin.
const PROXIED_PREFIXES = ['/api/', '/sanctum/']

export default defineEventHandler((event) => {
  const { pathname, search } = getRequestURL(event)
  if (!PROXIED_PREFIXES.some(prefix => pathname.startsWith(prefix))) {
    return
  }

  // Internal probes are only reachable on the private network.
  if (pathname.startsWith('/api/v1/internal/')) {
    throw createError({ statusCode: 404 })
  }

  const { apiInternalOrigin } = useRuntimeConfig(event)

  return proxyRequest(event, `${apiInternalOrigin}${pathname}${search}`, {
    fetchOptions: { redirect: 'manual' },
    headers: {
      'x-forwarded-host': getRequestHost(event),
      'x-forwarded-proto': getRequestProtocol(event)
    }
  })
})
