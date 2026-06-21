import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('public device route', () => {
  it('shows device activation without redirecting to login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: { registered: false, server_time: '2026-06-20T08:00:00+08:00' },
      meta: {},
      links: {},
    }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/gawai']}>
          <App />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Aktivasi Gawai' })).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Masuk' })).not.toBeInTheDocument()
  })
})
