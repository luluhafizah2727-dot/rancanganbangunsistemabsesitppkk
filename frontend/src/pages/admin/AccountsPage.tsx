import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, KeyRound, MonitorSmartphone, Pencil, Plus, Search, UserRoundX, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Avatar } from '../../components/Avatar'
import { Button, ConfirmDialog, EmptyState, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, ApiError, jsonBody } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { Account, MemberDevice, MemberDeviceBindingMode, Role } from '../../types'

const emptyStaffForm = { login_id: '', name: '', email: '', phone: '', role: 'operator' as Extract<Role, 'super_admin' | 'operator'>, password: '', password_confirmation: '' }

export function AccountsPage() {
  const [tab, setTab] = useState<'accounts' | 'devices'>('accounts')

  return (
    <>
      <PageHeader title="Akun" description="Kelola akun pengguna, role staf, dan perangkat anggota." />
      <div className="tabs">
        <button className={tab === 'accounts' ? 'active' : ''} onClick={() => setTab('accounts')}>Daftar Akun</button>
        <button className={tab === 'devices' ? 'active' : ''} onClick={() => setTab('devices')}>Perangkat Anggota</button>
      </div>
      {tab === 'accounts' ? <AccountList /> : <MemberDeviceAdmin />}
    </>
  )
}

