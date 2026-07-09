import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../types'
import { AccountMenu } from './AccountMenu'

const user: User = {
  id: '01TEST',
  login_id: 'admin',
  name: 'Super Admin',
  email: null,
  phone: null,
  avatar_url: null,
  status: 'active',
  roles: ['super_admin'],
  must_change_password: false,
  receive_wa_notifications: true,
  can_review_attendance_requests: true,
  member: null,
}

describe('account menu', () => {
  it('shows account actions and confirms logout', async () => {
    const onLogout = vi.fn().mockResolvedValue(undefined)
    const pointer = userEvent.setup()
    render(
      <MemoryRouter>
        <AccountMenu user={user} accountPath="/admin/accounts?tab=me" onLogout={onLogout} />
      </MemoryRouter>,
    )

    await pointer.click(screen.getByRole('button', { name: /Super Admin/i }))
    expect(screen.getByRole('menuitem', { name: 'Akun Saya' })).toBeVisible()
    await pointer.click(screen.getByRole('menuitem', { name: 'Keluar' }))
    expect(screen.getByRole('dialog', { name: 'Keluar dari akun?' })).toBeVisible()

    await pointer.click(screen.getByRole('button', { name: 'Ya, keluar' }))
    expect(onLogout).toHaveBeenCalledOnce()
  })

  it('navigates account action to the account page me tab', async () => {
    const pointer = userEvent.setup()
    const view = render(
      <MemoryRouter initialEntries={['/admin/dashboard']}>
        <AccountMenu user={user} accountPath="/admin/accounts?tab=me" onLogout={vi.fn()} />
        <LocationProbe />
      </MemoryRouter>,
    )
    const current = within(view.container)

    await pointer.click(current.getByRole('button', { name: /Super Admin/i }))
    await pointer.click(current.getByRole('menuitem', { name: 'Akun Saya' }))

    expect(current.getByTestId('location')).toHaveTextContent('/admin/accounts?tab=me')
  })
})

function LocationProbe() {
  const location = useLocation()

  return <span data-testid="location">{location.pathname}{location.search}</span>
}
