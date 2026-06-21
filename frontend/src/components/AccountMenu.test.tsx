import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
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
  member: null,
}

describe('account menu', () => {
  it('shows account actions and confirms logout', async () => {
    const onLogout = vi.fn().mockResolvedValue(undefined)
    const pointer = userEvent.setup()
    render(
      <MemoryRouter>
        <AccountMenu user={user} accountPath="/admin/settings" onLogout={onLogout} />
      </MemoryRouter>,
    )

    await pointer.click(screen.getByRole('button', { name: /Super Admin/i }))
    expect(screen.getByRole('menuitem', { name: 'Akun Saya' })).toBeVisible()
    await pointer.click(screen.getByRole('menuitem', { name: 'Keluar' }))
    expect(screen.getByRole('dialog', { name: 'Keluar dari akun?' })).toBeVisible()

    await pointer.click(screen.getByRole('button', { name: 'Ya, keluar' }))
    expect(onLogout).toHaveBeenCalledOnce()
  })
})
