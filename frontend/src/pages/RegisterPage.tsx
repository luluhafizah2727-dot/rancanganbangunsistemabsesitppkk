import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { z } from 'zod'
import { BrandMark } from '../components/BrandMark'
import { Button } from '../components/ui'
import { api, ApiError, jsonBody } from '../lib/api'

const schema = z.object({
  member_number: z.string().min(3, 'Nomor anggota wajib diisi.'),
  name: z.string().min(3, 'Nama lengkap wajib diisi.'),
  phone: z.string().min(8, 'Nomor telepon tidak valid.'),
  position: z.string().optional(),
  department: z.string().optional(),
  password: z.string().min(12, 'Password minimal 12 karakter.'),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi password tidak sama.' })

type FormValues = z.infer<typeof schema>

export function RegisterPage() {
  const [submitting, setSubmitting] = useState(false)
  const navigate = useNavigate()
  const form = useForm<FormValues>({ resolver: zodResolver(schema) })

  const onSubmit = async (values: FormValues) => {
    setSubmitting(true)
    try {
      await api('/api/v1/registrations', { method: 'POST', ...jsonBody(values) })
      toast.success('Pendaftaran diterima. Tunggu persetujuan admin.')
      navigate('/login')
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Pendaftaran gagal.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="registration-page">
      <section className="registration-card">
        <header><BrandMark /><Link to="/login" className="back-link"><ArrowLeft size={17} /> Kembali</Link></header>
        <div className="registration-title"><ShieldCheck size={31} /><div><h1>Pendaftaran anggota</h1><p>Akun baru bisa dipakai setelah disetujui petugas.</p></div></div>
        <form onSubmit={form.handleSubmit(onSubmit)}>
          <div className="form-grid">
            <Field label="Nomor anggota" error={form.formState.errors.member_number?.message}><input className="input" {...form.register('member_number')} /></Field>
            <Field label="Nama lengkap" error={form.formState.errors.name?.message}><input className="input" {...form.register('name')} /></Field>
            <Field label="Nomor telepon" error={form.formState.errors.phone?.message}><input className="input" inputMode="tel" {...form.register('phone')} /></Field>
            <Field label="Jabatan"><input className="input" {...form.register('position')} /></Field>
            <Field label="Bidang / kelompok"><input className="input" {...form.register('department')} /></Field>
            <span />
            <Field label="Password" error={form.formState.errors.password?.message}><input className="input" type="password" autoComplete="new-password" {...form.register('password')} /></Field>
            <Field label="Konfirmasi password" error={form.formState.errors.password_confirmation?.message}><input className="input" type="password" autoComplete="new-password" {...form.register('password_confirmation')} /></Field>
          </div>
          <div className="form-actions"><Button type="submit" disabled={submitting}>{submitting ? 'Mengirim...' : 'Kirim pendaftaran'}</Button></div>
        </form>
      </section>
    </main>
  )
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return <div className="field"><label>{label}</label>{children}{error ? <span className="field-error">{error}</span> : null}</div>
}
