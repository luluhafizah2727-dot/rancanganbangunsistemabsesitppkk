import { useQuery } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, Clock3 } from 'lucide-react'
import { EmptyState, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { Attendance, AttendanceStatus } from '../../types'

export function MemberHistoryPage() {
  const history = useQuery({ queryKey: ['member-history'], queryFn: () => api<Attendance[]>('/api/v1/attendance/history?per_page=50') })
  return <div className="member-page"><header className="member-section-title"><h1>Riwayat</h1><p>Daftar absensi harian Anda.</p></header><section className="history-list">{history.data?.length ? history.data.map((attendance) => <article className="history-card" key={attendance.id}><span className={`history-card__icon history-card__icon--${attendance.status}`}><CheckCircle2 size={23} /></span><div><h2>{formatDate(`${attendance.day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</h2><p><CalendarDays size={15} /> Absensi harian</p><div className="history-times"><StatusBadge tone={statusTone(attendance.status)}>{statusLabel(attendance.status)}</StatusBadge>{attendance.check_in_at ? <StatusBadge tone={attendance.check_in_status === 'late' ? 'warning' : 'success'}>Masuk {formatDate(attendance.check_in_at, 'HH.mm')} · {attendance.check_in_status === 'late' ? 'Terlambat' : 'Tepat waktu'}</StatusBadge> : null}{attendance.check_out_at ? <StatusBadge tone={attendance.check_out_status === 'early' ? 'warning' : 'info'}>{attendance.presence_summary.is_partial_absence ? 'Mulai izin/sakit/dinas' : 'Pulang'} {formatDate(attendance.check_out_at, 'HH.mm')} · {attendance.check_out_status === 'early' ? 'Pulang awal' : 'Selesai'}</StatusBadge> : attendance.status === 'present' ? <StatusBadge tone="neutral"><Clock3 size={12} /> Pulang belum tercatat</StatusBadge> : null}</div>{attendance.presence_summary.label ? <p className="history-note">{attendance.presence_summary.label}</p> : null}{attendance.note ? <p className="history-note">{attendance.note}</p> : null}</div></article>) : <EmptyState title="Belum ada riwayat" description="Riwayat absensi harian akan tampil di sini." />}</section></div>
}

function statusLabel(status: AttendanceStatus) { return { pending: 'Belum hadir', present: 'Hadir', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }[status] }
function statusTone(status: AttendanceStatus): 'neutral' | 'success' | 'info' | 'warning' | 'danger' {
  const tones: Record<AttendanceStatus, 'neutral' | 'success' | 'info' | 'warning' | 'danger'> = { pending: 'neutral', present: 'success', permission: 'info', leave: 'info', sick: 'warning', official_duty: 'info', absent: 'danger' }
  return tones[status]
}
