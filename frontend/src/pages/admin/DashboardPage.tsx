import { useQuery } from '@tanstack/react-query'
import { BriefcaseBusiness, CalendarDays, CheckCircle2, Clock3, FileCheck2, HeartPulse, Monitor, Radio, ShieldAlert, Umbrella, UserCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Button, EmptyState, LoadingScreen, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { AttendanceRequestType, DashboardData } from '../../types'

export function DashboardPage() {
  const query = useQuery({ queryKey: ['dashboard'], queryFn: () => api<DashboardData>('/api/v1/dashboard'), refetchInterval: 10_000 })
  const [countdown, setCountdown] = useState(10)
  useEffect(() => { const timer = window.setInterval(() => setCountdown(10 - (Math.floor(Date.now() / 1000) % 10)), 250); return () => window.clearInterval(timer) }, [])

  if (query.isLoading) return <LoadingScreen />
  const data = query.data
  if (!data) return <EmptyState title="Dashboard tidak tersedia" description="Periksa koneksi API lalu muat ulang halaman." />

  const day = data.attendance_day
  const metrics = [
    { label: 'Hadir', value: data.metrics.present, icon: UserCheck, tone: 'green' },
    { label: 'Izin', value: data.metrics.permission, icon: CheckCircle2, tone: 'blue' },
    { label: 'Cuti', value: data.metrics.leave, icon: Umbrella, tone: 'blue' },
    { label: 'Sakit', value: data.metrics.sick, icon: HeartPulse, tone: 'amber' },
    { label: 'Dinas', value: data.metrics.official_duty, icon: BriefcaseBusiness, tone: 'blue' },
    { label: 'Alpa', value: data.metrics.absent, icon: ShieldAlert, tone: 'amber' },
    { label: 'Belum hadir', value: data.metrics.pending, icon: Clock3, tone: 'neutral' },
  ]

  return <>
    <PageHeader title="Dashboard" description={`Kehadiran hari ini · ${data.metrics.total_members} anggota aktif`} />
    <section className="active-session panel">
      <div className="active-session__main">
        <div className="active-session__heading"><CalendarDays size={27} /><div><h2>{formatDate(`${day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</h2><p>{day.note || (day.is_working_day ? 'Jadwal reguler' : 'Hari libur')}</p></div></div>
        {day.is_working_day ? <div className="schedule-summary"><ScheduleFact label="Jam masuk" target={day.check_in_target_at} opens={day.check_in_opens_at} closes={day.check_in_closes_at} /><ScheduleFact label="Jam pulang" target={day.check_out_target_at} opens={day.check_out_opens_at} closes={day.check_out_closes_at} /><span><Radio size={18} /><small>Status saat ini</small><strong className={data.current_phase ? 'text-success' : ''}>{phaseLabel(data.current_phase)}</strong></span></div> : data.next_working_day ? <div className="next-schedule"><Clock3 size={18} /><span><small>Jadwal berikutnya</small><strong>{formatDate(`${data.next_working_day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM')} · {time(data.next_working_day.check_in_target_at)}</strong></span></div> : null}
      </div>
      {data.current_phase ? <div className="qr-countdown"><span>{String(countdown).padStart(2, '0')}</span><small>detik</small><em>QR diperbarui otomatis</em></div> : null}
      <div className="active-session__actions"><Button icon={<Monitor size={18} />} onClick={() => window.open('/gawai', '_blank')}>Buka layar Gawai</Button><Link className="button button--secondary" to="/admin/attendance">Kelola kehadiran</Link></div>
    </section>

    <section className="metrics-strip metrics-strip--seven">{metrics.map(({ label, value, icon: Icon, tone }) => <div className={`metric metric--${tone}`} key={label}><Icon size={23} /><span><small>{label}</small><strong>{value}</strong></span></div>)}</section>

    <section className="dashboard-grid"><div className="panel attendance-live"><header className="panel__header"><h2>Kehadiran terbaru</h2><Link to="/admin/attendance">Lihat semua</Link></header>{data.recent_attendance.length ? <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Waktu</th><th>Nama anggota</th><th>Jabatan</th><th>Ketepatan</th></tr></thead><tbody>{data.recent_attendance.map((attendance) => <tr key={attendance.id}><td>{formatDate(attendance.check_in_at, 'HH.mm.ss')}</td><td><span className="table-primary">{attendance.member.user?.name}</span><span className="table-secondary">{attendance.member.member_number}</span></td><td>{attendance.member.position || 'Anggota'}</td><td><StatusBadge tone={attendance.check_in_status === 'late' ? 'warning' : 'success'}>{attendance.check_in_status === 'late' ? 'Terlambat' : 'Tepat waktu'}</StatusBadge></td></tr>)}</tbody></table></div> : <EmptyState title="Belum ada kehadiran" description="Data akan muncul setelah anggota melakukan check-in." />}</div>
      <div className="dashboard-rail"><section className="panel"><header className="panel__header"><h2>Permohonan menunggu</h2><Link to="/admin/requests">{data.metrics.pending_requests} total</Link></header>{data.pending_requests.length ? <div className="activity-list">{data.pending_requests.map((request) => <div key={request.id}><FileCheck2 size={19} /><span><strong>{request.member.user?.name}</strong><small>{requestTypeLabel(request.type)} · {formatDate(`${request.date_from}T00:00:00+08:00`, 'dd MMM')}</small></span></div>)}</div> : <EmptyState title="Tidak ada antrean" description="Semua permohonan sudah ditinjau." />}</section>
        <section className="panel"><header className="panel__header"><h2>Status Gawai</h2><Link to="/admin/gawai">Kelola</Link></header>{data.device_status.length ? <div className="device-list">{data.device_status.map((device) => <div key={device.id}><Monitor size={19} /><span><strong>{device.name}</strong><small>{device.location || device.code}</small></span><StatusBadge tone={device.online ? 'success' : device.status === 'pending' ? 'neutral' : 'warning'}>{device.online ? 'Tersambung' : device.status === 'pending' ? 'Belum aktif' : 'Terputus'}</StatusBadge></div>)}</div> : <EmptyState title="Belum ada Gawai" description="Tambahkan Gawai untuk menampilkan QR." />}</section></div>
    </section>
  </>
}

function ScheduleFact({ label, target, opens, closes }: { label: string; target: string | null; opens: string | null; closes: string | null }) {
  return <span><Clock3 size={18} /><small>{label}</small><strong>{time(target)}</strong><em>Pemindaian {range(opens, closes)}</em></span>
}
function phaseLabel(phase: DashboardData['current_phase']) { return phase === 'check_in' ? 'Check-in aktif' : phase === 'check_out' ? 'Check-out aktif' : 'Di luar waktu pemindaian' }
function time(value: string | null) { return value ? `${formatDate(value, 'HH.mm')} WITA` : '-' }
function range(start: string | null, end: string | null) { return start && end ? `${formatDate(start, 'HH.mm')}–${formatDate(end, 'HH.mm')}` : '-' }
function requestTypeLabel(type: AttendanceRequestType) { return { missed_check_in: 'Check-in terlewat', missed_check_out: 'Check-out terlewat', time_correction: 'Koreksi waktu', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', other: 'Lainnya' }[type] }
