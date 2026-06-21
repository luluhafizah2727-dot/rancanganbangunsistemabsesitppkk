import { useQuery } from '@tanstack/react-query'
import { Search, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { EmptyState, PageHeader } from '../../components/ui'

interface AuditLog { id: string; action: string; actor: string; ip_address: string | null; created_at: string; metadata: Record<string, unknown> }

export function LogsPage() {
  const [search, setSearch] = useState('')
  const logs = useQuery({ queryKey: ['audit', search], queryFn: () => api<AuditLog[]>(`/api/v1/audit-logs?action=${encodeURIComponent(search)}&per_page=100`) })
  return <><PageHeader title="Log" description="Lihat aktivitas dan perubahan penting di aplikasi." /><section className="panel"><div className="panel__body toolbar"><div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari aktivitas" /></div></div>{logs.data?.length ? <div className="audit-list">{logs.data.map((log) => <article key={log.id}><span className="resource-icon"><ShieldCheck size={18} /></span><div><strong>{humanize(log.action)}</strong><p>{log.actor} · {log.ip_address || 'sistem'}</p></div><time>{formatDate(log.created_at)}</time></article>)}</div> : <EmptyState title="Belum ada log" description="Aktivitas penting akan tampil di sini." />}</section></>
}

const actionLabels: Record<string, string> = {
  'auth.login': 'Masuk ke aplikasi',
  'auth.logout': 'Keluar dari aplikasi',
  'auth.password_changed': 'Mengubah password',
  'profile.updated': 'Memperbarui profil',
  'profile.avatar_updated': 'Mengganti foto profil',
  'profile.avatar_removed': 'Menghapus foto profil',
  'member.created': 'Menambahkan anggota',
  'member.updated': 'Memperbarui anggota',
  'member.archived': 'Menghapus anggota dari daftar aktif',
  'member.approved': 'Menyetujui anggota',
  'member.rejected': 'Menolak pendaftaran anggota',
  'member.status_changed': 'Mengubah status anggota',
  'member.password_reset': 'Mereset password anggota',
  'account.created': 'Membuat akun staf',
  'account.updated': 'Memperbarui akun',
  'account.password_reset': 'Mereset password akun',
  'account.status_changed': 'Mengubah status akun',
  'kiosk.created': 'Menambahkan Gawai',
  'kiosk.updated': 'Memperbarui Gawai',
  'kiosk.activation_code_created': 'Membuat kode aktivasi Gawai',
  'kiosk.device_activated': 'Mengaktifkan Gawai',
  'kiosk.device_revoked': 'Mencabut Gawai',
  'device.created': 'Menambahkan Gawai',
  'device.updated': 'Memperbarui Gawai',
  'device.activation_code_created': 'Membuat kode aktivasi Gawai',
  'device.activated': 'Mengaktifkan Gawai',
  'device.revoked': 'Mencabut Gawai',
  'attendance_request.submitted': 'Mengajukan permohonan kehadiran',
  'attendance_request.cancelled': 'Membatalkan permohonan kehadiran',
  'attendance_request.approved': 'Menyetujui permohonan kehadiran',
  'attendance_request.rejected': 'Menolak permohonan kehadiran',
  'member_device.requested': 'Mengajukan perangkat anggota',
  'member_device.audit_recorded': 'Mencatat perangkat anggota',
  'member_device.auto_approved': 'Menyetujui otomatis perangkat anggota',
  'member_device.approved': 'Menyetujui perangkat anggota',
  'member_device.rejected': 'Menolak perangkat anggota',
  'member_device.revoked': 'Mencabut perangkat anggota',
  'security.member_device_binding_updated': 'Mengubah aturan perangkat anggota',
  'attendance_schedule.updated': 'Memperbarui jadwal mingguan',
  'attendance_exception.created': 'Menambahkan pengecualian tanggal',
  'attendance_exception.updated': 'Memperbarui pengecualian tanggal',
  'attendance_exception.deleted': 'Menghapus pengecualian tanggal',
  'attendance.manual_saved': 'Menyimpan catatan kehadiran manual',
  'attendance.manual_updated': 'Memperbarui catatan kehadiran',
  'attendance.manual_reset': 'Menghapus catatan kehadiran',
}

function humanize(value: string) {
  return actionLabels[value] ?? value.replaceAll('.', ' · ').replaceAll('_', ' ')
}
