import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Monitor, Plus, ShieldX, Wifi, WifiOff } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button, ConfirmDialog, EmptyState, Modal, PageHeader, StatusBadge } from '../../components/ui'
import { api, ApiError, jsonBody } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import type { AttendanceDevice } from '../../types'

const emptyForm = { code: '', name: '', location: '' }

export function DevicesPage() {
  const { user } = useAuth()
  const canManage = user?.roles.includes('super_admin') ?? false
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [activation, setActivation] = useState<{ code: string; expires: string } | null>(null)
  const [revokeTarget, setRevokeTarget] = useState<AttendanceDevice | null>(null)
  const devices = useQuery({ queryKey: ['attendance-devices'], queryFn: () => api<AttendanceDevice[]>('/api/v1/attendance-devices') })
  const create = useMutation({
    mutationFn: () => api('/api/v1/attendance-devices', { method: 'POST', ...jsonBody(form) }),
    onSuccess: () => { setShowForm(false); setForm(emptyForm); refresh(); toast.success('Gawai berhasil ditambahkan.') },
    onError: showError,
  })
  const revoke = useMutation({
    mutationFn: (device: AttendanceDevice) => api(`/api/v1/attendance-devices/${device.id}/revoke`, { method: 'POST' }),
    onSuccess: () => { setRevokeTarget(null); refresh(); toast.success('Akses gawai telah dicabut.') },
    onError: showError,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['attendance-devices'] })
  const generateCode = async (device: AttendanceDevice) => {
    try {
      const result = await api<{ activation_code: string; expires_at: string }>(`/api/v1/attendance-devices/${device.id}/activation-code`, { method: 'POST' })
      setActivation({ code: result.activation_code, expires: result.expires_at })
    } catch (error) { showError(error) }
  }

  return (
    <>
      <PageHeader title="Gawai" description="Kelola setiap layar QR yang terdaftar." actions={canManage ? <Button icon={<Plus size={17} />} onClick={() => setShowForm(true)}>Tambah gawai</Button> : undefined} />
      <section className="resource-list panel">
        {devices.data?.length ? devices.data.map((device) => {
          const online = device.status === 'active' && Boolean(device.last_seen_at && new Date(device.last_seen_at).getTime() > devices.dataUpdatedAt - 120_000)
          return <article className="resource-row device-row" key={device.id}>
            <span className="resource-icon"><Monitor size={21} /></span>
            <div className="resource-main">
              <div><h2>{device.name}</h2><StatusBadge tone={device.status === 'active' ? 'success' : device.status === 'revoked' ? 'danger' : 'neutral'}>{statusLabel(device.status)}</StatusBadge></div>
              <p>{device.code} · {device.location || 'Lokasi belum diisi'}</p>
              <div className="device-meta">
                <span>{online ? <Wifi size={14} /> : <WifiOff size={14} />}{online ? 'Tersambung' : device.last_seen_at ? `Terakhir ${formatDate(device.last_seen_at, 'dd MMM, HH.mm')}` : 'Belum diaktivasi'}</span>
                {device.credential_expires_at ? <span>Aktif hingga {formatDate(device.credential_expires_at, 'dd MMM yyyy')}</span> : null}
              </div>
            </div>
            {canManage ? <div className="row-actions">
              {device.status === 'pending' ? <Button variant="secondary" icon={<KeyRound size={17} />} onClick={() => generateCode(device)}>Kode aktivasi</Button> : null}
              {device.status === 'active' || device.status === 'inactive' ? <Button variant="ghost" icon={<ShieldX size={17} />} onClick={() => setRevokeTarget(device)}>Cabut</Button> : null}
            </div> : null}
          </article>
        }) : <EmptyState title="Belum ada gawai" description="Tambahkan satu record untuk setiap layar QR yang akan digunakan." />}
      </section>

      {showForm ? <Modal title="Tambah gawai" onClose={() => setShowForm(false)}><form onSubmit={(event) => { event.preventDefault(); create.mutate() }}><div className="form-grid"><label className="field"><span>Kode</span><input className="input" placeholder="GAWAI-002" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value.toUpperCase() })} required /></label><label className="field"><span>Nama gawai</span><input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Layar Aula" required /></label><label className="field field--full"><span>Lokasi</span><input className="input" value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} /></label></div><div className="form-actions"><Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Batal</Button><Button type="submit" disabled={create.isPending}>{create.isPending ? 'Menyimpan...' : 'Simpan gawai'}</Button></div></form></Modal> : null}
      {activation ? <Modal title="Kode aktivasi gawai" onClose={() => setActivation(null)}><div className="modal__content activation-code"><p>Masukkan kode ini pada layar Gawai. Kode hanya dapat digunakan satu kali.</p><strong>{activation.code}</strong><small>Berlaku hingga {formatDate(activation.expires, 'HH.mm.ss')} WITA</small><Button onClick={() => setActivation(null)}>Selesai</Button></div></Modal> : null}
      {revokeTarget ? <ConfirmDialog title="Cabut akses gawai?" description={`${revokeTarget.name} akan langsung berhenti menampilkan QR dan tidak dapat diaktifkan kembali.`} confirmLabel={revoke.isPending ? 'Mencabut...' : 'Cabut akses'} confirmVariant="danger" disabled={revoke.isPending} onCancel={() => setRevokeTarget(null)} onConfirm={() => revoke.mutate(revokeTarget)} /> : null}
    </>
  )
}

function statusLabel(status: AttendanceDevice['status']) { return { pending: 'Belum diaktivasi', active: 'Aktif', inactive: 'Nonaktif', revoked: 'Dicabut' }[status] }
function showError(error: unknown) { toast.error(error instanceof ApiError ? error.message : 'Perubahan gawai gagal.') }
