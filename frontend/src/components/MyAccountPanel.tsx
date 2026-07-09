import { useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { api, apiErrorMessage, jsonBody } from '../lib/api'
import { useAuth } from '../lib/auth'
import { AvatarEditor } from './AvatarEditor'
import { Button, FormErrorSummary } from './ui'

export function MyAccountPanel() {
  const { user, refresh } = useAuth()
  const staffAccount = user?.roles.some((role) => role === 'super_admin' || role === 'operator') ?? false
  const [profile, setProfile] = useState({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', receive_wa_notifications: user?.receive_wa_notifications ?? false })
  const [password, setPassword] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [savingProfile, setSavingProfile] = useState(false)
  const [savingPassword, setSavingPassword] = useState(false)
  const [profileError, setProfileError] = useState<unknown>(null)
  const [passwordError, setPasswordError] = useState<unknown>(null)
  if (!user) return null

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault()
    setSavingProfile(true)
    setProfileError(null)
    try {
      await api('/api/v1/profile', { method: 'PUT', ...jsonBody(profile) })
      await refresh()
      toast.success('Profil akun berhasil diperbarui.')
    } catch (error) {
      setProfileError(error)
      toast.error(apiErrorMessage(error, 'Perubahan gagal disimpan.'))
    } finally {
      setSavingProfile(false)
    }
  }

  const savePassword = async (event: FormEvent) => {
    event.preventDefault()
    setSavingPassword(true)
    setPasswordError(null)
    try {
      await api('/api/v1/auth/password', { method: 'PUT', ...jsonBody(password) })
      await refresh()
      setPassword({ current_password: '', password: '', password_confirmation: '' })
      toast.success('Password berhasil diperbarui.')
    } catch (error) {
      setPasswordError(error)
      toast.error(apiErrorMessage(error, 'Perubahan gagal disimpan.'))
    } finally {
      setSavingPassword(false)
    }
  }

  return (
    <div className="settings-grid">
      <section className="panel">
        <header className="panel__header"><h2>Profil akun</h2></header>
        <form className="panel__body account-form" onSubmit={saveProfile}>
          <AvatarEditor user={user} onUpdated={refresh} />
          <label className="field"><span>ID pengguna</span><input className="input" value={user.login_id} disabled /></label>
          <label className="field"><span>Nama</span><input className="input" value={profile.name} onChange={(event) => setProfile({ ...profile, name: event.target.value })} required /></label>
          <label className="field"><span>Email</span><input className="input" type="email" value={profile.email} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /></label>
          <label className="field"><span>Nomor telepon</span><input className="input" value={profile.phone} onChange={(event) => setProfile({ ...profile, phone: event.target.value })} /></label>
          {staffAccount ? <label className="checkbox notification-checkbox"><input type="checkbox" checked={profile.receive_wa_notifications} onChange={(event) => setProfile({ ...profile, receive_wa_notifications: event.target.checked })} /><span>Terima notifikasi WhatsApp untuk permohonan absensi</span></label> : null}
          {staffAccount && profile.receive_wa_notifications && !profile.phone.trim() ? <p className="field-error">Isi nomor telepon agar notifikasi WhatsApp bisa dikirim.</p> : null}
          <FormErrorSummary error={profileError} />
          <div className="form-actions"><Button type="submit" disabled={savingProfile}>{savingProfile ? 'Menyimpan...' : 'Simpan profil'}</Button></div>
        </form>
      </section>
      <section className="panel">
        <header className="panel__header"><h2>Ubah password</h2></header>
        <form className="panel__body account-form" onSubmit={savePassword}>
          <label className="field"><span>Password saat ini</span><input className="input" type="password" value={password.current_password} onChange={(event) => setPassword({ ...password, current_password: event.target.value })} required /></label>
          <label className="field"><span>Password baru</span><input className="input" type="password" minLength={8} value={password.password} onChange={(event) => setPassword({ ...password, password: event.target.value })} required /><small>Minimal 8 karakter.</small></label>
          <label className="field"><span>Konfirmasi password</span><input className="input" type="password" value={password.password_confirmation} onChange={(event) => setPassword({ ...password, password_confirmation: event.target.value })} required /></label>
          <FormErrorSummary error={passwordError} />
          <div className="form-actions"><Button type="submit" disabled={savingPassword}>{savingPassword ? 'Menyimpan...' : 'Simpan password'}</Button></div>
        </form>
      </section>
    </div>
  )
}
