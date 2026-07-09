import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Clock3, MessageCircle, Pencil, Plus, Send, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Navigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Button, ConfirmDialog, EmptyState, FormErrorSummary, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import type { AttendanceDevice, AttendanceException, AttendanceSettings, WeeklySchedule, WhatsAppNotificationSettings } from '../../types'

const dayNames = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']
type ScheduleForm = Omit<WeeklySchedule, 'id' | 'weekday'>
const defaultTimes: ScheduleForm = { is_working_day: true, check_in_time: '08:00', check_in_before_minutes: 30, check_in_after_minutes: 30, check_out_time: '16:00', check_out_before_minutes: 30, check_out_after_minutes: 30 }
type ExceptionForm = ScheduleForm & { attendance_date: string; note: string; attendance_device_ids: string[] }
const defaultExceptionForm = (): ExceptionForm => ({ ...defaultTimes, attendance_date: dateInMakassar(new Date()), note: '', attendance_device_ids: [] })

export function SettingsPage() {
  const { user } = useAuth()
  const isAdmin = user?.roles.includes('super_admin') ?? false
  const [params, setParams] = useSearchParams()
  const tabParam = params.get('tab')
  if (!isAdmin || tabParam === 'account') {
    return <Navigate to="/admin/accounts?tab=me" replace />
  }

  const tab = tabParam === 'exceptions' ? 'exceptions' : tabParam === 'whatsapp' ? 'whatsapp' : 'schedule'
  const setTab = (value: 'schedule' | 'exceptions' | 'whatsapp') => {
    setParams(value === 'schedule' ? {} : { tab: value }, { replace: true })
  }

  return (
    <>
      <PageHeader title="Pengaturan" description="Atur jadwal absensi dan pengecualian tanggal." />
      <div className="tabs settings-tabs">
        <button className={tab === 'schedule' ? 'active' : ''} onClick={() => setTab('schedule')}>Jadwal Mingguan</button>
        <button className={tab === 'exceptions' ? 'active' : ''} onClick={() => setTab('exceptions')}>Pengecualian Tanggal</button>
        <button className={tab === 'whatsapp' ? 'active' : ''} onClick={() => setTab('whatsapp')}>Notifikasi WhatsApp</button>
      </div>
      {tab === 'schedule' ? <WeeklySettings /> : null}
      {tab === 'exceptions' ? <ExceptionSettings /> : null}
      {tab === 'whatsapp' ? <WhatsAppSettings /> : null}
    </>
  )
}

function WeeklySettings() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<WeeklySchedule | null>(null)
  const [form, setForm] = useState<ScheduleForm>(defaultTimes)
  const settings = useSettings()
  const save = useMutation({
    mutationFn: () => api(`/api/v1/attendance-settings/weekly/${editing?.weekday}`, { method: 'PUT', ...jsonBody(form) }),
    onSuccess: () => { toast.success('Jadwal mingguan berhasil diperbarui.'); setEditing(null); queryClient.invalidateQueries({ queryKey: ['attendance-settings'] }); queryClient.invalidateQueries({ queryKey: ['dashboard'] }) },
    onError: showError,
  })
  const open = (schedule: WeeklySchedule) => {
    setEditing(schedule)
    setForm({ ...schedule, check_in_time: shortTime(schedule.check_in_time), check_out_time: shortTime(schedule.check_out_time) })
  }

  return (
    <section className="panel schedule-panel">
      <header className="panel__header"><div><h2>Jadwal mingguan</h2><p>Perubahan berlaku untuk hari yang belum dimulai.</p></div></header>
      <div className="weekly-list">
        {settings.data?.weekly.map((schedule) => <article key={schedule.id} className="weekly-row"><div className="weekly-day"><strong>{dayNames[schedule.weekday - 1]}</strong><StatusBadge tone={schedule.is_working_day ? 'success' : 'neutral'}>{schedule.is_working_day ? 'Hari kerja' : 'Libur'}</StatusBadge></div>{schedule.is_working_day ? <div className="weekly-times"><span><Clock3 size={17} /><small>Masuk</small><strong>{shortTime(schedule.check_in_time)} <em>± {schedule.check_in_before_minutes}/{schedule.check_in_after_minutes} mnt</em></strong></span><span><Clock3 size={17} /><small>Pulang</small><strong>{shortTime(schedule.check_out_time)} <em>± {schedule.check_out_before_minutes}/{schedule.check_out_after_minutes} mnt</em></strong></span></div> : <p className="muted">QR tidak ditampilkan pada hari ini.</p>}<button className="icon-button" title={`Edit ${dayNames[schedule.weekday - 1]}`} onClick={() => open(schedule)}><Pencil size={17} /></button></article>)}
      </div>
      {editing ? <Modal title={`Atur ${dayNames[editing.weekday - 1]}`} onClose={() => setEditing(null)}><ScheduleFormFields form={form} onChange={setForm} onSubmit={() => save.mutate()} onCancel={() => setEditing(null)} busy={save.isPending} /></Modal> : null}
    </section>
  )
}