function AccountList() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Account | null>(null)
  const [creating, setCreating] = useState(false)
  const [credential, setCredential] = useState<{ login: string; password: string } | null>(null)
  const [toggleTarget, setToggleTarget] = useState<Account | null>(null)
  const [form, setForm] = useState(emptyStaffForm)
  const accounts = useQuery({ queryKey: ['accounts', search], queryFn: () => api<Account[]>(`/api/v1/accounts?search=${encodeURIComponent(search)}&per_page=100`) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['accounts'] })

  const create = useMutation({
    mutationFn: () => api<{ account: Account; temporary_password: string | null }>('/api/v1/accounts', { method: 'POST', ...jsonBody(form.password ? form : { ...form, password: null, password_confirmation: null }) }),
    onSuccess: (result) => {
      setCreating(false)
      setForm(emptyStaffForm)
      refresh()
      if (result.temporary_password) setCredential({ login: result.account.login_id, password: result.temporary_password })
      toast.success('Akun staf berhasil dibuat.')
    },
    onError: showError,
  })
  const update = useMutation({
    mutationFn: ({ account, values }: { account: Account; values: { name?: string; email?: string; phone?: string; status?: Account['status']; role?: Role } }) => api<Account>(`/api/v1/accounts/${account.id}`, { method: 'PUT', ...jsonBody(values) }),
    onSuccess: () => { setEditing(null); refresh(); toast.success('Akun berhasil diperbarui.') },
    onError: showError,
  })
  const resetPassword = useMutation({
    mutationFn: (account: Account) => api<{ temporary_password: string }>(`/api/v1/accounts/${account.id}/reset-password`, { method: 'POST' }).then((result) => ({ account, ...result })),
    onSuccess: (result) => setCredential({ login: result.account.login_id, password: result.temporary_password }),
    onError: showError,
  })
  const toggle = useMutation({
    mutationFn: (account: Account) => api<Account>(`/api/v1/accounts/${account.id}/toggle-status`, { method: 'POST' }),
    onSuccess: () => { setToggleTarget(null); refresh(); toast.success('Status akun diperbarui.') },
    onError: showError,
  })

  return (
    <>
      <section className="panel">
        <div className="panel__body toolbar">
          <div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari ID, nama, atau email" /></div>
          <Button icon={<Plus size={17} />} onClick={() => setCreating(true)}>Tambah staf</Button>
        </div>
        {accounts.data?.length ? (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Akun</th><th>Role</th><th>Status</th><th>Kontak</th><th>Terakhir masuk</th><th>Aksi</th></tr></thead>
              <tbody>{accounts.data.map((account) => (
                <tr key={account.id}>
                  <td><div className="member-cell"><Avatar name={account.name} src={account.avatar_url} size="small" /><span><span className="table-primary">{account.name}</span><span className="table-secondary">{account.login_id}</span></span></div></td>
                  <td><StatusBadge tone={account.roles.includes('super_admin') ? 'info' : account.roles.includes('operator') ? 'neutral' : 'success'}>{roleLabel(account.roles[0])}</StatusBadge></td>
                  <td><StatusBadge tone={statusTone(account.status)}>{statusLabel(account.status)}</StatusBadge></td>
                  <td>{account.phone || account.email || '-'}</td>
                  <td>{account.last_login_at ? formatDate(account.last_login_at) : '-'}</td>
                  <td><div className="table-actions">
                    <button className="icon-button" title="Edit akun" onClick={() => setEditing(account)}><Pencil size={18} /></button>
                    <button className="icon-button" title="Reset password" onClick={() => resetPassword.mutate(account)}><KeyRound size={18} /></button>
                    <button className={`icon-button ${account.status === 'active' ? 'icon-button--danger' : ''}`} title={account.status === 'suspended' ? 'Aktifkan akun' : 'Tangguhkan akun'} onClick={() => setToggleTarget(account)}>{account.status === 'suspended' ? <Check size={18} /> : <UserRoundX size={18} />}</button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : <EmptyState title="Akun belum ditemukan" description="Tambah staf atau ubah kata pencarian." />}
      </section>

      {creating ? <StaffModal form={form} busy={create.isPending} onChange={setForm} onClose={() => setCreating(false)} onSubmit={() => create.mutate()} /> : null}
      {editing ? <EditAccountModal account={editing} busy={update.isPending} onClose={() => setEditing(null)} onSubmit={(values) => update.mutate({ account: editing, values })} /> : null}
      {toggleTarget ? <ConfirmDialog title={toggleTarget.status === 'suspended' ? 'Aktifkan akun?' : 'Tangguhkan akun?'} description={`${toggleTarget.name} ${toggleTarget.status === 'suspended' ? 'akan dapat masuk kembali.' : 'tidak dapat masuk sampai diaktifkan lagi.'}`} confirmLabel={toggle.isPending ? 'Menyimpan...' : 'Lanjutkan'} confirmVariant={toggleTarget.status === 'suspended' ? 'primary' : 'danger'} disabled={toggle.isPending} onCancel={() => setToggleTarget(null)} onConfirm={() => toggle.mutate(toggleTarget)} /> : null}
      {credential ? <Modal title="Password sementara" onClose={() => setCredential(null)}><div className="modal__content credential-box"><p>Berikan data ini langsung kepada pengguna. Minta pengguna mengganti password setelah masuk.</p><dl><div><dt>ID pengguna</dt><dd>{credential.login}</dd></div><div><dt>Password</dt><dd>{credential.password}</dd></div></dl><Button onClick={() => setCredential(null)}>Saya sudah mencatat</Button></div></Modal> : null}
    </>
  )
}

function StaffModal({ form, busy, onChange, onClose, onSubmit }: { form: typeof emptyStaffForm; busy: boolean; onChange: (value: typeof emptyStaffForm) => void; onClose: () => void; onSubmit: () => void }) {
  return <Modal title="Tambah akun staf" onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); onSubmit() }}><div className="form-grid"><label className="field"><span>ID pengguna</span><input className="input" value={form.login_id} onChange={(event) => onChange({ ...form, login_id: event.target.value })} required /></label><label className="field"><span>Role</span><select className="select" value={form.role} onChange={(event) => onChange({ ...form, role: event.target.value as typeof form.role })}><option value="operator">Operator</option><option value="super_admin">Super Admin</option></select></label><label className="field"><span>Nama</span><input className="input" value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} required /></label><label className="field"><span>Email</span><input className="input" type="email" value={form.email} onChange={(event) => onChange({ ...form, email: event.target.value })} /></label><label className="field"><span>Nomor telepon</span><input className="input" value={form.phone} onChange={(event) => onChange({ ...form, phone: event.target.value })} /></label><label className="field"><span>Password awal opsional</span><input className="input" type="password" minLength={12} value={form.password} onChange={(event) => onChange({ ...form, password: event.target.value })} /></label>{form.password ? <label className="field"><span>Konfirmasi password</span><input className="input" type="password" value={form.password_confirmation} onChange={(event) => onChange({ ...form, password_confirmation: event.target.value })} required /></label> : null}</div><div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : 'Simpan akun'}</Button></div></form></Modal>
}

