import { ChevronDown, LogOut, UserRound } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { User } from '../types'
import { Avatar } from './Avatar'
import { ConfirmDialog } from './ui'

interface AccountMenuProps {
  user: User
  accountPath: string
  onLogout: () => Promise<void>
  compact?: boolean
}

export function AccountMenu({ user, accountPath, onLogout, compact = false }: AccountMenuProps) {
  const [open, setOpen] = useState(false)
  const [confirmLogout, setConfirmLogout] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const wrapperRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (!wrapperRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const closeWithEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', closeWithEscape)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', closeWithEscape)
    }
  }, [])

  const logout = async () => {
    setLoggingOut(true)
    try {
      await onLogout()
      setConfirmLogout(false)
    } finally {
      setLoggingOut(false)
    }
  }

  return (
    <>
      <div className={`account-menu ${compact ? 'account-menu--compact' : ''}`} ref={wrapperRef}>
        <button
          type="button"
          className="account-menu__trigger"
          aria-expanded={open}
          aria-haspopup="menu"
          onClick={() => setOpen((value) => !value)}
        >
          <Avatar name={user.name} src={user.avatar_url} size="small" />
          {!compact ? <span><strong>{user.name}</strong></span> : null}
          <ChevronDown size={16} />
        </button>
        {open ? (
          <div className="account-menu__dropdown" role="menu">
            <div className="account-menu__identity"><strong>{user.name}</strong><small>{roleLabel(user)}</small></div>
            <button type="button" role="menuitem" onClick={() => { setOpen(false); navigate(accountPath) }}>
              <UserRound size={18} />
              <span>Akun Saya</span>
            </button>
            <button type="button" role="menuitem" className="account-menu__danger" onClick={() => { setOpen(false); setConfirmLogout(true) }}>
              <LogOut size={18} />
              <span>Keluar</span>
            </button>
          </div>
        ) : null}
      </div>
      {confirmLogout ? (
        <ConfirmDialog
          title="Keluar dari akun?"
          description="Anda perlu masuk kembali untuk menggunakan aplikasi."
          confirmLabel={loggingOut ? 'Keluar...' : 'Ya, keluar'}
          confirmVariant="danger"
          disabled={loggingOut}
          onCancel={() => setConfirmLogout(false)}
          onConfirm={logout}
        />
      ) : null}
    </>
  )
}

function roleLabel(user: User) {
  if (user.roles.includes('super_admin')) return 'Super Admin'
  if (user.roles.includes('operator')) return 'Operator'
  return 'Anggota'
}
