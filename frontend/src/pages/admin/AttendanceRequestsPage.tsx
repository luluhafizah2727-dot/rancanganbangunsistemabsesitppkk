import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Download, RefreshCw, Search, ShieldCheck, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { Button, EmptyState, FormErrorSummary, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { API_URL, api, ApiError, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { requestStatusLabel, requestTone, requestTypeLabel } from '../../lib/attendanceRequests'
import { formatDate } from '../../lib/format'
import type { AttendanceContext, AttendanceRequest, AttendanceRequestReviewerSettings, AttendanceRequestStatus, AttendanceRequestType } from '../../types'

const correctionTypes: AttendanceRequestType[] = ['missed_check_in', 'missed_check_out', 'time_correction']
const partialAbsenceTypes: AttendanceRequestType[] = ['permission', 'sick', 'official_duty']

export function AttendanceRequestsPage() {
  const { user, refresh } = useAuth()
  const isSuperAdmin = user?.roles.includes('super_admin') ?? false
  const canReview = user?.can_review_attendance_requests || isSuperAdmin
  const client = useQueryClient()
  const [status, setStatus] = useState<AttendanceRequestStatus | ''>('pending')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<AttendanceRequest | null>(null)
  const [reviewMode, setReviewMode] = useState<'approve' | 'reject' | null>(null)
  const [review, setReview] = useState({ review_note: '', approved_check_in_at: '', approved_check_out_at: '' })

  const requests = useQuery({
    queryKey: ['attendance-requests-admin', status, search],
    queryFn: () => api<AttendanceRequest[]>(`/api/v1/admin/attendance-requests?status=${status}&search=${encodeURIComponent(search)}&per_page=100`),
  })
  const metrics = useMemo(() => {
    const items = requests.data ?? []

    return {
      total: items.length,
      pending: items.filter((item) => item.status === 'pending').length,
      partial: items.filter((item) => item.attendance_context?.presence_summary.is_partial_absence || item.proposed_check_out_at && isPartialAbsenceType(item.type)).length,
    }
  }, [requests.data])

  const reviewMutation = useMutation({
    mutationFn: () => api(`/api/v1/admin/attendance-requests/${selected?.id}/${reviewMode}`, { method: 'POST', ...jsonBody({ ...review, approved_check_in_at: review.approved_check_in_at || null, approved_check_out_at: review.approved_check_out_at || null }) }),
    onSuccess: async () => {
      setSelected(null)
      setReviewMode(null)
      await Promise.all([
        client.invalidateQueries({ queryKey: ['attendance-requests-admin'] }),
        client.invalidateQueries({ queryKey: ['attendances'] }),
        client.invalidateQueries({ queryKey: ['dashboard'] }),
        refresh(),
      ])
      toast.success(reviewMode === 'approve' ? 'Permohonan disetujui.' : 'Permohonan ditolak.')
    },
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

  return (
    <>
      <PageHeader
        title="Permohonan"
        description="Tinjau izin, sakit, cuti, dinas, dan koreksi absensi anggota."
        actions={<Button type="button" variant="secondary" icon={<RefreshCw size={16} />} onClick={() => requests.refetch()} disabled={requests.isFetching}>Refresh</Button>}
      />
      {isSuperAdmin ? <ReviewerSettingsPanel /> : null}
      {!canReview ? <section className="panel request-auth-note"><ShieldCheck size={19} /><span>Anda dapat melihat permohonan, tetapi approval hanya tersedia untuk Super Admin atau operator yang diberi wewenang.</span></section> : null}
      <section className="panel request-toolbar-panel">
        <div className="panel__body request-toolbar">
          <div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama atau nomor anggota" /></div>
          <label className="field compact-field"><span>Status</span><select className="select" value={status} onChange={(event) => setStatus(event.target.value as AttendanceRequestStatus | '')}><option value="">Semua</option><option value="pending">Menunggu</option><option value="approved">Disetujui</option><option value="rejected">Ditolak</option><option value="cancelled">Dibatalkan</option></select></label>
          <div className="request-mini-metrics"><span><strong>{metrics.pending}</strong><small>Menunggu</small></span><span><strong>{metrics.partial}</strong><small>Sempat hadir</small></span><span><strong>{metrics.total}</strong><small>Dalam filter</small></span></div>
        </div>
      </section>
      <section className="panel request-table-panel">
        {requests.data?.length ? <div className="data-table-wrap"><table className="data-table request-table"><thead><tr><th>Anggota</th><th>Permohonan</th><th>Tanggal</th><th>Konteks</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{requests.data.map((item) => <tr key={item.id}><td><span className="table-primary">{item.member.user?.name}</span><span className="table-secondary">{item.member.member_number}</span></td><td><span className="table-primary">{requestTypeLabel(item)}</span><span className="table-secondary">{formatDate(item.created_at, 'dd MMM, HH.mm')} WITA</span></td><td>{dateRange(item)}</td><td><span className="request-context-inline">{item.attendance_context ? attendanceContextLabel(item.attendance_context) : item.proposed_check_out_at && isPartialAbsenceType(item.type) ? `Usulan mulai ${formatDate(item.proposed_check_out_at, 'HH.mm')} WITA` : '—'}</span></td><td><StatusBadge tone={requestTone(item.status)}>{requestStatusLabel(item.status)}</StatusBadge></td><td><div className="table-actions"><Button variant="ghost" onClick={() => setSelected(item)}>Detail</Button>{canReview && item.status === 'pending' ? <><Button variant="secondary" icon={<Check size={15} />} onClick={() => openReview(item, 'approve')}>Setujui</Button><Button variant="ghost" icon={<X size={15} />} onClick={() => openReview(item, 'reject')}>Tolak</Button></> : null}</div></td></tr>)}</tbody></table></div> : <EmptyState title="Tidak ada permohonan" description="Permohonan yang sesuai filter akan tampil di sini." />}
      </section>
      {selected ? <RequestModal selected={selected} reviewMode={reviewMode} review={review} canReview={canReview} busy={reviewMutation.isPending} error={reviewMutation.error} onReviewChange={setReview} onClose={() => { setSelected(null); setReviewMode(null) }} onOpenReview={openReview} onSubmit={() => reviewMutation.mutate()} /> : null}
    </>
  )
}

function ReviewerSettingsPanel() {
  const client = useQueryClient()
  const reviewers = useQuery({ queryKey: ['attendance-request-reviewers'], queryFn: () => api<AttendanceRequestReviewerSettings>('/api/v1/admin/attendance-request-reviewers') })
  const [selectedIds, setSelectedIds] = useState<string[]>([])

  useEffect(() => {
    if (!reviewers.data) return
    setSelectedIds(reviewers.data.operators.filter((operator) => operator.authorized).map((operator) => operator.id))
  }, [reviewers.data])

  const save = useMutation({
    mutationFn: () => api<AttendanceRequestReviewerSettings>('/api/v1/admin/attendance-request-reviewers', { method: 'PUT', ...jsonBody({ operator_ids: selectedIds }) }),
    onSuccess: () => {
      toast.success('Wewenang operator berhasil diperbarui.')
      client.invalidateQueries({ queryKey: ['attendance-request-reviewers'] })
    },
    onError: (error) => toast.error(error instanceof ApiError ? error.message : 'Pengaturan reviewer gagal disimpan.'),
  })
  const toggle = (id: string) => setSelectedIds((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])

  return (
    <section className="panel reviewer-settings-panel">
      <header className="panel__header"><div><h2>Reviewer operator</h2><p>Super Admin selalu berwenang. Pilih operator yang boleh approve/reject permohonan dan menerima link WhatsApp.</p></div><Button type="button" variant="secondary" onClick={() => save.mutate()} disabled={save.isPending}>{save.isPending ? 'Menyimpan...' : 'Simpan reviewer'}</Button></header>
      <div className="panel__body reviewer-list">
        {reviewers.data?.operators.length ? reviewers.data.operators.map((operator) => <label key={operator.id} className={`reviewer-card ${!operator.can_be_authorized ? 'reviewer-card--disabled' : ''}`}><input type="checkbox" checked={selectedIds.includes(operator.id)} disabled={!operator.can_be_authorized} onChange={() => toggle(operator.id)} /><span><strong>{operator.name}</strong><small>{operator.login_id}{operator.phone ? ` · ${operator.phone}` : ' · nomor WA belum diisi'} · {operator.receive_wa_notifications ? 'notifikasi aktif' : 'notifikasi nonaktif'}</small></span><StatusBadge tone={operator.can_be_authorized ? 'info' : 'neutral'}>{operator.status}</StatusBadge></label>) : <EmptyState title="Belum ada operator" description="Tambahkan akun operator dari menu Akun untuk mendelegasikan review." />}
      </div>
    </section>
  )
}

function RequestModal({ selected, reviewMode, review, canReview, busy, error, onReviewChange, onClose, onOpenReview, onSubmit }: {
  selected: AttendanceRequest
  reviewMode: 'approve' | 'reject' | null
  review: { review_note: string; approved_check_in_at: string; approved_check_out_at: string }
  canReview: boolean
  busy: boolean
  error: unknown
  onReviewChange: (value: { review_note: string; approved_check_in_at: string; approved_check_out_at: string }) => void
  onClose: () => void
  onOpenReview: (item: AttendanceRequest, mode: 'approve' | 'reject') => void
  onSubmit: () => void
}) {
  return (
    <Modal title={reviewMode === 'approve' ? 'Setujui permohonan' : reviewMode === 'reject' ? 'Tolak permohonan' : 'Detail permohonan'} onClose={onClose} className="modal--wide">
      <div className="modal__content request-detail">
        <div className="request-detail-grid">
          <DetailSection title="Data pemohon" items={[['Anggota', `${selected.member.user?.name ?? '-'} · ${selected.member.member_number}`], ['Diajukan', `${formatDate(selected.created_at, 'dd MMM yyyy, HH.mm')} WITA`], ['Status', requestStatusLabel(selected.status)]]} />
          <DetailSection title="Detail permohonan" items={[['Jenis', requestTypeLabel(selected)], ['Tanggal', dateRange(selected)], ['Alasan', selected.reason]]} />
          <DetailSection title="Konteks kehadiran" items={[['Absensi saat ini', selected.attendance_context ? attendanceContextLabel(selected.attendance_context) : 'Belum ada jejak absensi pada tanggal ini'], ['Usulan check-in', selected.proposed_check_in_at ? `${formatDate(selected.proposed_check_in_at, 'dd MMM yyyy, HH.mm')} WITA` : '-'], [isPartialAbsenceType(selected.type) ? 'Usulan mulai izin/sakit/dinas' : 'Usulan check-out', selected.proposed_check_out_at ? `${formatDate(selected.proposed_check_out_at, 'dd MMM yyyy, HH.mm')} WITA` : '-']]} />
          <section className="request-section">
            <h3>Lampiran & jejak review</h3>
            {selected.has_attachment ? <a className="button button--secondary" href={`${API_URL}/api/v1/attendance-requests/${selected.id}/attachment`} target="_blank" rel="noreferrer"><Download size={16} /> Lihat lampiran</a> : <p className="muted">Tidak ada lampiran.</p>}
            {selected.reviewer ? <p className="review-trail">Direview oleh <strong>{selected.reviewer.name}</strong>{selected.reviewed_at ? ` pada ${formatDate(selected.reviewed_at, 'dd MMM yyyy, HH.mm')} WITA` : ''}.</p> : <p className="muted">Belum direview.</p>}
            {selected.review_note ? <p className="review-note">Catatan: {selected.review_note}</p> : null}
          </section>
        </div>
        {reviewMode ? <form className="review-form" onSubmit={(event) => { event.preventDefault(); onSubmit() }}>
          {reviewMode === 'approve' && isCorrectionType(selected.type) ? <div className="form-grid"><label className="field"><span>Waktu check-in</span><input className="input" type="datetime-local" value={review.approved_check_in_at} onChange={(event) => onReviewChange({ ...review, approved_check_in_at: event.target.value })} /></label><label className="field"><span>Waktu check-out</span><input className="input" type="datetime-local" value={review.approved_check_out_at} onChange={(event) => onReviewChange({ ...review, approved_check_out_at: event.target.value })} /></label></div> : null}
          {reviewMode === 'approve' && isPartialAbsenceType(selected.type) ? <label className="field"><span>Waktu mulai izin/sakit/dinas</span><input className="input" type="datetime-local" value={review.approved_check_out_at} onChange={(event) => onReviewChange({ ...review, approved_check_out_at: event.target.value })} required={hasCheckedInWithoutCheckout(selected.attendance_context)} />{hasCheckedInWithoutCheckout(selected.attendance_context) ? <small>Wajib karena anggota sudah check-in dan belum checkout.</small> : <small>Isi hanya untuk permohonan sebagian hari setelah check-in.</small>}</label> : null}
          <label className="field"><span>{reviewMode === 'reject' ? 'Alasan penolakan' : 'Catatan review'}</span><textarea className="textarea" minLength={reviewMode === 'reject' ? 5 : undefined} required={reviewMode === 'reject'} value={review.review_note} onChange={(event) => onReviewChange({ ...review, review_note: event.target.value })} /></label>
          <FormErrorSummary error={error} />
          <div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" variant={reviewMode === 'reject' ? 'danger' : 'primary'} disabled={busy}>{busy ? 'Memproses...' : reviewMode === 'approve' ? 'Setujui' : 'Tolak'}</Button></div>
        </form> : <div className="form-actions">{canReview && selected.status === 'pending' ? <><Button type="button" variant="secondary" icon={<Check size={15} />} onClick={() => onOpenReview(selected, 'approve')}>Setujui</Button><Button type="button" variant="danger" icon={<X size={15} />} onClick={() => onOpenReview(selected, 'reject')}>Tolak</Button></> : null}<Button type="button" variant="ghost" onClick={onClose}>Tutup</Button></div>}
      </div>
    </Modal>
  )
}

function DetailSection({ title, items }: { title: string; items: Array<[string, string]> }) {
  return <section className="request-section"><h3>{title}</h3><dl>{items.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>
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
