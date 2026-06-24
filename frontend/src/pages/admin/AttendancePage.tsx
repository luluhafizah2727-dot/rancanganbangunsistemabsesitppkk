import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, Clock3, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button, ConfirmDialog, EmptyState, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, ApiError, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import type { Attendance, AttendanceListResponse, AttendanceStatus, Member } from '../../types'

const today = dateInMakassar(new Date())
const emptyForm = { member_id: '', attendance_date: today, status: 'present' as AttendanceStatus, check_in_at: `${today}T08:00`, check_out_at: '', note: '', reason: '' }

export function AttendancePage() {
  const { user } = useAuth()
  const canManage = user?.roles.includes('super_admin') ?? false
  const [date, setDate] = useState(today)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [editing, setEditing] = useState<Attendance | 'new' | null>(null)
  const [removing, setRemoving] = useState<Attendance | null>(null)
  const [deleteReason, setDeleteReason] = useState('')
  const [form, setForm] = useState(emptyForm)
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: ['attendances', date, search, status],
    queryFn: () => api<AttendanceListResponse>(`/api/v1/attendances?date=${date}&search=${encodeURIComponent(search)}&status=${status}`),
  })
  const members = useQuery({ queryKey: ['members', 'attendance-form'], queryFn: () => api<Member[]>('/api/v1/members?status=active&per_page=100'), enabled: canManage })
  const save = useMutation({
    mutationFn: () => api(editing === 'new' ? '/api/v1/attendances' : `/api/v1/attendances/${(editing as Attendance).id}`, {
      method: editing === 'new' ? 'POST' : 'PUT',
      ...jsonBody(editing === 'new' ? form : withoutIdentity(form)),
    }),
    onSuccess: () => {
      toast.success('Data kehadiran berhasil disimpan.')
      setEditing(null)
      queryClient.invalidateQueries({ queryKey: ['attendances'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: showError,
  })
  const reset = useMutation({
    mutationFn: () => api(`/api/v1/attendances/${removing?.id}`, { method: 'DELETE', ...jsonBody({ reason: deleteReason }) }),
    onSuccess: () => {
      toast.success('Catatan kehadiran dikembalikan ke status awal.')
      setRemoving(null)
      setDeleteReason('')
      queryClient.invalidateQueries({ queryKey: ['attendances'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: showError,
  })

  const openCreate = () => {
    setForm({ ...emptyForm, attendance_date: date, check_in_at: `${date}T08:00` })
    setEditing('new')
  }
  const openEdit = (attendance: Attendance) => {
    setForm({
      member_id: attendance.member.id,
      attendance_date: attendance.day.attendance_date,
      status: attendance.status === 'pending' ? 'present' : attendance.status,
      check_in_at: toLocalInput(attendance.check_in_at),
      check_out_at: toLocalInput(attendance.check_out_at),
      note: attendance.note ?? '',
      reason: '',
    })
    setEditing(attendance)
  }

  const day = query.data?.day
  return (
    <>
      <PageHeader title="Kehadiran" description="Lihat dan kelola absensi anggota berdasarkan tanggal." actions={canManage ? <Button icon={<Plus size={17} />} onClick={openCreate}>Tambah catatan</Button> : undefined} />
      <section className="panel attendance-toolbar-panel">
        <div className="panel__body toolbar attendance-toolbar">
          <label className="field compact-field"><span>Tanggal</span><input className="input" type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
          <label className="field compact-field"><span>Status</span><select className="select" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Semua status</option><option value="pending">Belum hadir</option><option value="present">Hadir</option><option value="permission">Izin</option><option value="leave">Cuti</option><option value="sick">Sakit</option><option value="official_duty">Dinas</option><option value="absent">Alpa</option></select></label>
          <label className="field compact-field search-field"><span>Cari anggota</span><div className="input-with-icon"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nama atau nomor anggota" /></div></label>
        </div>
        {day ? <div className="day-summary"><CalendarDays size={18} /><span><strong>{formatDate(`${day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</strong>{day.is_working_day ? ` · Masuk ${time(day.check_in_target_at)} · Pulang ${time(day.check_out_target_at)}` : ' · Hari libur'}</span><StatusBadge tone={query.data?.current_phase ? 'success' : day.is_working_day ? 'neutral' : 'warning'}>{query.data?.current_phase === 'check_in' ? 'Check-in aktif' : query.data?.current_phase === 'check_out' ? 'Check-out aktif' : day.is_working_day ? 'Jadwal tersimpan' : 'Libur'}</StatusBadge></div> : null}
      </section>

      <section className="panel attendance-table-panel">
        {query.data?.attendances.length ? <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Anggota</th><th>Status</th><th>Jejak hadir</th><th>Check-in</th><th>Ketepatan masuk</th><th>Check-out</th><th>Ketepatan pulang</th>{canManage ? <th>Aksi</th> : null}</tr></thead><tbody>{query.data.attendances.map((attendance) => <tr key={attendance.id}><td><span className="table-primary">{attendance.member.user?.name}</span><span className="table-secondary">{attendance.member.member_number} · {attendance.member.position ?? 'Anggota'}</span></td><td><AttendanceBadge status={attendance.status} /></td><td><PresenceSummary attendance={attendance} /></td><td>{attendance.check_in_at ? formatDate(attendance.check_in_at, 'HH.mm.ss') : '-'}</td><td>{attendance.check_in_status ? <StatusBadge tone={attendance.check_in_status === 'late' ? 'warning' : 'success'}>{attendance.check_in_status === 'late' ? 'Terlambat' : 'Tepat waktu'}</StatusBadge> : '-'}</td><td>{attendance.check_out_at ? formatDate(attendance.check_out_at, 'HH.mm.ss') : '-'}</td><td>{attendance.check_out_status ? <StatusBadge tone={attendance.check_out_status === 'early' ? 'warning' : 'info'}>{attendance.check_out_status === 'early' ? 'Pulang awal' : 'Selesai'}</StatusBadge> : '-'}</td>{canManage ? <td><div className="row-actions"><button className="icon-button" title="Edit" onClick={() => openEdit(attendance)}><Pencil size={16} /></button><button className="icon-button icon-button--danger" title="Hapus catatan" onClick={() => setRemoving(attendance)}><Trash2 size={16} /></button></div></td> : null}</tr>)}</tbody></table></div> : <EmptyState title="Tidak ada data" description="Belum ada anggota atau data tidak cocok dengan filter." />}
      </section>

      {editing ? <Modal title={editing === 'new' ? 'Tambah catatan kehadiran' : 'Edit catatan kehadiran'} onClose={() => setEditing(null)}><form onSubmit={(event) => { event.preventDefault(); save.mutate() }}><div className="form-grid">{editing === 'new' ? <><label className="field field--full"><span>Anggota</span><select className="select" value={form.member_id} onChange={(event) => setForm({ ...form, member_id: event.target.value })} required><option value="">Pilih anggota</option>{members.data?.map((member) => <option key={member.id} value={member.id}>{member.member_number} · {member.user?.name}</option>)}</select></label><label className="field field--full"><span>Tanggal</span><input className="input" type="date" value={form.attendance_date} onChange={(event) => setForm({ ...form, attendance_date: event.target.value, check_in_at: `${event.target.value}T08:00` })} required /></label></> : null}<label className="field field--full"><span>Status</span><select className="select" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as AttendanceStatus })}><option value="present">Hadir</option><option value="permission">Izin</option><option value="leave">Cuti</option><option value="sick">Sakit</option><option value="official_duty">Dinas</option><option value="absent">Alpa</option></select></label>{form.status === 'present' ? <><label className="field"><span>Waktu check-in</span><input className="input" type="datetime-local" value={form.check_in_at} onChange={(event) => setForm({ ...form, check_in_at: event.target.value })} required /></label><label className="field"><span>Waktu check-out</span><input className="input" type="datetime-local" value={form.check_out_at} onChange={(event) => setForm({ ...form, check_out_at: event.target.value })} /></label></> : null}<label className="field field--full"><span>Catatan</span><textarea className="textarea" value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} placeholder="Contoh: Surat izin diterima" /></label><label className="field field--full"><span>Alasan perubahan</span><textarea className="textarea" minLength={5} value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} placeholder="Alasan wajib dicatat dalam log" required /></label></div><div className="form-actions"><Button type="button" variant="secondary" onClick={() => setEditing(null)}>Batal</Button><Button type="submit" disabled={save.isPending}>{save.isPending ? 'Menyimpan...' : 'Simpan'}</Button></div></form></Modal> : null}

      {removing ? <ConfirmDialog title="Hapus catatan kehadiran?" description={`Data ${removing.member.user?.name} akan dikembalikan menjadi belum hadir atau alpa. Riwayat perubahan tetap tersimpan di Log.`} confirmLabel="Hapus catatan" confirmVariant="danger" disabled={reset.isPending || deleteReason.length < 5} onCancel={() => { setRemoving(null); setDeleteReason('') }} onConfirm={() => reset.mutate()}><label className="field"><span>Alasan penghapusan</span><textarea className="textarea" minLength={5} value={deleteReason} onChange={(event) => setDeleteReason(event.target.value)} required /></label></ConfirmDialog> : null}
    </>
  )
}

function AttendanceBadge({ status }: { status: AttendanceStatus }) {
  const labels = { pending: 'Belum hadir', present: 'Hadir', permission: 'Izin', leave: 'Cuti', sick: 'Sakit', official_duty: 'Dinas', absent: 'Alpa' }
  const tones = { pending: 'neutral', present: 'success', permission: 'info', leave: 'info', sick: 'warning', official_duty: 'info', absent: 'danger' } as const
  return <StatusBadge tone={tones[status]}>{status === 'present' ? <CheckCircle2 size={13} /> : status === 'pending' ? <Clock3 size={13} /> : null}{labels[status]}</StatusBadge>
}

function PresenceSummary({ attendance }: { attendance: Attendance }) {
  if (attendance.presence_summary.is_partial_absence) {
    return <span className="table-secondary">{attendance.presence_summary.label}</span>
  }
  if (attendance.attendance_request?.reviewer) {
    return <span className="table-secondary">Disetujui {attendance.attendance_request.reviewer.name}</span>
  }
  return <span className="table-secondary">-</span>
}

function withoutIdentity(value: typeof emptyForm) {
  return { status: value.status, check_in_at: value.check_in_at, check_out_at: value.check_out_at, note: value.note, reason: value.reason }
}

function dateInMakassar(date: Date) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date)
}

function toLocalInput(value: string | null) {
  return value ? new Date(value).toLocaleString('sv-SE', { timeZone: 'Asia/Makassar' }).slice(0, 16).replace(' ', 'T') : ''
}

function time(value: string | null) {
  return value ? `${formatDate(value, 'HH.mm')} WITA` : '-'
}

function showError(error: unknown) {
  toast.error(error instanceof ApiError ? error.message : 'Data kehadiran gagal diproses.')
}
