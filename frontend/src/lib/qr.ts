export function extractToken(rawValue: string) {
  const value = rawValue.trim()

  try {
    const parsed = JSON.parse(value) as { token?: unknown }
    if (typeof parsed.token === 'string') return parsed.token
  } catch {
    // QR lama atau manual token bukan JSON.
  }

  try {
    const url = new URL(value)
    return url.searchParams.get('token') ?? value
  } catch {
    return value
  }
}
