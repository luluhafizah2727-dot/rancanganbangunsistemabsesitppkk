import { useQuery } from '@tanstack/react-query'
import { Download, Eye, FileSpreadsheet, FileText } from 'lucide-react'
import { useState } from 'react'
import { Button, EmptyState, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { API_URL, api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { Attendance, AttendanceDay } from '../../types'

interface ReportPreview {
  date_from: string
  date_to: string
  days: AttendanceDay[]
  attendances: Attendance[]
  summary: { expected: number; present: number; permission: number; leave: number; sick: number; official_duty: number; absent: number; pending: number }
  generated_at: string
}

const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())

export function ReportsPage() {
  const [dateFrom, setDateFrom] = useState(today)
  const [dateTo, setDateTo] = useState(today)
  const [showPreview, setShowPreview] = useState(false)
  const params = `date_from=${dateFrom}&date_to=${dateTo}`
  const preview = useQuery({ queryKey: ['report-preview', dateFrom, dateTo], queryFn: () => api<ReportPreview>(`/api/v1/reports/attendance?${params}`), enabled: showPreview })
  const download = (type: 'pdf' | 'xlsx') => window.open(`${API_URL}/api/v1/reports/attendance/${type}?${params}`, '_blank', 'noopener')

  return <>
    <PageHeader title="Laporan" description="Preview dan unduh rekap absensi berdasarkan rentang tanggal." />
    <section className="panel report-filter-panel"><div className="panel__body report-filter"><label className="field"><span>Dari tanggal</span><input className="input" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} /></label><label className="field"><span>Sampai tanggal</span><input className="input" type="date" min={dateFrom} value={dateTo} onChange={(event) => setDateTo(event.target.value)} /></label><div className="report-actions"><Button icon={<Eye size={17} />} onClick={() => setShowPreview(true)}>Preview laporan</Button><Button variant="secondary" icon={<FileText size={17} />} onClick={() => download('pdf')}>PDF</Button><Button variant="secondary" icon={<FileSpreadsheet size={17} />} onClick={() => download('xlsx')}>Excel</Button></div></div></section>
    <section className="report-guide panel"><span className="resource-icon"><Download size={22} /></span><div><h2>Laporan absensi harian</h2><p>Pilih satu tanggal untuk laporan harian atau rentang maksimal 31 hari untuk rekap berkala.</p></div></section>
    {showPreview ? <Modal title="Preview laporan absensi" onClose={() => setShowPreview(false)} className="modal--wide"><div className="modal__content report-preview">{preview.isLoading ? <p className="muted">Memuat laporan...</p> : null}{preview.isError ? <p className="field-error">Laporan tidak dapat dimuat. Periksa rentang tanggal.</p> : null}{preview.data ? <><div className="report-preview__summary">{summaryItems(preview.data).map(([label, value]) => <span key={label}><small>{label}</small><strong>{value}</strong></span>)}</div><div className="report-period">Periode {formatDate(`${preview.data.date_from}T00:00:00+08:00`, 'dd MMM yyyy')}–{formatDate(`${preview.data.date_to}T00:00:00+08:00`, 'dd MMM yyyy')} · Dibuat {formatDate(preview.data.generated_at, 'dd MMM, HH.mm')}</div><div className="data-table-wrap"><table className="data-table"><thead><tr><th>Tanggal</th><th>Anggota</th><th>Status</th><th>Check-in</th><th>Check-out</th></tr></thead><tbody>{preview.data.attendances.map((attendance) => <tr key={attendance.id}><td>{formatDate(`${attendance.day.attendance_date}T00:00:00+08:00`, 'dd/MM/yyyy')}</td><td><span className="table-primary">{attendance.member.user?.name}</span><span className="table-secondary">{attendance.member.member_number}</span></td><td><ReportStatus status={attendance.status} /></td><td>{attendance.check_in_at ? formatDate(attendance.check_in_at, 'HH.mm.ss') : '-'}</td><td>{attendance.check_out_at ? formatDate(attendance.check_out_at, 'HH.mm.ss') : '-'}</td></tr>)}</tbody></table>{!preview.data.attendances.length ? <EmptyState title="Belum ada data" description="Tidak ada hari kerja atau anggota pada rentang ini." /> : null}</div><div className="form-actions"><Button variant="secondary" icon={<FileText size={17} />} onClick={() => download('pdf')}>Unduh PDF</Button><Button variant="secondary" icon={<FileSpreadsheet size={17} />} onClick={() => download('xlsx')}>Unduh Excel</Button></div></> : null}</div></Modal> : null}
  </>
}

function summaryItems(data: ReportPreview): Array<[string, number]> { return [['Hadir', data.summary.present], ['Izin', data.summary.permission], ['Cuti', data.summary.leave], ['Sakit', data.summary.sick], ['Dinas', data.summary.official_duty], ['Alpa', data.summary.absent], ['Belum hadir', data.summary.pending]] }
function ReportStatus({ status }: { status: Attendance['status'] }) {
  const label = { pending: 'Belum hadir', present: 'Hadir', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }[status]
  const tone = { pending: 'neutral', present: 'success', permission: 'info', leave: 'info', sick: 'warning', official_duty: 'info', absent: 'danger' }[status] as 'neutral' | 'success' | 'info' | 'warning' | 'danger'
  return <StatusBadge tone={tone}>{label}</StatusBadge>
}
