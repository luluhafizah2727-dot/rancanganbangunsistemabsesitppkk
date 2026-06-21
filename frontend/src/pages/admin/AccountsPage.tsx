import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, KeyRound, Pencil, Plus, Search, UserRoundX } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Avatar } from '../../components/Avatar'
import { Button, ConfirmDialog, EmptyState, FormErrorSummary, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { Account, Role } from '../../types'

const emptyStaffForm = { login_id: '', name: '', email: '', phone: '', role: 'operator' as Extract<Role, 'super_admin' | 'operator'>, password: '', password_confirmation: '' }

export function AccountsPage() {
  return (
    <>
      <PageHeader title="Akun" description="Kelola akun staf, role, status, dan reset password." />
      <AccountList />
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
  const staffAccounts = accounts.data?.filter((account) => !account.roles.includes('member')) ?? []
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
    mutationFn: ({ account, values }: { account: Account; values: { login_id?: string; name?: string; email?: string; phone?: string; status?: Account['status']; role?: Role } }) => api<Account>(`/api/v1/accounts/${account.id}`, { method: 'PUT', ...jsonBody(values) }),
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
        {staffAccounts.length ? (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Akun</th><th>Role</th><th>Status</th><th>Kontak</th><th>Terakhir masuk</th><th>Aksi</th></tr></thead>
              <tbody>{staffAccounts.map((account) => (
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

      {creating ? <StaffModal form={form} busy={create.isPending} error={create.error} onChange={setForm} onClose={() => setCreating(false)} onSubmit={() => create.mutate()} /> : null}
      {editing ? <EditAccountModal account={editing} busy={update.isPending} error={update.error} onClose={() => setEditing(null)} onSubmit={(values) => update.mutate({ account: editing, values })} /> : null}
      {toggleTarget ? <ConfirmDialog title={toggleTarget.status === 'suspended' ? 'Aktifkan akun?' : 'Tangguhkan akun?'} description={`${toggleTarget.name} ${toggleTarget.status === 'suspended' ? 'akan dapat masuk kembali.' : 'tidak dapat masuk sampai diaktifkan lagi.'}`} confirmLabel={toggle.isPending ? 'Menyimpan...' : 'Lanjutkan'} confirmVariant={toggleTarget.status === 'suspended' ? 'primary' : 'danger'} disabled={toggle.isPending} onCancel={() => setToggleTarget(null)} onConfirm={() => toggle.mutate(toggleTarget)} /> : null}
      {credential ? <Modal title="Password sementara" onClose={() => setCredential(null)}><div className="modal__content credential-box"><p>Berikan data ini langsung kepada pengguna. Minta pengguna mengganti password setelah masuk.</p><dl><div><dt>ID pengguna</dt><dd>{credential.login}</dd></div><div><dt>Password</dt><dd>{credential.password}</dd></div></dl><Button onClick={() => setCredential(null)}>Saya sudah mencatat</Button></div></Modal> : null}
    </>
  )
}

function StaffModal({ form, busy, error, onChange, onClose, onSubmit }: { form: typeof emptyStaffForm; busy: boolean; error: unknown; onChange: (value: typeof emptyStaffForm) => void; onClose: () => void; onSubmit: () => void }) {
  return <Modal title="Tambah akun staf" onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); onSubmit() }}><div className="form-grid"><label className="field"><span>ID pengguna</span><input className="input" value={form.login_id} onChange={(event) => onChange({ ...form, login_id: event.target.value })} required /></label><label className="field"><span>Role</span><select className="select" value={form.role} onChange={(event) => onChange({ ...form, role: event.target.value as typeof form.role })}><option value="operator">Operator</option><option value="super_admin">Super Admin</option></select></label><label className="field"><span>Nama</span><input className="input" value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} required /></label><label className="field"><span>Email</span><input className="input" type="email" value={form.email} onChange={(event) => onChange({ ...form, email: event.target.value })} /></label><label className="field"><span>Nomor telepon</span><input className="input" value={form.phone} onChange={(event) => onChange({ ...form, phone: event.target.value })} /></label><label className="field"><span>Password awal opsional</span><input className="input" type="password" minLength={8} value={form.password} onChange={(event) => onChange({ ...form, password: event.target.value })} /><small>Minimal 8 karakter. Kosongkan jika ingin dibuat otomatis.</small></label>{form.password ? <label className="field"><span>Konfirmasi password</span><input className="input" type="password" value={form.password_confirmation} onChange={(event) => onChange({ ...form, password_confirmation: event.target.value })} required /></label> : null}</div><FormErrorSummary error={error} /><div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : 'Simpan akun'}</Button></div></form></Modal>
}

function EditAccountModal({ account, busy, error, onClose, onSubmit }: { account: Account; busy: boolean; error: unknown; onClose: () => void; onSubmit: (values: { login_id?: string; name: string; email: string; phone: string; status: Account['status']; role?: Role }) => void }) {
  const isMember = account.roles.includes('member')
  const [form, setForm] = useState({ login_id: account.login_id, name: account.name, email: account.email ?? '', phone: account.phone ?? '', status: account.status, role: account.roles[0] })
  return <Modal title="Edit akun" onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); onSubmit(isMember ? { name: form.name, email: form.email, phone: form.phone, status: form.status } : form) }}><div className="form-grid"><label className="field"><span>ID pengguna</span><input className="input" value={form.login_id} disabled={isMember} onChange={(event) => setForm({ ...form, login_id: event.target.value })} required /></label><label className="field"><span>Role</span><select className="select" value={form.role} disabled={isMember} onChange={(event) => setForm({ ...form, role: event.target.value as Role })}><option value="operator">Operator</option><option value="super_admin">Super Admin</option><option value="member">Anggota</option></select></label><label className="field"><span>Nama</span><input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label><label className="field"><span>Status</span><select className="select" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as Account['status'] })}><option value="active">Aktif</option><option value="pending">Menunggu</option><option value="suspended">Ditangguhkan</option><option value="rejected">Ditolak</option></select></label><label className="field"><span>Email</span><input className="input" type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label><label className="field"><span>Nomor telepon</span><input className="input" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label></div>{isMember ? <p className="form-note">Data resmi anggota seperti nomor anggota, jabatan, dan alamat tetap diubah dari menu Anggota.</p> : null}<FormErrorSummary error={error} /><div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : 'Simpan perubahan'}</Button></div></form></Modal>
}

function roleLabel(role?: Role) { return role === 'super_admin' ? 'Super Admin' : role === 'operator' ? 'Operator' : 'Anggota' }
function statusLabel(status?: string) { return ({ active: 'Aktif', pending: 'Menunggu', suspended: 'Ditangguhkan', rejected: 'Ditolak' } as Record<string, string>)[status || ''] || '-' }
function statusTone(status?: string): 'success' | 'warning' | 'danger' | 'neutral' { return status === 'active' ? 'success' : status === 'pending' ? 'warning' : status === 'suspended' ? 'danger' : 'neutral' }
function showError(error: unknown) { toast.error(apiErrorMessage(error, 'Operasi gagal.')) }
