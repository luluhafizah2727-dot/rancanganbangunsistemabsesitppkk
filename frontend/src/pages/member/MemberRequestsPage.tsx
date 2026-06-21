import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileCheck2, Paperclip, Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button, ConfirmDialog, EmptyState, Modal, StatusBadge } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { requestStatusLabel, requestTone, requestTypeLabel } from '../../lib/attendanceRequests'
import { formatDate } from '../../lib/format'
import type { AttendanceRequest, AttendanceRequestType } from '../../types'

const today = formatDate(new Date().toISOString(), 'yyyy-MM-dd')

export function MemberRequestsPage() {
  const client = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<AttendanceRequest | null>(null)
  const [form, setForm] = useState({ type: 'permission' as AttendanceRequestType, date_from: today, date_to: today, proposed_check_in_at: '', proposed_check_out_at: '', other_label: '', reason: '', attachment: null as File | null })
  const requests = useQuery({ queryKey: ['member-attendance-requests'], queryFn: () => api<AttendanceRequest[]>('/api/v1/attendance-requests?per_page=100') })
  const submit = useMutation({
    mutationFn: async () => {
      const data = new FormData()
      Object.entries(form).forEach(([key, value]) => { if (value) data.append(key, value) })
      return api('/api/v1/attendance-requests', { method: 'POST', body: data })
    },
    onSuccess: () => { setShowForm(false); resetForm(); client.invalidateQueries({ queryKey: ['member-attendance-requests'] }); toast.success('Permohonan berhasil dikirim.') },
    onError: showError,
  })
  const cancel = useMutation({
    mutationFn: (item: AttendanceRequest) => api(`/api/v1/attendance-requests/${item.id}`, { method: 'DELETE' }),
    onSuccess: () => { setCancelTarget(null); client.invalidateQueries({ queryKey: ['member-attendance-requests'] }); toast.success('Permohonan dibatalkan.') },
    onError: showError,
  })
  const correction = ['missed_check_in', 'missed_check_out', 'time_correction'].includes(form.type)
  const resetForm = () => setForm({ type: 'permission', date_from: today, date_to: today, proposed_check_in_at: '', proposed_check_out_at: '', other_label: '', reason: '', attachment: null })

  return <div className="member-page"><header className="member-section-title member-section-title--actions"><div><h1>Permohonan</h1><p>Kirim koreksi atau keterangan ketidakhadiran.</p></div><Button icon={<Plus size={17} />} onClick={() => setShowForm(true)}>Ajukan</Button></header>
    <section className="request-list">{requests.data?.length ? requests.data.map((item) => <article className="member-card request-card" key={item.id}><span className="request-card__icon"><FileCheck2 size={22} /></span><div><div className="request-card__head"><h2>{requestTypeLabel(item)}</h2><StatusBadge tone={requestTone(item.status)}>{requestStatusLabel(item.status)}</StatusBadge></div><p>{dateLabel(item)} · Diajukan {formatDate(item.created_at, 'dd MMM yyyy')}</p><small>{item.reason}</small>{item.review_note ? <div className="request-review"><strong>Catatan admin</strong><span>{item.review_note}</span></div> : null}{item.status === 'pending' ? <Button variant="ghost" onClick={() => setCancelTarget(item)}>Batalkan</Button> : null}</div></article>) : <EmptyState title="Belum ada permohonan" description="Koreksi, izin, cuti, sakit, atau dinas yang Anda ajukan akan tampil di sini." />}</section>
    {showForm ? <Modal title="Ajukan permohonan" onClose={() => setShowForm(false)}><form onSubmit={(event) => { event.preventDefault(); submit.mutate() }}><div className="form-grid"><label className="field field--full"><span>Jenis permohonan</span><select className="select" value={form.type} onChange={(event) => { const type = event.target.value as AttendanceRequestType; setForm({ ...form, type, date_to: ['missed_check_in', 'missed_check_out', 'time_correction'].includes(type) ? form.date_from : form.date_to }) }}><option value="missed_check_in">Check-in terlewat</option><option value="missed_check_out">Check-out terlewat</option><option value="time_correction">Koreksi waktu</option><option value="permission">Izin</option><option value="leave">Cuti</option><option value="sick">Sakit</option><option value="official_duty">Dinas</option><option value="other">Lainnya</option></select></label><label className="field"><span>{correction ? 'Tanggal' : 'Dari tanggal'}</span><input className="input" type="date" value={form.date_from} onChange={(event) => setForm({ ...form, date_from: event.target.value, date_to: correction ? event.target.value : form.date_to })} required /></label>{!correction ? <label className="field"><span>Sampai tanggal</span><input className="input" type="date" value={form.date_to} min={form.date_from} onChange={(event) => setForm({ ...form, date_to: event.target.value })} required /></label> : null}{form.type === 'missed_check_in' || form.type === 'time_correction' ? <label className="field"><span>Usulan check-in</span><input className="input" type="datetime-local" value={form.proposed_check_in_at} onChange={(event) => setForm({ ...form, proposed_check_in_at: event.target.value })} required={form.type === 'missed_check_in'} /></label> : null}{form.type === 'missed_check_out' || form.type === 'time_correction' ? <label className="field"><span>Usulan check-out</span><input className="input" type="datetime-local" value={form.proposed_check_out_at} onChange={(event) => setForm({ ...form, proposed_check_out_at: event.target.value })} required={form.type === 'missed_check_out'} /></label> : null}{form.type === 'other' ? <label className="field field--full"><span>Nama keperluan</span><input className="input" value={form.other_label} onChange={(event) => setForm({ ...form, other_label: event.target.value })} required /></label> : null}<label className="field field--full"><span>Alasan</span><textarea className="textarea" minLength={10} maxLength={2000} value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} required /></label><label className="field field--full file-field"><span>Lampiran (opsional)</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => setForm({ ...form, attachment: event.target.files?.[0] ?? null })} /><small><Paperclip size={14} /> PDF/JPG/PNG, maksimal 5 MB</small></label></div><div className="form-actions"><Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Batal</Button><Button type="submit" disabled={submit.isPending}>{submit.isPending ? 'Mengirim...' : 'Kirim permohonan'}</Button></div></form></Modal> : null}
    {cancelTarget ? <ConfirmDialog title="Batalkan permohonan?" description="Permohonan yang dibatalkan tidak dapat diproses admin." confirmLabel={cancel.isPending ? 'Membatalkan...' : 'Batalkan permohonan'} confirmVariant="danger" disabled={cancel.isPending} onCancel={() => setCancelTarget(null)} onConfirm={() => cancel.mutate(cancelTarget)} /> : null}
  </div>
}

function dateLabel(item: AttendanceRequest) { return item.date_from === item.date_to ? formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMMM yyyy') : `${formatDate(`${item.date_from}T00:00:00+08:00`, 'dd MMM')}–${formatDate(`${item.date_to}T00:00:00+08:00`, 'dd MMM yyyy')}` }
function showError(error: unknown) { toast.error(error instanceof ApiError ? error.message : 'Permohonan tidak dapat disimpan.') }
