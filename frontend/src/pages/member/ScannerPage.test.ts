import { describe, expect, it } from 'vitest'
import { extractToken } from '../../lib/qr'

describe('scanner token parser', () => {
  it('reads dynamic QR JSON payloads', () => {
    expect(extractToken(JSON.stringify({ type: 'attendance', token: 'a'.repeat(43) }))).toBe('a'.repeat(43))
  })

  it('reads token query parameters from signed links', () => {
    expect(extractToken(`https://example.test/scan?token=${'b'.repeat(43)}`)).toBe('b'.repeat(43))
  })

  it('keeps raw token values from supported scanner implementations', () => {
    expect(extractToken(`  ${'c'.repeat(43)}  `)).toBe('c'.repeat(43))
  })
})
