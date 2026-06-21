import {
  CalendarDays,
  ClipboardCheck,
  FileBarChart,
  FileCheck2,
  LayoutDashboard,
  Logs,
  Menu,
  Monitor,
  Settings,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAuth } from '../lib/auth'
import { formatDate } from '../lib/format'
import { AccountMenu } from './AccountMenu'
import { BrandMark } from './BrandMark'

const navItems = [
  { to: '/admin/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/admin/accounts', label: 'Akun', icon: ShieldCheck, adminOnly: true },
  { to: '/admin/members', label: 'Anggota', icon: Users, adminOnly: true },
  { to: '/admin/gawai', label: 'Gawai', icon: Monitor },
  { to: '/admin/requests', label: 'Permohonan', icon: FileCheck2 },
  { to: '/admin/attendance', label: 'Kehadiran', icon: ClipboardCheck },
  { to: '/admin/reports', label: 'Laporan', icon: FileBarChart },
  { to: '/admin/logs', label: 'Log', icon: Logs, adminOnly: true },
  { to: '/admin/settings', label: 'Pengaturan', icon: Settings, adminOnly: true },
]

export function AppShell({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false)
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const isAdmin = user?.roles.includes('super_admin')

  const handleLogout = async () => {
    try {
      await logout()
      navigate('/login')
    } catch {
      toast.error('Tidak dapat keluar. Coba lagi.')
    }
  }

  return (
    <div className="admin-layout">
      <aside className={`sidebar ${open ? 'sidebar--open' : ''}`}>
        <div className="sidebar__brand">
          <BrandMark inverse />
          <button className="icon-button sidebar__close" onClick={() => setOpen(false)} aria-label="Tutup menu">
            <X size={20} />
          </button>
        </div>
        <nav className="sidebar__nav" aria-label="Navigasi utama">
          {navItems.filter((item) => !item.adminOnly || isAdmin).map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to} onClick={() => setOpen(false)} className={({ isActive }) => `nav-link ${isActive ? 'nav-link--active' : ''}`}>
              <Icon size={20} />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>
      </aside>
      {open ? <button className="sidebar-scrim" aria-label="Tutup menu" onClick={() => setOpen(false)} /> : null}
      <div className="admin-main">
        <header className="topbar">
          <button className="icon-button topbar__menu" onClick={() => setOpen(true)} aria-label="Buka menu"><Menu size={21} /></button>
          <div className="topbar__date"><CalendarDays size={18} /><span>{formatDate(new Date().toISOString(), 'EEEE, dd MMMM yyyy')}</span></div>
          {user ? <AccountMenu user={user} accountPath="/admin/settings?tab=account" onLogout={handleLogout} /> : null}
        </header>
        {user?.must_change_password ? <div className="password-banner">Password sementara masih digunakan. <Link to="/admin/settings?tab=account">Perbarui di Akun Saya.</Link></div> : null}
        <main className="admin-content">{children}</main>
      </div>
    </div>
  )
}
