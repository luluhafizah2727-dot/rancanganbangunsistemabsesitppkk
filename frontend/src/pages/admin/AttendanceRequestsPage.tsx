import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Download, Search, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button, EmptyState, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { API_URL, api, ApiError, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { requestStatusLabel, requestTone, requestTypeLabel } from '../../lib/attendanceRequests'
import { formatDate } from '../../lib/format'
import type { AttendanceContext, AttendanceRequest, AttendanceRequestStatus, AttendanceRequestType } from '../../types'

const correctionTypes: AttendanceRequestType[] = ['missed_check_in', 'missed_check_out', 'time_correction']
const partialAbsenceTypes: AttendanceRequestType[] = ['permission', 'sick', 'official_duty']

export function AttendanceRequestsPage() {
  const { user } = useAuth()
  const canReview = user?.roles.includes('super_admin') ?? false
  const client = useQueryClient()
  const [status, setStatus] = useState<AttendanceRequestStatus | ''>('pending')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<AttendanceRequest | null>(null)
  const [reviewMode, setReviewMode] = useState<'approve' | 'reject' | null>(null)
  const [review, setReview] = useState({ review_note: '', approved_check_in_at: '', approved_check_out_at: '' })
  const requests = useQuery({ queryKey: ['attendance-requests-admin', status, search], queryFn: () => api<AttendanceRequest[]>(`/api/v1/admin/attendance-requests?status=${status}&search=${encodeURIComponent(search)}&per_page=100`) })
  const reviewMutation = useMutation({
    mutationFn: () => api(`/api/v1/admin/attendance-requests/${selected?.id}/${reviewMode}`, { method: 'POST', ...jsonBody({ ...review, approved_check_in_at: review.approved_check_in_at || null, approved_check_out_at: review.approved_check_out_at || null }) }),
    onSuccess: () => { setSelected(null); setReviewMode(null); client.invalidateQueries({ queryKey: ['attendance-requests-admin'] }); client.invalidateQueries({ queryKey: ['attendances'] }); client.invalidateQueries({ queryKey: ['dashboard'] }); toast.success(reviewMode === 'approve' ? 'Permohonan disetujui.' : 'Permohonan ditolak.') },
    onError: (error) => toast.error(error instanceof ApiError ? error.message : 'Permohonan tidak dapat diproses.'),
  })

  const openReview = (item: AttendanceRequest, mode: 'approve' | 'reject') => {
    setSelected(item)
    setReviewMode(mode)
    setReview({
      review_note: '',
      approved_check_in_at: toLocalInput(item.proposed_check_in_at),
      approved_check_out_at: toLocalInput(item.proposed_check_out_at),
    })
  }

  return <>
    <PageHeader title="Permohonan" description="Tinjau koreksi dan ketidakhadiran yang diajukan anggota." />
    <section className="panel"><div className="panel__body toolbar"><div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari anggota" /></div><label className="field compact-field"><span>Status</span><select className="select" value={status} onChange={(event) => setStatus(event.target.value as AttendanceRequestStatus | '')}><option value="">Semua</option><option value="pending">Menunggu</option><option value="approved">Disetujui</option><option value="rejected">Ditolak</option><option value="cancelled">Dibatalkan</option></select></label></div>
      {requests.data?.length ? <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Anggota</th><th>Jenis</th><th>Tanggal</th><th>Diajukan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{requests.data.map((item) => <tr key={item.id}><td><span className="table-primary">{item.member.user?.name}</span><span className="table-secondary">{item.member.member_number}</span></td><td>{requestTypeLabel(item)}</td><td>{dateRange(item)}</td><td>{formatDate(item.created_at, 'dd MMM, HH.mm')}</td><td><StatusBadge tone={requestTone(item.status)}>{requestStatusLabel(item.status)}</StatusBadge></td><td><div className="table-actions"><Button variant="ghost" onClick={() => setSelected(item)}>Detail</Button>{canReview && item.status === 'pending' ? <><Button variant="secondary" icon={<Check size={15} />} onClick={() => openReview(item, 'approve')}>Setujui</Button><Button variant="ghost" icon={<X size={15} />} onClick={() => openReview(item, 'reject')}>Tolak</Button></> : null}</div></td></tr>)}</tbody></table></div> : <EmptyState title="Tidak ada permohonan" description="Permohonan yang sesuai filter akan tampil di sini." />}
    </section>
    {selected ? <Modal title={reviewMode === 'approve' ? 'Setujui permohonan' : reviewMode === 'reject' ? 'Tolak permohonan' : 'Detail permohonan'} onClose={() => { setSelected(null); setReviewMode(null) }}><div className="modal__content request-detail"><dl><div><dt>Anggota</dt><dd>{selected.member.user?.name} · {selected.member.member_number}</dd></div><div><dt>Jenis</dt><dd>{requestTypeLabel(selected)}</dd></div><div><dt>Tanggal</dt><dd>{dateRange(selected)}</dd></div><div><dt>Alasan</dt><dd>{selected.reason}</dd></div>{selected.attendance_context ? <div><dt>Absensi saat ini</dt><dd>{attendanceContextLabel(selected.attendance_context)}</dd></div> : null}{selected.proposed_check_in_at ? <div><dt>Usulan check-in</dt><dd>{formatDate(selected.proposed_check_in_at, 'dd MMM yyyy, HH.mm')} WITA</dd></div> : null}{selected.proposed_check_out_at ? <div><dt>{isPartialAbsenceType(selected.type) ? 'Usulan mulai izin/sakit/dinas' : 'Usulan check-out'}</dt><dd>{formatDate(selected.proposed_check_out_at, 'dd MMM yyyy, HH.mm')} WITA</dd></div> : null}{selected.attendance_context?.presence_summary.label ? <div><dt>Jejak hadir</dt><dd>{selected.attendance_context.presence_summary.label}</dd></div> : null}</dl>{selected.has_attachment ? <a className="button button--secondary" href={`${API_URL}/api/v1/attendance-requests/${selected.id}/attachment`} target="_blank" rel="noreferrer"><Download size={16} /> Lihat lampiran</a> : null}{reviewMode ? <form onSubmit={(event) => { event.preventDefault(); reviewMutation.mutate() }}>{reviewMode === 'approve' && isCorrectionType(selected.type) ? <div className="form-grid"><label className="field"><span>Waktu check-in</span><input className="input" type="datetime-local" value={review.approved_check_in_at} onChange={(event) => setReview({ ...review, approved_check_in_at: event.target.value })} /></label><label className="field"><span>Waktu check-out</span><input className="input" type="datetime-local" value={review.approved_check_out_at} onChange={(event) => setReview({ ...review, approved_check_out_at: event.target.value })} /></label></div> : null}{reviewMode === 'approve' && isPartialAbsenceType(selected.type) ? <label className="field"><span>Waktu mulai izin/sakit/dinas</span><input className="input" type="datetime-local" value={review.approved_check_out_at} onChange={(event) => setReview({ ...review, approved_check_out_at: event.target.value })} required={hasCheckedInWithoutCheckout(selected.attendance_context)} />{hasCheckedInWithoutCheckout(selected.attendance_context) ? <small>Wajib diisi karena anggota sudah check-in dan belum checkout.</small> : <small>Isi hanya jika permohonan ini untuk sebagian hari setelah check-in.</small>}</label> : null}<label className="field"><span>{reviewMode === 'reject' ? 'Alasan penolakan' : 'Catatan review'}</span><textarea className="textarea" minLength={reviewMode === 'reject' ? 5 : undefined} required={reviewMode === 'reject'} value={review.review_note} onChange={(event) => setReview({ ...review, review_note: event.target.value })} /></label><div className="form-actions"><Button type="button" variant="secondary" onClick={() => { setSelected(null); setReviewMode(null) }}>Batal</Button><Button type="submit" variant={reviewMode === 'reject' ? 'danger' : 'primary'} disabled={reviewMutation.isPending}>{reviewMutation.isPending ? 'Memproses...' : reviewMode === 'approve' ? 'Setujui' : 'Tolak'}</Button></div></form> : null}</div></Modal> : null}
  </>
}

function dateRange(item: AttendanceRequest) { return item.date_from === item.date_to ? formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMM yyyy') : `${formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMM')}–${formatDate(`${item.date_to}T00:00:00+08:00`, 'dd MMM yyyy')}` }
function toLocalInput(value: string | null) { return value ? formatDate(value, "yyyy-MM-dd'T'HH:mm") : '' }
function isCorrectionType(type: AttendanceRequestType) { return correctionTypes.includes(type) }
function isPartialAbsenceType(type: AttendanceRequestType) { return partialAbsenceTypes.includes(type) }
function hasCheckedInWithoutCheckout(context: AttendanceContext | null) { return Boolean(context?.check_in_at && !context.check_out_at) }
function attendanceContextLabel(context: AttendanceContext) {
  if (context.presence_summary.label) return context.presence_summary.label
  if (context.check_in_at && context.check_out_at) return `Hadir ${formatDate(context.check_in_at, 'HH.mm')}–${formatDate(context.check_out_at, 'HH.mm')} WITA`
  if (context.check_in_at) return `Sudah check-in ${formatDate(context.check_in_at, 'HH.mm')} WITA, belum checkout.`
  return statusLabel(context.status)
}
function statusLabel(status: AttendanceContext['status']) {
  return { pending: 'Belum hadir', present: 'Hadir', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }[status]
}
