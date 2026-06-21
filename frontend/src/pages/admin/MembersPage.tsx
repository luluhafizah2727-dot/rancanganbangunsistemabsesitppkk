import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, KeyRound, Pencil, Plus, Search, Trash2, Upload, UserRoundCheck, UserRoundX, X } from 'lucide-react'
import { useRef, useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { Avatar } from '../../components/Avatar'
import { MemberDeviceAdmin } from '../../components/admin/MemberDeviceAdmin'
import { Button, ConfirmDialog, EmptyState, FormErrorSummary, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import type { Member } from '../../types'

const emptyForm = { member_number: '', name: '', email: '', phone: '', position: '', department: '', address: '' }
type MemberForm = typeof emptyForm

export function MembersPage() {
  const { user } = useAuth()
  const canManage = user?.roles.includes('super_admin') ?? false
  const [tab, setTab] = useState<'data' | 'devices'>('data')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Member | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Member | null>(null)
  const [form, setForm] = useState<MemberForm>(emptyForm)
  const [credential, setCredential] = useState<{ login: string; password: string } | null>(null)
  const [importPreview, setImportPreview] = useState<{ id: string; valid: number; failed: number } | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()
  const members = useQuery({ queryKey: ['members', search], queryFn: () => api<Member[]>(`/api/v1/members?search=${encodeURIComponent(search)}&per_page=100`) })
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['members'] })

  const save = useMutation({
    mutationFn: async ({ id, values }: { id?: string; values: MemberForm }) => {
      if (id) {
        const member = await api<Member>(`/api/v1/members/${id}`, { method: 'PUT', ...jsonBody(values) })
        return { member, temporaryPassword: null, created: false }
      }

      const result = await api<{ member: Member; temporary_password: string }>('/api/v1/members', { method: 'POST', ...jsonBody(values) })
      return { member: result.member, temporaryPassword: result.temporary_password, created: true }
    },
    onSuccess: (result, variables) => {
      if (result.created && result.temporaryPassword) {
        setCredential({ login: result.member.member_number, password: result.temporaryPassword })
      }
      setEditing(null)
      setForm(emptyForm)
      invalidate()
      toast.success(variables.id ? 'Data anggota berhasil diperbarui.' : 'Anggota berhasil ditambahkan.')
    },
    onError: showError,
  })
  const action = useMutation({
    mutationFn: ({ id, endpoint }: { id: string; endpoint: string }) => api(`/api/v1/members/${id}/${endpoint}`, { method: 'POST' }),
    onSuccess: (_, variables) => {
      invalidate()
      const messages: Record<string, string> = {
        approve: 'Pendaftaran disetujui.',
        reject: 'Pendaftaran ditolak.',
        'toggle-status': 'Status anggota diperbarui.',
      }
      toast.success(messages[variables.endpoint] ?? 'Data anggota diperbarui.')
    },
    onError: showError,
  })
  const resetPassword = useMutation({
    mutationFn: (member: Member) => api<{ temporary_password: string }>(`/api/v1/members/${member.id}/reset-password`, { method: 'POST' }).then((result) => ({ member, ...result })),
    onSuccess: (result) => setCredential({ login: result.member.member_number, password: result.temporary_password }),
    onError: showError,
  })
  const archive = useMutation({
    mutationFn: (member: Member) => api(`/api/v1/members/${member.id}`, { method: 'DELETE' }),
    onSuccess: () => {
      setDeleting(null)
      invalidate()
      toast.success('Anggota dihapus dari daftar aktif.')
    },
    onError: showError,
  })

  const openCreate = () => {
    setForm(emptyForm)
    setEditing('new')
  }
  const openEdit = (member: Member) => {
    setForm({
      member_number: member.member_number,
      name: member.user?.name ?? '',
      email: member.user?.email ?? '',
      phone: member.user?.phone ?? '',
      position: member.position ?? '',
      department: member.department ?? '',
      address: member.address ?? '',
    })
    setEditing(member)
  }
  const submit = (event: FormEvent) => {
    event.preventDefault()
    save.mutate({ id: editing && editing !== 'new' ? editing.id : undefined, values: form })
  }

  const previewImport = async (file: File) => {
    const body = new FormData()
    body.append('file', file)
    try {
      const preview = await api<{ import_id: string; valid_rows: number; failed_rows: number }>('/api/v1/member-imports/preview', { method: 'POST', body })
      setImportPreview({ id: preview.import_id, valid: preview.valid_rows, failed: preview.failed_rows })
    } catch (error) {
      showError(error)
    } finally {
      if (fileRef.current) fileRef.current.value = ''
    }
  }
  const confirmImport = async () => {
    if (!importPreview) return
    try {
      const result = await api<{ created: number; failed: number }>(`/api/v1/member-imports/${importPreview.id}/confirm`, { method: 'POST' })
      toast.success(`${result.created} anggota berhasil diimpor.`)
      setImportPreview(null)
      invalidate()
    } catch (error) {
      showError(error)
    }
  }

  return (
    <>
      <PageHeader
        title="Anggota"
        description="Kelola data anggota, persetujuan akun, status, dan perangkat anggota."
        actions={canManage ? (
          <>
            <input ref={fileRef} type="file" accept=".xlsx,.csv" hidden onChange={(event) => event.target.files?.[0] && previewImport(event.target.files[0])} />
            <Button variant="secondary" icon={<Upload size={17} />} onClick={() => fileRef.current?.click()}>Import</Button>
            <Button icon={<Plus size={17} />} onClick={openCreate}>Tambah anggota</Button>
          </>
        ) : undefined}
      />
      <div className="tabs">
        <button className={tab === 'data' ? 'active' : ''} onClick={() => setTab('data')}>Data Anggota</button>
        <button className={tab === 'devices' ? 'active' : ''} onClick={() => setTab('devices')}>Perangkat Anggota</button>
      </div>
      {tab === 'devices' ? <MemberDeviceAdmin canManage={canManage} /> : (
      <section className="panel">
        <div className="panel__body toolbar">
          <div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama atau nomor anggota" /></div>
          <StatusBadge tone="info">{members.data?.length ?? 0} anggota</StatusBadge>
        </div>
        {members.data?.length ? (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead><tr><th>Nomor</th><th>Nama</th><th>Jabatan</th><th>Kontak</th><th>Status</th>{canManage ? <th>Aksi</th> : null}</tr></thead>
              <tbody>{members.data.map((member) => (
                <tr key={member.id}>
                  <td className="table-primary">{member.member_number}</td>
                  <td><div className="member-cell"><Avatar name={member.user?.name ?? 'Anggota'} src={member.user?.avatar_url} size="small" /><span><span className="table-primary">{member.user?.name}</span><span className="table-secondary">{member.department || 'TP PKK Kabupaten Balangan'}</span></span></div></td>
                  <td>{member.position || 'Anggota'}</td>
                  <td>{member.user?.phone || member.user?.email || '-'}</td>
                  <td><StatusBadge tone={statusTone(member.user?.status)}>{statusLabel(member.user?.status)}</StatusBadge></td>
                  {canManage ? <td>
                    <div className="table-actions">
                      <button className="icon-button" title="Edit anggota" aria-label={`Edit ${member.user?.name}`} onClick={() => openEdit(member)}><Pencil size={18} /></button>
                      {member.user?.status === 'pending' ? (
                        <>
                          <button className="icon-button" title="Setujui" aria-label={`Setujui ${member.user?.name}`} onClick={() => action.mutate({ id: member.id, endpoint: 'approve' })}><UserRoundCheck size={18} /></button>
                          <button className="icon-button icon-button--danger" title="Tolak" aria-label={`Tolak ${member.user?.name}`} onClick={() => action.mutate({ id: member.id, endpoint: 'reject' })}><X size={18} /></button>
                        </>
                      ) : null}
                      <button className="icon-button" title="Reset password" aria-label={`Reset password ${member.user?.name}`} onClick={() => resetPassword.mutate(member)}><KeyRound size={18} /></button>
                      <button className="icon-button" title={member.user?.status === 'suspended' ? 'Aktifkan' : 'Suspend'} aria-label={`${member.user?.status === 'suspended' ? 'Aktifkan' : 'Suspend'} ${member.user?.name}`} onClick={() => action.mutate({ id: member.id, endpoint: 'toggle-status' })}>{member.user?.status === 'suspended' ? <Check size={18} /> : <UserRoundX size={18} />}</button>
                      <button className="icon-button icon-button--danger" title="Hapus anggota" aria-label={`Hapus ${member.user?.name}`} onClick={() => setDeleting(member)}><Trash2 size={18} /></button>
                    </div>
                  </td> : null}
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : <EmptyState title="Anggota belum ditemukan" description="Tambahkan anggota atau ubah kata pencarian." />}
      </section>
      )}

      {editing && canManage ? (
        <Modal title={editing === 'new' ? 'Tambah anggota' : 'Edit anggota'} onClose={() => setEditing(null)}>
          <form onSubmit={submit}>
            <div className="form-grid">
              {Object.entries({ member_number: 'Nomor anggota', name: 'Nama lengkap', email: 'Email', phone: 'Nomor telepon', position: 'Jabatan', department: 'Bidang / kelompok' }).map(([key, label]) => (
                <label className="field" key={key}><span>{label}</span><input className="input" type={key === 'email' ? 'email' : 'text'} value={form[key as keyof MemberForm]} onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))} required={['member_number', 'name'].includes(key)} /></label>
              ))}
              <label className="field field--full"><span>Alamat</span><textarea className="textarea" value={form.address} onChange={(event) => setForm((current) => ({ ...current, address: event.target.value }))} /></label>
            </div>
            <FormErrorSummary error={save.error} />
            <div className="form-actions"><Button type="button" variant="secondary" onClick={() => setEditing(null)}>Batal</Button><Button type="submit" disabled={save.isPending}>{save.isPending ? 'Menyimpan...' : 'Simpan anggota'}</Button></div>
          </form>
        </Modal>
      ) : null}
      {deleting && canManage ? (
        <ConfirmDialog
          title="Hapus anggota?"
          description={`${deleting.user?.name} (${deleting.member_number}) akan dihapus dari daftar aktif. Riwayat kehadirannya tetap tersimpan.`}
          confirmLabel={archive.isPending ? 'Menghapus...' : 'Hapus anggota'}
          confirmVariant="danger"
          disabled={archive.isPending}
          onCancel={() => setDeleting(null)}
          onConfirm={() => archive.mutate(deleting)}
        />
      ) : null}
      {importPreview && canManage ? (
        <ConfirmDialog
          title="Lanjutkan import?"
          description={`${importPreview.valid} baris siap diimpor dan ${importPreview.failed} baris tidak dapat diproses.`}
          confirmLabel="Import data"
          onCancel={() => setImportPreview(null)}
          onConfirm={confirmImport}
        />
      ) : null}
      {credential ? (
        <Modal title="Akun sementara" onClose={() => setCredential(null)}>
          <div className="modal__content credential-box">
            <p>Berikan data ini langsung kepada anggota. Minta anggota mengganti password saat pertama kali masuk.</p>
            <dl><div><dt>ID pengguna</dt><dd>{credential.login}</dd></div><div><dt>Password</dt><dd>{credential.password}</dd></div></dl>
            <Button onClick={() => setCredential(null)}>Saya sudah mencatat</Button>
          </div>
        </Modal>
      ) : null}
    </>
  )
}

function statusTone(status?: string): 'success' | 'warning' | 'danger' | 'neutral' {
  return status === 'active' ? 'success' : status === 'pending' ? 'warning' : status === 'suspended' ? 'danger' : 'neutral'
}
function statusLabel(status?: string) {
  return ({ active: 'Aktif', pending: 'Menunggu', suspended: 'Ditangguhkan', rejected: 'Ditolak' } as Record<string, string>)[status || ''] || '-'
}
function showError(error: unknown) {
  toast.error(apiErrorMessage(error, 'Operasi gagal.'))
}
