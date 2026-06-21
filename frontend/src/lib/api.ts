import type { ApiEnvelope, ApiErrorPayload } from '../types'

function resolveApiUrl() {
  const configured = String(import.meta.env.VITE_API_URL || '').trim()

  if (!configured) return ''

  if (configured.startsWith('/')) {
    return configured.replace(/\/$/, '')
  }

  try {
    const url = new URL(configured)

    return url.origin
  } catch {
    return String(configured).replace(/\/$/, '')
  }
}

const API_URL = resolveApiUrl()

export class ApiError extends Error {
  status: number
  payload: ApiErrorPayload

  constructor(status: number, payload: ApiErrorPayload) {
    super(payload.message || 'Permintaan gagal.')
    this.status = status
    this.payload = payload
  }
}

function csrfToken() {
  const value = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')
    .slice(1)
    .join('=')

  return value ? decodeURIComponent(value) : null
}

export async function ensureCsrf() {
  await fetch(`${API_URL}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method || 'GET').toUpperCase()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')

  if (!(init.body instanceof FormData) && init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    let token = csrfToken()
    if (!token) {
      await ensureCsrf()
      token = csrfToken()
    }
    if (token) headers.set('X-XSRF-TOKEN', token)
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...init,
    headers,
    credentials: 'include',
  })

  const payload = await response.json().catch(() => ({ message: 'Respons server tidak dapat dibaca.' }))
  if (!response.ok) throw new ApiError(response.status, payload)

  return (payload as ApiEnvelope<T>).data
}

export function jsonBody(data: unknown): Pick<RequestInit, 'body'> {
  return { body: JSON.stringify(data) }
}

export { API_URL }
