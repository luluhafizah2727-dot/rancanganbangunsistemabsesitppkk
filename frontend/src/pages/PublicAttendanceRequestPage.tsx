import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, KeyRound, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { BrandMark } from '../components/BrandMark'
import { Button, FormErrorSummary, LoadingScreen, StatusBadge } from '../components/ui'
import { api, ApiError, jsonBody } from '../lib/api'
import { requestStatusLabel, requestTone, requestTypeLabel } from '../lib/attendanceRequests'
import { formatDate } from '../lib/format'
import type { AttendanceContext, AttendanceRequest, AttendanceRequestType, PublicAttendanceRequestAction } from '../types'

const correctionTypes: AttendanceRequestType[] = ['missed_check_in', 'missed_check_out', 'time_correction']
const partialAbsenceTypes: AttendanceRequestType[] = ['permission', 'sick', 'official_duty']

export function PublicAttendanceRequestPage() {
  const { token = '' } = useParams()
  const [params] = useSearchParams()
  const action = params.get('action') === 'reject' ? 'reject' : 'approve'
  const [form, setForm] = useState({ code: '', review_note: '', approved_check_in_at: '', approved_check_out_at: '' })
  const query = useQuery({ queryKey: ['public-attendance-request-action', token], queryFn: () => api<PublicAttendanceRequestAction>(`/api/v1/public/attendance-request-actions/${token}`), retry: false })
  const mutation = useMutation({
    mutationFn: () => api<AttendanceRequest>(`/api/v1/public/attendance-request-actions/${token}/confirm`, { method: 'POST', ...jsonBody({ action, ...form, approved_check_in_at: form.approved_check_in_at || null, approved_check_out_at: form.approved_check_out_at || null }) }),
    onSuccess: () => {
      toast.success(action === 'approve' ? 'Permohonan berhasil disetujui.' : 'Permohonan berhasil ditolak.')
      query.refetch()
    },
    onError: (error) => toast.error(error instanceof ApiError ? error.message : 'Konfirmasi gagal diproses.'),
  })

  if (query.isLoading) return <LoadingScreen />
  if (query.isError || !query.data) {
    return <PublicShell><div className="public-card"><h1>Link tidak ditemukan</h1><p>Link konfirmasi tidak valid atau sudah tidak tersedia.</p><Link className="button button--secondary" to="/login">Masuk dashboard</Link></div></PublicShell>
  }

  const request = query.data.request
  const context = request.attendance_context
  const title = action === 'approve' ? 'Setujui permohonan' : 'Tolak permohonan'
  const hasProcessed = request.status !== 'pending'

  return (
    <PublicShell>
      <div className="public-card public-card--wide">
        <header className="public-card__header">
          <span className={`public-action-icon public-action-icon--${action}`}>{action === 'approve' ? <Check size={24} /> : <X size={24} />}</span>
          <div>
            <h1>{title}</h1>
            <p>Masukkan kode yang dikirim melalui WhatsApp untuk memastikan aksi berasal dari reviewer berwenang.</p>
          </div>
        </header>
        <div className="public-request-summary">
          <SummaryRow label="Reviewer" value={`${query.data.reviewer_hint.name} · ${query.data.reviewer_hint.login_id}`} />
          <SummaryRow label="Anggota" value={`${request.member.user?.name ?? '-'} · ${request.member.member_number}`} />
          <SummaryRow label="Jenis" value={requestTypeLabel(request)} />
          <SummaryRow label="Tanggal" value={dateRange(request)} />
          <SummaryRow label="Status" value={requestStatusLabel(request.status)} badgeTone={requestTone(request.status)} />
          <SummaryRow label="Konteks hadir" value={context ? attendanceContextLabel(context) : request.proposed_check_out_at && isPartialAbsenceType(request.type) ? `Usulan mulai ${formatDate(request.proposed_check_out_at, 'HH.mm')} WITA` : '-'} />
          <SummaryRow label="Alasan" value={request.reason} />
        </div>
        {!query.data.token_valid || hasProcessed ? <div className="public-warning"><KeyRound size={18} /><span>{hasProcessed ? 'Permohonan ini sudah diproses. Token lain otomatis tidak dapat dipakai.' : 'Token sudah kedaluwarsa atau sudah pernah dipakai.'}</span></div> : <form className="public-action-form" onSubmit={(event) => { event.preventDefault(); mutation.mutate() }}>
          <label className="field"><span>Kode konfirmasi</span><input className="input" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} placeholder="Tempel kode dari tombol Copy kode" required /></label>
          {action === 'approve' && isCorrectionType(request.type) ? <div className="form-grid"><label className="field"><span>Waktu check-in</span><input className="input" type="datetime-local" value={form.approved_check_in_at} onChange={(event) => setForm({ ...form, approved_check_in_at: event.target.value })} /></label><label className="field"><span>Waktu check-out</span><input className="input" type="datetime-local" value={form.approved_check_out_at} onChange={(event) => setForm({ ...form, approved_check_out_at: event.target.value })} /></label></div> : null}
          {action === 'approve' && isPartialAbsenceType(request.type) ? <label className="field"><span>Waktu mulai izin/sakit/dinas</span><input className="input" type="datetime-local" value={form.approved_check_out_at} onChange={(event) => setForm({ ...form, approved_check_out_at: event.target.value })} required={hasCheckedInWithoutCheckout(context)} />{hasCheckedInWithoutCheckout(context) ? <small>Wajib karena anggota sudah check-in dan belum checkout.</small> : <small>Isi hanya jika permohonan ini sebagian hari setelah check-in.</small>}</label> : null}
          <label className="field"><span>{action === 'reject' ? 'Alasan penolakan' : 'Catatan approval'}</span><textarea className="textarea" value={form.review_note} onChange={(event) => setForm({ ...form, review_note: event.target.value })} minLength={action === 'reject' ? 5 : undefined} required={action === 'reject'} /></label>
          <FormErrorSummary error={mutation.error} />
          <div className="form-actions"><Link className="button button--secondary" to="/login">Buka dashboard</Link><Button type="submit" variant={action === 'reject' ? 'danger' : 'primary'} disabled={mutation.isPending}>{mutation.isPending ? 'Memproses...' : action === 'approve' ? 'Setujui dengan kode' : 'Tolak dengan kode'}</Button></div>
        </form>}
      </div>
    </PublicShell>
  )
}

