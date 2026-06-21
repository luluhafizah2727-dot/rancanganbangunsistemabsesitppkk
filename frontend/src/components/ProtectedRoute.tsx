import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import type { Role } from '../types'
import { LoadingScreen } from './ui'

export function ProtectedRoute({ roles }: { roles: Role[] }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <LoadingScreen />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (!roles.some((role) => user.roles.includes(role))) {
    return <Navigate to={user.roles.includes('member') ? '/member/home' : '/admin/dashboard'} replace />
  }

  return <Outlet />
}
