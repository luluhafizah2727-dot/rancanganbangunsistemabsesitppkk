import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, Clock3, Expand, MonitorCheck, RefreshCw, ShieldCheck, Wifi, WifiOff } from 'lucide-react'
import { useEffect, useState, type CSSProperties } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { toast } from 'sonner'
import { BrandMark } from '../../components/BrandMark'
import { Button, LoadingScreen, StatusBadge } from '../../components/ui'
import { api, ApiError, jsonBody } from '../../lib/api'
import { formatDate } from '../../lib/format'
import type { AttendanceSummary, DeviceContext } from '../../types'

interface ActivationValues { activation_code: string }
const emptySummary: AttendanceSummary = { expected: 0, present: 0, permission: 0, leave: 0, sick: 0, official_duty: 0, absent: 0, pending: 0, checked_out: 0 }

export function KioskPage() {
  const queryClient = useQueryClient()
  const [serverNow, setServerNow] = useState(() => Date.now())
  const context = useQuery({
    queryKey: ['device-context'],
    queryFn: () => api<DeviceContext>('/api/v1/attendance-device/context'),
    retry: false,
    refetchInterval: (query) => query.state.data?.registered ? (query.state.data.qr ? 3000 : 7000) : false,
  })
  const { mutate: sendHeartbeat, status: heartbeatStatus } = useMutation({ mutationFn: () => api<{ server_time: string }>('/api/v1/attendance-device/heartbeat', { method: 'POST' }) })
  const deviceId = context.data?.device?.id
  const serverTime = context.data?.server_time

  useEffect(() => {
    const offset = serverTime ? new Date(serverTime).getTime() - Date.now() : 0
    const clock = window.setInterval(() => setServerNow(Date.now() + offset), 500)
    return () => window.clearInterval(clock)
  }, [serverTime])

  useEffect(() => {
    if (!deviceId) return
    sendHeartbeat()
    const timer = window.setInterval(() => sendHeartbeat(), 15_000)
    return () => window.clearInterval(timer)
  }, [deviceId, sendHeartbeat])

  if (context.isLoading) return <LoadingScreen />
  if (context.isError) return <KioskError onRetry={() => context.refetch()} />
  if (!context.data?.registered) return <ActivationPanel onActivated={() => queryClient.invalidateQueries({ queryKey: ['device-context'] })} />

  const data = context.data
  const day = data.attendance_day
  const summary = data.attendance_summary ?? emptySummary
  const qr = data.qr
  const secondsLeft = qr ? Math.max(0, Math.ceil((new Date(qr.expires_at).getTime() - serverNow) / 1000)) : 0
  const qrValue = qr ? JSON.stringify({ type: 'attendance', token: qr.token }) : ''
  const connected = !context.isError && heartbeatStatus !== 'error'
  const phaseLabel = data.current_phase === 'check_out' ? 'Check-out aktif' : data.current_phase === 'check_in' ? 'Check-in aktif' : day?.is_working_day ? 'Menunggu waktu absensi' : 'Hari libur'

  return (
    <main className="kiosk-screen">
      <header className="kiosk-header"><BrandMark inverse /><span className={`kiosk-connection ${connected ? 'is-online' : 'is-offline'}`}>{connected ? <Wifi size={23} /> : <WifiOff size={23} />}{connected ? 'Tersambung' : 'Koneksi terputus'}</span></header>
      <section className="kiosk-stage">
        <div className="kiosk-qr-panel">
          <div className={`kiosk-qr-box ${qr ? '' : 'is-empty'}`}>{qr ? <QRCodeSVG value={qrValue} size={440} level="M" marginSize={2} /> : <Clock3 size={92} />}</div>
          <div className="kiosk-countdown">{qr ? <RefreshCw size={44} /> : <CalendarDays size={44} />}<span>{qr ? <>QR diperbarui dalam <strong>{secondsLeft.toString().padStart(2, '0')}</strong> detik</> : 'QR tampil saat waktu absensi aktif'}</span></div>
        </div>
        <div className="kiosk-info-panel">
          <div className="kiosk-event-head"><CalendarDays size={64} /><div><StatusBadge tone={data.current_phase === 'check_in' ? 'success' : data.current_phase === 'check_out' ? 'warning' : 'neutral'}>{phaseLabel}</StatusBadge><h1>Absensi Harian</h1><p>{day ? `${formatDate(`${day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')} · ${scheduleRange(day.check_in_target_at, day.check_out_target_at)}` : 'Jadwal belum tersedia'}</p></div></div>
          <div className="kiosk-instruction"><span><MonitorCheck size={54} /></span><div><h2>{qr ? 'Pindai QR untuk mencatat kehadiran' : nextInstruction(data)}</h2><p>{qr ? `Masuk ke akun anggota, lalu pindai untuk ${data.current_phase === 'check_out' ? 'mencatat waktu pulang' : 'mencatat waktu masuk'}.` : day?.is_working_day ? 'Layar akan menampilkan QR secara otomatis sesuai jadwal.' : 'Tidak ada absensi yang perlu dilakukan hari ini.'}</p></div></div>
          <div className="kiosk-summary"><div className="kiosk-ring" style={{ '--progress': progress(summary.present, summary.expected) } as CSSProperties}><ShieldCheck size={40} /></div><p><strong>{summary.present}</strong> dari {summary.expected}<span>anggota hadir hari ini</span></p></div>
          <section className="kiosk-recent"><h2>Kehadiran Terbaru</h2>{data.recent_attendance?.length ? data.recent_attendance.slice(0, 4).map((item) => <div key={item.id}><CheckCircle2 size={25} /><span>{item.member_name}{item.position ? ` (${item.position})` : ''}</span><time>{formatDate(item.recorded_at, 'HH.mm.ss')}</time></div>) : <p className="muted">Belum ada pemindaian hari ini.</p>}</section>
        </div>
      </section>
      <footer className="kiosk-footer"><span><ShieldCheck size={26} /> Gawai aktif · {data.device?.code ?? '-'}</span><span><Clock3 size={26} /> Waktu: {formatDate(new Date(serverNow).toISOString(), 'dd/MM/yyyy HH:mm:ss')} WITA</span><button type="button" onClick={() => document.documentElement.requestFullscreen?.()}><Expand size={25} /> Layar penuh</button></footer>
    </main>
  )
}

function ActivationPanel({ onActivated }: { onActivated: () => void }) {
  const [values, setValues] = useState<ActivationValues>({ activation_code: '' })
  const activate = useMutation({ mutationFn: () => api('/api/v1/attendance-devices/activate', { method: 'POST', ...jsonBody({ activation_code: values.activation_code.toUpperCase(), fingerprint: fingerprint(), screen: { width: window.screen.width, height: window.screen.height }, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }) }), onSuccess: () => { toast.success('Gawai berhasil diaktivasi.'); onActivated() }, onError: (error) => toast.error(error instanceof ApiError ? error.message : 'Aktivasi gawai gagal.') })
  return <main className="kiosk-activation"><section><BrandMark inverse /><h1>Aktivasi Gawai</h1><p>Masukkan kode aktivasi yang dibuat Super Admin untuk layar ini.</p><form onSubmit={(event) => { event.preventDefault(); activate.mutate() }}><label className="field"><span>Kode aktivasi</span><input className="input activation-input" maxLength={12} value={values.activation_code} onChange={(event) => setValues({ activation_code: event.target.value.toUpperCase() })} placeholder="ABCD1234EFGH" required /></label><Button type="submit" disabled={activate.isPending || values.activation_code.length !== 12}>{activate.isPending ? 'Mengaktivasi...' : 'Aktivasi Gawai'}</Button></form></section></main>
}

function KioskError({ onRetry }: { onRetry: () => void }) { return <main className="kiosk-activation"><section><BrandMark inverse /><h1>Layar Gawai tidak dapat dimuat</h1><p>Periksa koneksi lalu coba lagi.</p><Button onClick={onRetry}>Coba lagi</Button></section></main> }
function fingerprint() { return [navigator.userAgent, navigator.language, navigator.platform, `${window.screen.width}x${window.screen.height}`, Intl.DateTimeFormat().resolvedOptions().timeZone].join('|') }
function scheduleRange(start: string | null, end: string | null) { return start && end ? `${formatDate(start, 'HH.mm')}–${formatDate(end, 'HH.mm')} WITA` : 'Hari libur' }
function nextInstruction(data: DeviceContext) { return data.next_working_day ? `Jadwal berikutnya ${formatDate(`${data.next_working_day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM')}` : 'Belum ada jadwal berikutnya' }
function progress(value: number, total: number) { return total ? `${Math.min(360, Math.round((value / total) * 360))}deg` : '0deg' }
