import { zodResolver } from '@hookform/resolvers/zod'
import { Eye, EyeOff, LockKeyhole, ShieldCheck, UserRound } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { z } from 'zod'
import { BrandMark } from '../components/BrandMark'
import { Button } from '../components/ui'
import { useAuth } from '../lib/auth'
import { ApiError } from '../lib/api'

const schema = z.object({
  loginId: z.string().min(1, 'ID pengguna wajib diisi.'),
  password: z.string().min(1, 'Password wajib diisi.'),
  remember: z.boolean(),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const { login, user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { loginId: '', password: '', remember: false } })

  if (user) {
    return <Navigate to={user.roles.includes('member') ? '/member/home' : '/admin/dashboard'} replace />
  }

  const onSubmit = async (values: FormValues) => {
    setSubmitting(true)
    try {
      const authenticated = await login(values.loginId, values.password, values.remember)
      const requested = (location.state as { from?: string } | null)?.from
      navigate(requested || (authenticated.roles.includes('member') ? '/member/home' : '/admin/dashboard'), { replace: true })
      toast.success(`Selamat datang, ${authenticated.name}.`)
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Login gagal. Coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-brand-panel">
        <BrandMark inverse />
        <div className="auth-brand-copy">
          <ShieldCheck size={42} strokeWidth={1.6} />
          <h1>Masuk Absensi TP PKK Balangan</h1>
          <p>Gunakan akun yang sudah terdaftar untuk mencatat dan mengelola kehadiran.</p>
        </div>
        <small>TP PKK Kabupaten Balangan</small>
      </section>
      <section className="auth-form-panel">
        <div className="auth-form-wrap">
          <div className="auth-mobile-brand"><BrandMark /></div>
          <h2>Masuk</h2>
          <p>Gunakan username staf atau nomor anggota.</p>
          <form onSubmit={form.handleSubmit(onSubmit)}>
            <div className="field">
              <label htmlFor="loginId">ID pengguna</label>
              <div className="input-with-icon"><UserRound size={18} /><input id="loginId" className="input" placeholder="Username / nomor anggota" autoComplete="username" {...form.register('loginId')} /></div>
              {form.formState.errors.loginId ? <span className="field-error">{form.formState.errors.loginId.message}</span> : null}
            </div>
            <div className="field">
              <label htmlFor="password">Password</label>
              <div className="input-with-icon"><LockKeyhole size={18} /><input id="password" className="input" type={showPassword ? 'text' : 'password'} placeholder="Masukkan password" autoComplete="current-password" {...form.register('password')} /><button type="button" className="input-action" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}>{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</button></div>
              {form.formState.errors.password ? <span className="field-error">{form.formState.errors.password.message}</span> : null}
            </div>
            <label className="checkbox"><input type="checkbox" {...form.register('remember')} /><span>Ingat sesi di perangkat ini</span></label>
            <Button type="submit" disabled={submitting} className="auth-submit">{submitting ? 'Memverifikasi...' : 'Masuk'}</Button>
          </form>
          <p className="auth-footnote">Belum memiliki akun? <Link to="/register">Ajukan pendaftaran anggota</Link></p>
        </div>
      </section>
    </main>
  )
}
