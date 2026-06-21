import { useQuery } from '@tanstack/react-query'
import { CalendarDays, Clock3, QrCode, ShieldCheck } from 'lucide-react'
import { Link } from 'react-router-dom'
import { StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import type { Attendance, MemberToday } from '../../types'

export function MemberHomePage() {
  const { user } = useAuth()
  const today = useQuery({ queryKey: ['member-today'], queryFn: () => api<MemberToday>('/api/v1/attendance/today'), refetchInterval: 15_000 })
  const history = useQuery({ queryKey: ['member-history', 'home'], queryFn: () => api<Attendance[]>('/api/v1/attendance/history?per_page=3') })
  const data = today.data
  const attendance = data?.attendance

  return (
    <div className="member-page">
      <header className="member-hero"><h1>Selamat datang, <span>{user?.name.split(' ')[0] ?? 'Anggota'}</span></h1><p>Lihat jadwal dan status absensi hari ini.</p></header>
      <section className="member-card next-event">
        <div className="member-card__header"><span><CalendarDays size={20} /> Jadwal Hari Ini</span>{data ? <StatusBadge tone={data.current_phase ? 'success' : data.day.is_working_day ? 'neutral' : 'warning'}>{data.current_phase === 'check_in' ? 'Waktu masuk' : data.current_phase === 'check_out' ? 'Waktu pulang' : data.day.is_working_day ? 'Terjadwal' : 'Libur'}</StatusBadge> : null}</div>
        {data?.day.is_working_day ? <><h2>{formatDate(`${data.day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</h2><p><Clock3 size={18} /> Masuk {time(data.day.check_in_target_at)} · Pulang {time(data.day.check_out_target_at)}</p><Link className="button button--primary member-scan-link" to="/member/scan"><QrCode size={22} /> <span>Pindai QR di layar absensi</span></Link></> : <div className="member-empty-mini"><strong>Hari ini tidak ada jadwal absensi</strong><p>Anda tidak perlu melakukan pemindaian.</p></div>}
      </section>
      <section className="member-card status-card"><span className={attendance?.status === 'present' ? 'status-icon status-icon--success' : 'status-icon status-icon--warning'}>{attendance?.status === 'present' ? <ShieldCheck size={42} /> : <Clock3 size={42} />}</span><div><h2>Status Hari Ini</h2><strong>{statusLabel(attendance)}</strong><p>{attendance?.check_in_at ? `Check-in ${formatDate(attendance.check_in_at, 'HH.mm')} WITA${attendance.check_in_status === 'late' ? ' · Terlambat' : ' · Tepat waktu'}` : data?.day.is_working_day ? 'Pindai QR ketika waktu absensi aktif.' : 'Hari libur.'}</p></div></section>
      {history.data?.length ? <section className="member-card recent-member-history"><div className="member-card__header"><span>Riwayat terbaru</span><Link to="/member/history">Lihat semua</Link></div>{history.data.slice(0, 2).map((item) => <div key={item.id}><span><strong>{formatDate(`${item.day.attendance_date}T00:00:00+08:00`, 'dd MMM yyyy')}</strong><small>{statusLabel(item)}</small></span><StatusBadge tone={item.status === 'present' ? 'success' : item.status === 'absent' ? 'danger' : 'info'}>{statusLabel(item)}</StatusBadge></div>)}</section> : null}
      <section className="member-note"><ShieldCheck size={22} /><p>Gunakan akun sendiri saat melakukan absensi.</p></section>
    </div>
  )
}

function time(value: string | null) { return value ? `${formatDate(value, 'HH.mm')} WITA` : '-' }
function statusLabel(value: Attendance | null | undefined) { if (!value || value.status === 'pending') return 'Belum hadir'; return { present: value.check_out_at ? 'Sudah check-out' : 'Sudah check-in', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }[value.status] }