function EditAccountModal({ account, busy, onClose, onSubmit }: { account: Account; busy: boolean; onClose: () => void; onSubmit: (values: { name: string; email: string; phone: string; status: Account['status']; role?: Role }) => void }) {
  const isMember = account.roles.includes('member')
  const [form, setForm] = useState({ name: account.name, email: account.email ?? '', phone: account.phone ?? '', status: account.status, role: account.roles[0] })
  return <Modal title="Edit akun" onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); onSubmit(isMember ? { name: form.name, email: form.email, phone: form.phone, status: form.status } : form) }}><div className="form-grid"><label className="field"><span>ID pengguna</span><input className="input" value={account.login_id} disabled /></label><label className="field"><span>Role</span><select className="select" value={form.role} disabled={isMember} onChange={(event) => setForm({ ...form, role: event.target.value as Role })}><option value="operator">Operator</option><option value="super_admin">Super Admin</option><option value="member">Anggota</option></select></label><label className="field"><span>Nama</span><input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label><label className="field"><span>Status</span><select className="select" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as Account['status'] })}><option value="active">Aktif</option><option value="pending">Menunggu</option><option value="suspended">Ditangguhkan</option><option value="rejected">Ditolak</option></select></label><label className="field"><span>Email</span><input className="input" type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label><label className="field"><span>Nomor telepon</span><input className="input" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label></div>{isMember ? <p className="form-note">Data resmi anggota seperti nomor anggota, jabatan, dan alamat tetap diubah dari menu Anggota.</p> : null}<div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : 'Simpan perubahan'}</Button></div></form></Modal>
}

