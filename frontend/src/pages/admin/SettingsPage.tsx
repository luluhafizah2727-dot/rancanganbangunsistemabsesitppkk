import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Clock3, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { AvatarEditor } from '../../components/AvatarEditor'
import { Button, ConfirmDialog, EmptyState, FormErrorSummary, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import type { AttendanceDevice, AttendanceException, AttendanceSettings, WeeklySchedule } from '../../types'

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
  const tab = tabParam === 'account' ? 'account' : tabParam === 'exceptions' && isAdmin ? 'exceptions' : isAdmin ? 'schedule' : 'account'
  const setTab = (value: 'schedule' | 'exceptions' | 'account') => {
    setParams(value === 'schedule' ? {} : { tab: value }, { replace: true })
  }

  return (
    <>
      <PageHeader title="Pengaturan" description="Atur jadwal absensi dan akun Anda." />
      <div className="tabs settings-tabs">
        {isAdmin ? <><button className={tab === 'schedule' ? 'active' : ''} onClick={() => setTab('schedule')}>Jadwal Mingguan</button><button className={tab === 'exceptions' ? 'active' : ''} onClick={() => setTab('exceptions')}>Pengecualian Tanggal</button></> : null}
        <button className={tab === 'account' ? 'active' : ''} onClick={() => setTab('account')}>Akun Saya</button>
      </div>
      {tab === 'schedule' ? <WeeklySettings /> : null}
      {tab === 'exceptions' ? <ExceptionSettings /> : null}
      {tab === 'account' ? <AccountSettings /> : null}
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

function AccountSettings() {
  const { user, refresh } = useAuth()
  const [profile, setProfile] = useState({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' })
  const [password, setPassword] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [savingProfile, setSavingProfile] = useState(false)
  const [savingPassword, setSavingPassword] = useState(false)
  const [profileError, setProfileError] = useState<unknown>(null)
  const [passwordError, setPasswordError] = useState<unknown>(null)
  if (!user) return null

  const saveProfile = async (event: FormEvent) => { event.preventDefault(); setSavingProfile(true); setProfileError(null); try { await api('/api/v1/profile', { method: 'PUT', ...jsonBody(profile) }); await refresh(); toast.success('Profil akun berhasil diperbarui.') } catch (error) { setProfileError(error); showError(error) } finally { setSavingProfile(false) } }
  const savePassword = async (event: FormEvent) => { event.preventDefault(); setSavingPassword(true); setPasswordError(null); try { await api('/api/v1/auth/password', { method: 'PUT', ...jsonBody(password) }); await refresh(); setPassword({ current_password: '', password: '', password_confirmation: '' }); toast.success('Password berhasil diperbarui.') } catch (error) { setPasswordError(error); showError(error) } finally { setSavingPassword(false) } }

  return <div className="settings-grid"><section className="panel"><header className="panel__header"><h2>Profil akun</h2></header><form className="panel__body account-form" onSubmit={saveProfile}><AvatarEditor user={user} onUpdated={refresh} /><label className="field"><span>ID pengguna</span><input className="input" value={user.login_id} disabled /></label><label className="field"><span>Nama</span><input className="input" value={profile.name} onChange={(event) => setProfile({ ...profile, name: event.target.value })} required /></label><label className="field"><span>Email</span><input className="input" type="email" value={profile.email} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /></label><label className="field"><span>Nomor telepon</span><input className="input" value={profile.phone} onChange={(event) => setProfile({ ...profile, phone: event.target.value })} /></label><FormErrorSummary error={profileError} /><div className="form-actions"><Button type="submit" disabled={savingProfile}>{savingProfile ? 'Menyimpan...' : 'Simpan profil'}</Button></div></form></section><section className="panel"><header className="panel__header"><h2>Ubah password</h2></header><form className="panel__body account-form" onSubmit={savePassword}><label className="field"><span>Password saat ini</span><input className="input" type="password" value={password.current_password} onChange={(event) => setPassword({ ...password, current_password: event.target.value })} required /></label><label className="field"><span>Password baru</span><input className="input" type="password" minLength={8} value={password.password} onChange={(event) => setPassword({ ...password, password: event.target.value })} required /><small>Minimal 8 karakter.</small></label><label className="field"><span>Konfirmasi password</span><input className="input" type="password" value={password.password_confirmation} onChange={(event) => setPassword({ ...password, password_confirmation: event.target.value })} required /></label><FormErrorSummary error={passwordError} /><div className="form-actions"><Button type="submit" disabled={savingPassword}>{savingPassword ? 'Menyimpan...' : 'Simpan password'}</Button></div></form></section></div>
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
