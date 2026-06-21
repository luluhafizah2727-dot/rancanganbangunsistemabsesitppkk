import { ShieldCheck } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { Avatar } from '../../components/Avatar'
import { AvatarEditor } from '../../components/AvatarEditor'
import { Button, FormErrorSummary } from '../../components/ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'

export function MemberProfilePage() {
  const { user, refresh } = useAuth()
  const [profile, setProfile] = useState({
    email: user?.email ?? '',
    phone: user?.phone ?? '',
    address: user?.member?.address ?? '',
  })
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
      toast.success('Profil berhasil diperbarui.')
    } catch (error) {
      setProfileError(error)
      showError(error, 'Profil gagal diperbarui.')
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
      showError(error, 'Password gagal diperbarui.')
    } finally {
      setSavingPassword(false)
    }
  }

  return (
    <div className="member-page">
      <header className="member-section-title">
        <h1>Profil</h1>
        <p>Periksa data akun dan perbarui informasi yang dapat Anda kelola.</p>
      </header>

      <section className="profile-card">
        <Avatar name={user.name} src={user.avatar_url} size="large" />
        <div>
          <h2>{user.name}</h2>
          <p>{user.member?.member_number}</p>
          <small>{user.member?.position ?? 'Anggota'} · {user.member?.department ?? 'TP PKK Balangan'}</small>
        </div>
      </section>

      <section className="member-card">
        <AvatarEditor user={user} onUpdated={refresh} showPreview={false} />
      </section>

      <section className="member-card">
        <div className="member-card__header"><span>Informasi kontak</span></div>
        <form className="member-form" onSubmit={saveProfile}>
          <label className="field"><span>Email</span><input className="input" type="email" value={profile.email} onChange={(event) => setProfile({ ...profile, email: event.target.value })} /></label>
          <label className="field"><span>Nomor telepon</span><input className="input" value={profile.phone} onChange={(event) => setProfile({ ...profile, phone: event.target.value })} /></label>
          <label className="field"><span>Alamat</span><textarea className="textarea" value={profile.address} onChange={(event) => setProfile({ ...profile, address: event.target.value })} /></label>
          <FormErrorSummary error={profileError} />
          <Button type="submit" disabled={savingProfile}>{savingProfile ? 'Menyimpan...' : 'Simpan profil'}</Button>
        </form>
      </section>

      <section className="member-card profile-details-mobile">
        <h2>Data keanggotaan</h2>
        <dl>
          <div><dt>ID login</dt><dd>{user.login_id}</dd></div>
          <div><dt>Jabatan</dt><dd>{user.member?.position ?? '-'}</dd></div>
          <div><dt>Bidang / kelompok</dt><dd>{user.member?.department ?? '-'}</dd></div>
          <div><dt>Status</dt><dd>{user.status === 'active' ? 'Aktif' : 'Tidak aktif'}</dd></div>
        </dl>
      </section>

      <section className="member-card">
        <div className="member-card__header"><span><ShieldCheck size={20} /> Ubah password</span></div>
        <form className="member-form" onSubmit={savePassword}>
          <label className="field"><span>Password saat ini</span><input className="input" type="password" value={password.current_password} onChange={(event) => setPassword({ ...password, current_password: event.target.value })} required /></label>
          <label className="field"><span>Password baru</span><input className="input" type="password" minLength={8} value={password.password} onChange={(event) => setPassword({ ...password, password: event.target.value })} required /><small>Minimal 8 karakter.</small></label>
          <label className="field"><span>Konfirmasi password</span><input className="input" type="password" value={password.password_confirmation} onChange={(event) => setPassword({ ...password, password_confirmation: event.target.value })} required /></label>
          <FormErrorSummary error={passwordError} />
          <Button type="submit" disabled={savingPassword}>{savingPassword ? 'Menyimpan...' : 'Simpan password'}</Button>
        </form>
      </section>
    </div>
  )
}

function showError(error: unknown, fallback: string) {
  toast.error(apiErrorMessage(error, fallback))
}
