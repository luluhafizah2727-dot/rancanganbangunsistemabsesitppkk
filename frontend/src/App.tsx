import { lazy, Suspense } from 'react'
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { MemberShell } from './components/MemberShell'
import { ProtectedRoute } from './components/ProtectedRoute'
import { LoadingScreen } from './components/ui'

const AttendancePage = lazy(() => import('./pages/admin/AttendancePage').then((module) => ({ default: module.AttendancePage })))
const AccountsPage = lazy(() => import('./pages/admin/AccountsPage').then((module) => ({ default: module.AccountsPage })))
const DashboardPage = lazy(() => import('./pages/admin/DashboardPage').then((module) => ({ default: module.DashboardPage })))
const DevicesPage = lazy(() => import('./pages/admin/DevicesPage').then((module) => ({ default: module.DevicesPage })))
const AttendanceRequestsPage = lazy(() => import('./pages/admin/AttendanceRequestsPage').then((module) => ({ default: module.AttendanceRequestsPage })))
const LogsPage = lazy(() => import('./pages/admin/LogsPage').then((module) => ({ default: module.LogsPage })))
const MembersPage = lazy(() => import('./pages/admin/MembersPage').then((module) => ({ default: module.MembersPage })))
const ReportsPage = lazy(() => import('./pages/admin/ReportsPage').then((module) => ({ default: module.ReportsPage })))
const SettingsPage = lazy(() => import('./pages/admin/SettingsPage').then((module) => ({ default: module.SettingsPage })))
const KioskPage = lazy(() => import('./pages/kiosk/KioskPage').then((module) => ({ default: module.KioskPage })))
const LoginPage = lazy(() => import('./pages/LoginPage').then((module) => ({ default: module.LoginPage })))
const PublicAttendanceRequestPage = lazy(() => import('./pages/PublicAttendanceRequestPage').then((module) => ({ default: module.PublicAttendanceRequestPage })))
const RegisterPage = lazy(() => import('./pages/RegisterPage').then((module) => ({ default: module.RegisterPage })))
const MemberHistoryPage = lazy(() => import('./pages/member/MemberHistoryPage').then((module) => ({ default: module.MemberHistoryPage })))
const MemberHomePage = lazy(() => import('./pages/member/MemberHomePage').then((module) => ({ default: module.MemberHomePage })))
const MemberProfilePage = lazy(() => import('./pages/member/MemberProfilePage').then((module) => ({ default: module.MemberProfilePage })))
const MemberRequestsPage = lazy(() => import('./pages/member/MemberRequestsPage').then((module) => ({ default: module.MemberRequestsPage })))
const ScannerPage = lazy(() => import('./pages/member/ScannerPage').then((module) => ({ default: module.ScannerPage })))

function AdminLayout() {
  return (
    <AppShell>
      <Outlet />
    </AppShell>
  )
}

function MemberLayout() {
  return (
    <MemberShell>
      <Outlet />
    </MemberShell>
  )
}

export default function App() {
  return (
    <Suspense fallback={<LoadingScreen />}><Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/public/attendance-requests/:token" element={<PublicAttendanceRequestPage />} />
      <Route path="/gawai" element={<KioskPage />} />
      <Route path="/kiosk" element={<Navigate to="/gawai" replace />} />

      <Route element={<ProtectedRoute roles={['super_admin', 'operator']} />}>
        <Route path="/admin" element={<AdminLayout />}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<DashboardPage />} />
          <Route path="accounts" element={<AccountsPage />} />
          <Route path="members" element={<MembersPage />} />
          <Route path="gawai" element={<DevicesPage />} />
          <Route path="kiosks" element={<Navigate to="../gawai" replace />} />
          <Route path="requests" element={<AttendanceRequestsPage />} />
          <Route path="attendance" element={<AttendancePage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="logs" element={<LogsPage />} />
          <Route path="audit" element={<Navigate to="../logs" replace />} />
          <Route path="settings" element={<SettingsPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute roles={['member']} />}>
        <Route path="/member" element={<MemberLayout />}>
          <Route index element={<Navigate to="home" replace />} />
          <Route path="home" element={<MemberHomePage />} />
          <Route path="scan" element={<ScannerPage />} />
          <Route path="history" element={<MemberHistoryPage />} />
          <Route path="requests" element={<MemberRequestsPage />} />
          <Route path="profile" element={<MemberProfilePage />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes></Suspense>
  )
}