function ExceptionSettings() {
  const queryClient = useQueryClient()
  const settings = useSettings()
  const devices = useQuery({ queryKey: ['attendance-devices'], queryFn: () => api<AttendanceDevice[]>('/api/v1/attendance-devices') })
  const [editing, setEditing] = useState<AttendanceException | 'new' | null>(null)
  const [removing, setRemoving] = useState<AttendanceException | null>(null)
  const [form, setForm] = useState<ExceptionForm>(() => defaultExceptionForm())
  const selectableDevices = (devices.data ?? []).filter((device) => device.status !== 'revoked')
  const missingDeviceSelection = form.is_working_day && form.attendance_device_ids.length === 0
  const updateForm = (value: ExceptionForm) => setForm(value.is_working_day ? value : { ...value, attendance_device_ids: [] })
  const save = useMutation({
    mutationFn: () => api(editing === 'new' ? '/api/v1/attendance-exceptions' : `/api/v1/attendance-exceptions/${(editing as AttendanceException).id}`, { method: editing === 'new' ? 'POST' : 'PUT', ...jsonBody(form) }),
    onSuccess: () => { toast.success('Pengecualian tanggal berhasil disimpan.'); setEditing(null); queryClient.invalidateQueries({ queryKey: ['attendance-settings'] }) },
    onError: showError,
  })
  const remove = useMutation({
    mutationFn: () => api(`/api/v1/attendance-exceptions/${removing?.id}`, { method: 'DELETE' }),
    onSuccess: () => { toast.success('Pengecualian tanggal dihapus.'); setRemoving(null); queryClient.invalidateQueries({ queryKey: ['attendance-settings'] }) },
    onError: showError,
  })
  const openCreate = () => { setForm(defaultExceptionForm()); setEditing('new') }
  const openEdit = (item: AttendanceException) => { setForm({ ...item, check_in_time: shortTime(item.check_in_time), check_out_time: shortTime(item.check_out_time), attendance_device_ids: item.device_ids ?? [] }); setEditing(item) }

  return (
    <section className="panel schedule-panel">
      <header className="panel__header"><div><h2>Pengecualian tanggal</h2><p>Atur hari libur atau jadwal khusus.</p></div><Button icon={<Plus size={17} />} onClick={openCreate}>Tambah tanggal</Button></header>
      <div className="exception-list">
        {settings.data?.exceptions.length ? settings.data.exceptions.map((item) => <article key={item.id}><span className="resource-icon"><CalendarOff size={20} /></span><div><strong>{formatDate(`${item.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</strong><p>{item.note}</p>{item.is_working_day ? <p>Gawai: {deviceScopeLabel(item)}</p> : null}</div><StatusBadge tone={item.is_working_day ? 'info' : 'warning'}>{item.is_working_day ? `${shortTime(item.check_in_time)}–${shortTime(item.check_out_time)}` : 'Libur'}</StatusBadge><div className="row-actions"><button className="icon-button" title="Edit" onClick={() => openEdit(item)}><Pencil size={16} /></button><button className="icon-button icon-button--danger" title="Hapus" onClick={() => setRemoving(item)}><Trash2 size={16} /></button></div></article>) : <EmptyState title="Belum ada pengecualian" description="Jadwal mingguan digunakan untuk semua tanggal." />}
      </div>
      {editing ? <Modal title={editing === 'new' ? 'Tambah pengecualian' : 'Edit pengecualian'} onClose={() => setEditing(null)}><form onSubmit={(event) => { event.preventDefault(); if (!missingDeviceSelection) save.mutate() }}><div className="form-grid"><label className="field field--full"><span>Tanggal</span><input className="input" type="date" value={form.attendance_date} onChange={(event) => setForm({ ...form, attendance_date: event.target.value })} required /></label><label className="field field--full"><span>Catatan</span><input className="input" value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} placeholder="Contoh: Rakor kader di aula" required /></label></div><ScheduleFields form={form} onChange={updateForm} />{form.is_working_day ? <ExceptionDeviceSelector devices={selectableDevices} selectedIds={form.attendance_device_ids} loading={devices.isLoading} onChange={(attendance_device_ids) => setForm({ ...form, attendance_device_ids })} /> : null}<FormErrorSummary error={save.error} /><div className="form-actions"><Button type="button" variant="secondary" onClick={() => setEditing(null)}>Batal</Button><Button type="submit" disabled={save.isPending || missingDeviceSelection}>{save.isPending ? 'Menyimpan...' : 'Simpan'}</Button></div></form></Modal> : null}
      {removing ? <ConfirmDialog title="Hapus pengecualian?" description={`Tanggal ${formatDate(`${removing.attendance_date}T00:00:00+08:00`, 'dd MMMM yyyy')} akan kembali mengikuti jadwal mingguan.`} confirmLabel="Hapus" confirmVariant="danger" disabled={remove.isPending} onCancel={() => setRemoving(null)} onConfirm={() => remove.mutate()} /> : null}
    </section>
  )
}

function ExceptionDeviceSelector({ devices, selectedIds, loading, onChange }: { devices: AttendanceDevice[]; selectedIds: string[]; loading: boolean; onChange: (ids: string[]) => void }) {
  const toggle = (id: string) => onChange(selectedIds.includes(id) ? selectedIds.filter((selectedId) => selectedId !== id) : [...selectedIds, id])

  return (
    <div className="field field--full exception-device-field">
      <span>Gawai yang diizinkan</span>
      <small>Pilih layar/gawai yang memang dipakai untuk jadwal khusus atau agenda hari ini.</small>
      {loading ? <p className="muted">Memuat daftar gawai...</p> : null}
      {!loading && devices.length === 0 ? <p className="field-error">Belum ada gawai yang bisa dipilih. Tambahkan gawai terlebih dahulu dari menu Gawai.</p> : null}
      {!loading && devices.length > 0 ? <div className="device-check-list">{devices.map((device) => <label key={device.id} className="device-check"><input type="checkbox" checked={selectedIds.includes(device.id)} onChange={() => toggle(device.id)} /><span><strong>{device.name}</strong><small>{device.code}{device.location ? ` · ${device.location}` : ''} · {deviceStatusLabel(device.status)}</small></span></label>)}</div> : null}
      {!loading && devices.length > 0 && selectedIds.length === 0 ? <p className="field-error">Pilih minimal satu gawai untuk jadwal khusus.</p> : null}
    </div>
  )
}

type WhatsAppForm = {
  enabled: boolean
  send_url: string
  status_url: string
  auth_mode: WhatsAppNotificationSettings['auth_mode']
  auth_username: string
  auth_password: string
  auth_header_name: string
  auth_header_value: string
  auth_bearer_token: string
  footer: string
  public_base_url: string
}

const defaultWhatsAppForm: WhatsAppForm = {
  enabled: false,
  send_url: '',
  status_url: '',
  auth_mode: 'none',
  auth_username: '',
  auth_password: '',
  auth_header_name: '',
  auth_header_value: '',
  auth_bearer_token: '',
  footer: 'Absensi TP PKK Balangan',
  public_base_url: '',
}

function WhatsAppSettings() {
  const queryClient = useQueryClient()
  const settings = useQuery({ queryKey: ['whatsapp-settings'], queryFn: () => api<WhatsAppNotificationSettings>('/api/v1/admin/settings/whatsapp') })
  const [form, setForm] = useState<WhatsAppForm>(defaultWhatsAppForm)
  const [testOpen, setTestOpen] = useState(false)
  const [testPhone, setTestPhone] = useState('')

  useEffect(() => {
    if (!settings.data) return
    setForm({
      enabled: settings.data.enabled,
      send_url: '',
      status_url: '',
      auth_mode: settings.data.auth_mode,
      auth_username: settings.data.auth_username ?? '',
      auth_password: '',
      auth_header_name: settings.data.auth_header_name ?? '',
      auth_header_value: '',
      auth_bearer_token: '',
      footer: settings.data.footer || 'Absensi TP PKK Balangan',
      public_base_url: settings.data.public_base_url ?? '',
    })
  }, [settings.data])

  const save = useMutation({
    mutationFn: () => api<WhatsAppNotificationSettings>('/api/v1/admin/settings/whatsapp', { method: 'PUT', ...jsonBody(whatsAppPayload(form)) }),
    onSuccess: () => {
      toast.success('Pengaturan WhatsApp berhasil disimpan.')
      queryClient.invalidateQueries({ queryKey: ['whatsapp-settings'] })
    },
    onError: showError,
  })
  const test = useMutation({
    mutationFn: () => api<{ status: string; message: string }>('/api/v1/admin/settings/whatsapp/test', { method: 'POST', ...jsonBody({ phone: testPhone }) }),
    onSuccess: (result) => {
      toast.success(result.message)
      setTestOpen(false)
      setTestPhone('')
    },
    onError: showError,
  })

  return (
    <section className="panel whatsapp-panel">
      <header className="panel__header">
        <div>
          <h2>Notifikasi WhatsApp</h2>
          <p>Konfigurasi gateway DRNet untuk notifikasi pengajuan dan approval publik.</p>
        </div>
        <StatusBadge tone={settings.data?.enabled_and_configured ? 'success' : settings.data?.enabled ? 'warning' : 'neutral'}>
          {settings.data?.enabled_and_configured ? 'Aktif' : settings.data?.enabled ? 'Perlu konfigurasi' : 'Nonaktif'}
        </StatusBadge>
      </header>
      <form className="panel__body whatsapp-form" onSubmit={(event) => { event.preventDefault(); save.mutate() }}>
        <label className="checkbox field--full"><input type="checkbox" checked={form.enabled} onChange={(event) => setForm({ ...form, enabled: event.target.checked })} /><span>Aktifkan notifikasi WhatsApp</span></label>
        <div className="form-grid">
          <label className="field field--full"><span>POST Send URL</span><input className="input" type="url" value={form.send_url} onChange={(event) => setForm({ ...form, send_url: event.target.value })} placeholder={settings.data?.send_url_configured ? 'Endpoint tersimpan. Isi hanya jika ingin mengganti.' : 'https://gateway.drnet.biz.id/ext/secret/wa'} required={form.enabled && !settings.data?.send_url_configured} />{settings.data?.send_url_preview ? <small>Tersimpan: {settings.data.send_url_preview}</small> : <small>Secret URL disimpan terenkripsi di backend dan tidak ditampilkan penuh.</small>}</label>
          <label className="field field--full"><span>Status URL (opsional)</span><input className="input" type="url" value={form.status_url} onChange={(event) => setForm({ ...form, status_url: event.target.value })} placeholder={settings.data?.status_url_configured ? 'Status endpoint tersimpan. Kosongkan jika tidak diganti.' : 'Otomatis: /status'} />{settings.data?.status_url_preview ? <small>Tersimpan: {settings.data.status_url_preview}</small> : null}</label>
          <label className="field"><span>URL publik aplikasi</span><input className="input" type="url" value={form.public_base_url} onChange={(event) => setForm({ ...form, public_base_url: event.target.value })} placeholder="https://absensi.example.go.id" required={form.enabled} /><small>Dipakai untuk link approve/reject di WhatsApp. Jangan gunakan localhost.</small></label>
          <label className="field"><span>Footer pesan</span><input className="input" value={form.footer} onChange={(event) => setForm({ ...form, footer: event.target.value })} /></label>
          <label className="field"><span>Auth tambahan</span><select className="select" value={form.auth_mode} onChange={(event) => setForm({ ...form, auth_mode: event.target.value as WhatsAppForm['auth_mode'] })}><option value="none">Tidak ada</option><option value="basic">Basic Auth</option><option value="header">Custom Header</option><option value="jwt">Bearer/JWT</option></select></label>
          {form.auth_mode === 'basic' ? <><label className="field"><span>Basic username</span><input className="input" value={form.auth_username} onChange={(event) => setForm({ ...form, auth_username: event.target.value })} /></label><label className="field"><span>Basic password</span><input className="input" type="password" value={form.auth_password} onChange={(event) => setForm({ ...form, auth_password: event.target.value })} placeholder={settings.data?.auth_password_configured ? 'Password tersimpan. Isi jika diganti.' : ''} /></label></> : null}
          {form.auth_mode === 'header' ? <><label className="field"><span>Nama header</span><input className="input" value={form.auth_header_name} onChange={(event) => setForm({ ...form, auth_header_name: event.target.value })} placeholder="X-API-Key" /></label><label className="field"><span>Nilai header</span><input className="input" type="password" value={form.auth_header_value} onChange={(event) => setForm({ ...form, auth_header_value: event.target.value })} placeholder={settings.data?.auth_header_value_configured ? 'Token tersimpan. Isi jika diganti.' : ''} /></label></> : null}
          {form.auth_mode === 'jwt' ? <label className="field field--full"><span>Bearer/JWT token</span><input className="input" type="password" value={form.auth_bearer_token} onChange={(event) => setForm({ ...form, auth_bearer_token: event.target.value })} placeholder={settings.data?.auth_bearer_token_configured ? 'Token tersimpan. Isi jika diganti.' : ''} /></label> : null}
        </div>
        <div className="whatsapp-guide"><MessageCircle size={18} /><span>Pesan approval memakai tombol URL approve/reject dan tombol copy kode. Link publik tetap membutuhkan kode unik penerima.</span></div>
        <FormErrorSummary error={save.error} />
        <div className="form-actions"><Button type="button" variant="secondary" icon={<Send size={16} />} onClick={() => setTestOpen(true)}>Test kirim</Button><Button type="submit" disabled={save.isPending}>{save.isPending ? 'Menyimpan...' : 'Simpan pengaturan'}</Button></div>
      </form>
      {testOpen ? <Modal title="Test kirim WhatsApp" onClose={() => setTestOpen(false)}><form onSubmit={(event) => { event.preventDefault(); test.mutate() }}><label className="field"><span>Nomor tujuan</span><input className="input" value={testPhone} onChange={(event) => setTestPhone(event.target.value)} placeholder="628123456789" required /><small>Gunakan format internasional tanpa tanda plus.</small></label><FormErrorSummary error={test.error} /><div className="form-actions"><Button type="button" variant="secondary" onClick={() => setTestOpen(false)}>Batal</Button><Button type="submit" disabled={test.isPending}>{test.isPending ? 'Mengirim...' : 'Kirim test'}</Button></div></form></Modal> : null}
    </section>
  )
}

function whatsAppPayload(form: WhatsAppForm) {
  const payload: Record<string, unknown> = {
    enabled: form.enabled,
    auth_mode: form.auth_mode,
    auth_username: form.auth_username || null,
    auth_header_name: form.auth_header_name || null,
    footer: form.footer,
    public_base_url: form.public_base_url || null,
  }
  if (form.send_url.trim()) payload.send_url = form.send_url.trim()
  if (form.status_url.trim()) payload.status_url = form.status_url.trim()
  if (form.auth_password.trim()) payload.auth_password = form.auth_password
  if (form.auth_header_value.trim()) payload.auth_header_value = form.auth_header_value
  if (form.auth_bearer_token.trim()) payload.auth_bearer_token = form.auth_bearer_token

  return payload
}

function ScheduleFormFields({ form, onChange, onSubmit, onCancel, busy }: { form: ScheduleForm; onChange: (value: ScheduleForm) => void; onSubmit: () => void; onCancel: () => void; busy: boolean }) {
  return <form onSubmit={(event) => { event.preventDefault(); onSubmit() }}><ScheduleFields form={form} onChange={onChange} /><div className="form-actions"><Button type="button" variant="secondary" onClick={onCancel}>Batal</Button><Button type="submit" disabled={busy}>Simpan jadwal</Button></div></form>
}

function ScheduleFields<T extends ScheduleForm>({ form, onChange }: { form: T; onChange: (value: T) => void }) {
  return <div className="form-grid schedule-fields"><label className="checkbox field--full"><input type="checkbox" checked={form.is_working_day} onChange={(event) => onChange({ ...form, is_working_day: event.target.checked } as T)} /><span>Aktifkan absensi pada hari ini</span></label>{form.is_working_day ? <><label className="field"><span>Target masuk</span><input className="input" type="time" value={form.check_in_time ?? ''} onChange={(event) => onChange({ ...form, check_in_time: event.target.value } as T)} required /></label><ToleranceFields prefix="check_in" form={form} onChange={onChange} /><label className="field"><span>Target pulang</span><input className="input" type="time" value={form.check_out_time ?? ''} onChange={(event) => onChange({ ...form, check_out_time: event.target.value } as T)} required /></label><ToleranceFields prefix="check_out" form={form} onChange={onChange} /></> : null}</div>
}

function ToleranceFields<T extends ScheduleForm>({ prefix, form, onChange }: { prefix: 'check_in' | 'check_out'; form: T; onChange: (value: T) => void }) {
  const before = `${prefix}_before_minutes` as const
  const after = `${prefix}_after_minutes` as const
  return <><label className="field"><span>Toleransi sebelum (menit)</span><input className="input" type="number" min="0" max="720" value={form[before]} onChange={(event) => onChange({ ...form, [before]: Number(event.target.value) } as T)} /></label><label className="field"><span>Toleransi sesudah (menit)</span><input className="input" type="number" min="0" max="720" value={form[after]} onChange={(event) => onChange({ ...form, [after]: Number(event.target.value) } as T)} /></label></>
}

function useSettings() {
  return useQuery({ queryKey: ['attendance-settings'], queryFn: () => api<AttendanceSettings>('/api/v1/attendance-settings') })
}

function shortTime(value: string | null) { return value ? value.slice(0, 5) : '-' }
function dateInMakassar(date: Date) { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date) }
function showError(error: unknown) { toast.error(apiErrorMessage(error, 'Perubahan gagal disimpan.')) }
function deviceScopeLabel(item: AttendanceException) {
  if (!item.device_ids?.length) return 'Semua gawai aktif (data lama)'
  return item.devices?.length ? item.devices.map((device) => device.name).join(', ') : `${item.device_ids.length} gawai`
}
function deviceStatusLabel(status: AttendanceDevice['status']) {
  return status === 'active' ? 'aktif' : status === 'pending' ? 'menunggu aktivasi' : status === 'inactive' ? 'nonaktif' : 'dicabut'
}