function MemberDeviceAdmin() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [review, setReview] = useState<{ device: MemberDevice; action: 'approve' | 'reject' | 'revoke' } | null>(null)
  const [reviewNote, setReviewNote] = useState('')
  const devices = useQuery({ queryKey: ['member-devices', search], queryFn: () => api<MemberDevice[]>(`/api/v1/member-devices?search=${encodeURIComponent(search)}&per_page=100`) })
  const setting = useQuery({ queryKey: ['member-device-binding'], queryFn: () => api<{ mode: MemberDeviceBindingMode }>('/api/v1/security-settings/member-device-binding') })
  const refresh = () => { queryClient.invalidateQueries({ queryKey: ['member-devices'] }); queryClient.invalidateQueries({ queryKey: ['member-device-binding'] }) }
  const saveMode = useMutation({
    mutationFn: (mode: MemberDeviceBindingMode) => api('/api/v1/security-settings/member-device-binding', { method: 'PUT', ...jsonBody({ mode }) }),
    onSuccess: () => { refresh(); toast.success('Pengaturan perangkat anggota diperbarui.') },
    onError: showError,
  })
  const reviewMutation = useMutation({
    mutationFn: () => {
      if (!review) throw new Error('Perangkat belum dipilih.')
      return api(`/api/v1/member-devices/${review.device.id}/${review.action}`, { method: 'POST', ...jsonBody({ review_note: reviewNote || (review.action === 'approve' ? null : 'Ditinjau oleh admin') }) })
    },
    onSuccess: () => { setReview(null); setReviewNote(''); refresh(); toast.success('Status perangkat diperbarui.') },
    onError: showError,
  })

  return (
    <div className="settings-grid">
      <section className="panel">
        <header className="panel__header"><div><h2>Aturan perangkat anggota</h2><p>Mode ketat meminta persetujuan admin sebelum scan.</p></div></header>
        <div className="panel__body security-options">
          <label className="radio-card"><input type="radio" checked={setting.data?.mode === 'approval_required'} onChange={() => saveMode.mutate('approval_required')} /><span><strong>Perlu persetujuan</strong><small>Perangkat baru tidak bisa scan sebelum disetujui.</small></span></label>
          <label className="radio-card"><input type="radio" checked={setting.data?.mode === 'audit_only'} onChange={() => saveMode.mutate('audit_only')} /><span><strong>Audit saja</strong><small>Scan tetap bisa, perangkat baru dicatat di Log.</small></span></label>
        </div>
      </section>
      <section className="panel">
        <header className="panel__header"><h2>Perangkat anggota</h2></header>
        <div className="panel__body toolbar"><div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari anggota" /></div></div>
        {devices.data?.length ? <div className="resource-list">{devices.data.map((device) => <article key={device.id} className="resource-row"><span className="resource-icon"><MonitorSmartphone size={20} /></span><div className="resource-main"><div><h2>{device.member.user?.name}</h2><StatusBadge tone={deviceTone(device.status)}>{deviceStatusLabel(device.status)}</StatusBadge></div><p>{device.member.member_number} · {device.label || 'Perangkat anggota'}</p><div className="device-meta"><span>IP {device.ip_address || '-'}</span><span>{device.last_seen_at ? `Terakhir ${formatDate(device.last_seen_at)}` : 'Belum digunakan'}</span></div>{device.review_note ? <p>{device.review_note}</p> : null}</div><div className="table-actions">{device.status === 'pending' ? <><button className="icon-button" title="Setujui" onClick={() => setReview({ device, action: 'approve' })}><Check size={18} /></button><button className="icon-button icon-button--danger" title="Tolak" onClick={() => setReview({ device, action: 'reject' })}><X size={18} /></button></> : null}{device.status === 'approved' ? <button className="icon-button icon-button--danger" title="Cabut" onClick={() => setReview({ device, action: 'revoke' })}><UserRoundX size={18} /></button> : null}</div></article>)}</div> : <EmptyState title="Belum ada perangkat" description="Permohonan perangkat anggota akan tampil di sini." />}
      </section>
      {review ? <ConfirmDialog title={reviewTitle(review.action)} description={`${review.device.member.user?.name} - ${review.device.label || 'Perangkat anggota'}`} confirmLabel={reviewMutation.isPending ? 'Menyimpan...' : reviewLabel(review.action)} confirmVariant={review.action === 'approve' ? 'primary' : 'danger'} disabled={reviewMutation.isPending} onCancel={() => { setReview(null); setReviewNote('') }} onConfirm={() => reviewMutation.mutate()}><label className="field"><span>Catatan</span><textarea className="textarea" value={reviewNote} onChange={(event) => setReviewNote(event.target.value)} placeholder={review.action === 'approve' ? 'Opsional' : 'Tuliskan alasan'} /></label></ConfirmDialog> : null}
    </div>
  )
}

function roleLabel(role?: Role) { return role === 'super_admin' ? 'Super Admin' : role === 'operator' ? 'Operator' : 'Anggota' }
function statusLabel(status?: string) { return ({ active: 'Aktif', pending: 'Menunggu', suspended: 'Ditangguhkan', rejected: 'Ditolak' } as Record<string, string>)[status || ''] || '-' }
function statusTone(status?: string): 'success' | 'warning' | 'danger' | 'neutral' { return status === 'active' ? 'success' : status === 'pending' ? 'warning' : status === 'suspended' ? 'danger' : 'neutral' }
function deviceStatusLabel(status: string) { return ({ pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak', revoked: 'Dicabut' } as Record<string, string>)[status] || status }
function deviceTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' { return status === 'approved' ? 'success' : status === 'pending' ? 'warning' : status === 'rejected' || status === 'revoked' ? 'danger' : 'neutral' }
function reviewTitle(action: 'approve' | 'reject' | 'revoke') { return action === 'approve' ? 'Setujui perangkat?' : action === 'reject' ? 'Tolak perangkat?' : 'Cabut perangkat?' }
function reviewLabel(action: 'approve' | 'reject' | 'revoke') { return action === 'approve' ? 'Setujui' : action === 'reject' ? 'Tolak' : 'Cabut' }
function showError(error: unknown) { toast.error(error instanceof ApiError ? error.message : 'Operasi gagal.') }
