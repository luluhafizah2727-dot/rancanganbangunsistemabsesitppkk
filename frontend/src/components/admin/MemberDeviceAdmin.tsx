import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, MonitorSmartphone, Search, UserRoundX, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { ConfirmDialog, EmptyState, FormErrorSummary, StatusBadge } from '../ui'
import { api, apiErrorMessage, jsonBody } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { MemberDevice, MemberDeviceBindingMode } from '../../types'

export function MemberDeviceAdmin({ canManage }: { canManage: boolean }) {
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
    onError: (error) => toast.error(apiErrorMessage(error, 'Pengaturan perangkat anggota gagal disimpan.')),
  })
  const reviewMutation = useMutation({
    mutationFn: () => {
      if (!review) throw new Error('Perangkat belum dipilih.')
      return api(`/api/v1/member-devices/${review.device.id}/${review.action}`, { method: 'POST', ...jsonBody({ review_note: reviewNote || (review.action === 'approve' ? null : 'Ditinjau oleh admin') }) })
    },
    onSuccess: () => { setReview(null); setReviewNote(''); refresh(); toast.success('Status perangkat diperbarui.') },
    onError: (error) => toast.error(apiErrorMessage(error, 'Status perangkat gagal diperbarui.')),
  })

  return (
    <div className="settings-grid">
      <section className="panel">
        <header className="panel__header"><div><h2>Aturan perangkat anggota</h2><p>Mode ketat meminta persetujuan sebelum anggota memindai QR dari perangkat baru.</p></div></header>
        <div className="panel__body security-options">
          <label className="radio-card"><input type="radio" checked={setting.data?.mode === 'approval_required'} disabled={!canManage || saveMode.isPending} onChange={() => saveMode.mutate('approval_required')} /><span><strong>Perlu persetujuan</strong><small>Perangkat baru tidak bisa scan sebelum disetujui.</small></span></label>
          <label className="radio-card"><input type="radio" checked={setting.data?.mode === 'audit_only'} disabled={!canManage || saveMode.isPending} onChange={() => saveMode.mutate('audit_only')} /><span><strong>Audit saja</strong><small>Scan tetap bisa, perangkat baru dicatat di Log.</small></span></label>
        </div>
      </section>
      <section className="panel">
        <header className="panel__header"><h2>Perangkat anggota</h2></header>
        <div className="panel__body toolbar"><div className="input-with-icon search-input"><Search size={17} /><input className="input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari anggota" /></div></div>
        {devices.data?.length ? <div className="resource-list">{devices.data.map((device) => <article key={device.id} className="resource-row"><span className="resource-icon"><MonitorSmartphone size={20} /></span><div className="resource-main"><div><h2>{device.member.user?.name}</h2><StatusBadge tone={deviceTone(device.status)}>{deviceStatusLabel(device.status)}</StatusBadge></div><p>{device.member.member_number} · {device.label || 'Perangkat anggota'}</p><div className="device-meta"><span>IP {device.ip_address || '-'}</span><span>{device.last_seen_at ? `Terakhir ${formatDate(device.last_seen_at)}` : 'Belum digunakan'}</span></div>{device.review_note ? <p>{device.review_note}</p> : null}</div>{canManage ? <div className="table-actions">{device.status === 'pending' ? <><button className="icon-button" title="Setujui" onClick={() => setReview({ device, action: 'approve' })}><Check size={18} /></button><button className="icon-button icon-button--danger" title="Tolak" onClick={() => setReview({ device, action: 'reject' })}><X size={18} /></button></> : null}{device.status === 'approved' ? <button className="icon-button icon-button--danger" title="Cabut" onClick={() => setReview({ device, action: 'revoke' })}><UserRoundX size={18} /></button> : null}</div> : null}</article>)}</div> : <EmptyState title="Belum ada perangkat" description="Permohonan perangkat anggota akan tampil di sini." />}
      </section>
      {review ? <ConfirmDialog title={reviewTitle(review, devices.data ?? [])} description={reviewDescription(review, devices.data ?? [])} confirmLabel={reviewMutation.isPending ? 'Menyimpan...' : reviewLabel(review.action)} confirmVariant={review.action === 'approve' ? 'primary' : 'danger'} disabled={reviewMutation.isPending} onCancel={() => { setReview(null); setReviewNote('') }} onConfirm={() => reviewMutation.mutate()}><label className="field"><span>Catatan</span><textarea className="textarea" value={reviewNote} onChange={(event) => setReviewNote(event.target.value)} placeholder={review.action === 'approve' ? 'Opsional' : 'Tuliskan alasan'} /></label><FormErrorSummary error={reviewMutation.error} /></ConfirmDialog> : null}
    </div>
  )
}

function deviceStatusLabel(status: string) { return ({ pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak', revoked: 'Dicabut' } as Record<string, string>)[status] || status }
function deviceTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' { return status === 'approved' ? 'success' : status === 'pending' ? 'warning' : status === 'rejected' || status === 'revoked' ? 'danger' : 'neutral' }
function reviewTitle(review: { device: MemberDevice; action: 'approve' | 'reject' | 'revoke' }, devices: MemberDevice[]) {
  if (review.action !== 'approve') return review.action === 'reject' ? 'Tolak perangkat?' : 'Cabut perangkat?'

  return deviceCounts(review.device, devices).approved > 0 ? 'Setujui perangkat tambahan?' : 'Setujui perangkat?'
}

function reviewDescription(review: { device: MemberDevice; action: 'approve' | 'reject' | 'revoke' }, devices: MemberDevice[]) {
  const name = review.device.member.user?.name ?? 'Anggota'
  const label = review.device.label || 'Perangkat anggota'
  if (review.action !== 'approve') return `${name} - ${label}`

  const counts = deviceCounts(review.device, devices)
  const nextApproved = counts.approved + 1

  return `${name} - ${label}. Saat ini anggota memiliki ${counts.approved} perangkat disetujui dan ${counts.pending} permohonan menunggu. Jika disetujui, total perangkat disetujui menjadi ${nextApproved}.`
}

function deviceCounts(device: MemberDevice, devices: MemberDevice[]) {
  if (device.counts) {
    return {
      approved: device.counts.approved,
      pending: device.counts.pending,
    }
  }

  const memberDevices = devices.filter((item) => item.member.id === device.member.id)

  return {
    approved: memberDevices.filter((item) => item.status === 'approved').length,
    pending: memberDevices.filter((item) => item.status === 'pending').length,
  }
}

function reviewLabel(action: 'approve' | 'reject' | 'revoke') { return action === 'approve' ? 'Setujui' : action === 'reject' ? 'Tolak' : 'Cabut' }
