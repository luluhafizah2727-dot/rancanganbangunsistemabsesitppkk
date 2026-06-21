import { Clock3, FileCheck2, Home, ScanLine, UserRound } from 'lucide-react'
import type { ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAuth } from '../lib/auth'
import { AccountMenu } from './AccountMenu'
import { BrandMark } from './BrandMark'

const tabs = [
  { to: '/member/home', label: 'Beranda', icon: Home },
  { to: '/member/scan', label: 'Pindai', icon: ScanLine },
  { to: '/member/history', label: 'Riwayat', icon: Clock3 },
  { to: '/member/requests', label: 'Permohonan', icon: FileCheck2 },
  { to: '/member/profile', label: 'Profil', icon: UserRound },
]

export function MemberShell({ children }: { children: ReactNode }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    try {
      await logout()
      navigate('/login')
    } catch {
      toast.error('Tidak dapat keluar. Coba lagi.')
    }
  }

  return (
    <div className="member-app">
      <header className="member-topbar">
        <BrandMark />
        {user ? <AccountMenu user={user} accountPath="/member/profile" onLogout={handleLogout} compact /> : null}
      </header>
      <main className="member-content">{children}</main>
      <nav className="member-nav" aria-label="Navigasi anggota">
        {tabs.map(({ to, label, icon: Icon }) => (
          <NavLink key={to} to={to} className={({ isActive }) => `member-nav__link ${isActive ? 'member-nav__link--active' : ''}`}>
            <Icon size={22} />
            <span>{label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