function PublicShell({ children }: { children: ReactNode }) {
  return <main className="public-action-page"><div className="public-brand"><BrandMark /><span><small>Absensi</small><strong>TP PKK Balangan</strong></span></div>{children}</main>
}

function SummaryRow({ label, value, badgeTone }: { label: string; value: string; badgeTone?: 'success' | 'warning' | 'danger' | 'neutral' | 'info' }) {
  return <div><dt>{label}</dt><dd>{badgeTone ? <StatusBadge tone={badgeTone}>{value}</StatusBadge> : value}</dd></div>
}

function dateRange(item: AttendanceRequest) { return item.date_from === item.date_to ? formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMM yyyy') : `${formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMM')}–${formatDate(`${item.date_to}T00:00:00+08:00`, 'dd MMM yyyy')}` }
function isCorrectionType(type: AttendanceRequestType) { return correctionTypes.includes(type) }
function isPartialAbsenceType(type: AttendanceRequestType) { return partialAbsenceTypes.includes(type) }
function hasCheckedInWithoutCheckout(context: AttendanceContext | null) { return Boolean(context?.check_in_at && !context.check_out_at) }
function attendanceContextLabel(context: AttendanceContext) {
  if (context.presence_summary.label) return context.presence_summary.label
  if (context.check_in_at && context.check_out_at) return `Hadir ${formatDate(context.check_in_at, 'HH.mm')}–${formatDate(context.check_out_at, 'HH.mm')} WITA`
  if (context.check_in_at) return `Sudah check-in ${formatDate(context.check_in_at, 'HH.mm')} WITA, belum checkout.`
  return { pending: 'Belum hadir', present: 'Hadir', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }[context.status]
}
