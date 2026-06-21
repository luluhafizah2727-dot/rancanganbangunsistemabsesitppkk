import { BrowserMultiFormatReader, type IScannerControls } from '@zxing/browser'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Camera, CheckCircle2, Clock3, RefreshCw, ShieldCheck, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Button, StatusBadge } from '../../components/ui'
import { api, ApiError, jsonBody } from '../../lib/api'
import { browserFingerprint } from '../../lib/device'
import { formatDate } from '../../lib/format'
import { extractToken } from '../../lib/qr'
import type { MemberDeviceContext, ScanResponse } from '../../types'

type CameraState = 'idle' | 'starting' | 'active' | 'denied' | 'unsupported'
type DetectedBarcode = { rawValue: string }
type BarcodeDetectorLike = { detect: (source: CanvasImageSource) => Promise<DetectedBarcode[]> }
type BarcodeDetectorConstructor = new (options?: { formats?: string[] }) => BarcodeDetectorLike

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructor
  }
}

export function ScannerPage() {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const controlsRef = useRef<IScannerControls | null>(null)
  const frameRef = useRef<number | null>(null)
  const processingRef = useRef(false)
  const queryClient = useQueryClient()
  const [cameraState, setCameraState] = useState<CameraState>('idle')
  const [lastError, setLastError] = useState<string | null>(null)
  const [scanResult, setScanResult] = useState<ScanResponse | null>(null)
  const device = useQuery({ queryKey: ['member-device-current'], queryFn: () => api<MemberDeviceContext>('/api/v1/member-devices/current'), retry: false })

  const stopCamera = useCallback(() => {
    controlsRef.current?.stop()
    controlsRef.current = null
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    if (frameRef.current) window.cancelAnimationFrame(frameRef.current)
    frameRef.current = null
    setCameraState((current) => (current === 'active' || current === 'starting' ? 'idle' : current))
  }, [])

  const scan = useMutation({
    mutationFn: (token: string) => api<ScanResponse>('/api/v1/attendance/scans', { method: 'POST', ...jsonBody({ token }) }),
    onSuccess: (data) => {
      setScanResult(data)
      setLastError(null)
      queryClient.invalidateQueries({ queryKey: ['member-history'] })
      queryClient.invalidateQueries({ queryKey: ['member-upcoming'] })
      queryClient.invalidateQueries({ queryKey: ['member-device-current'] })
      toast.success(data.message)
      stopCamera()
    },
    onError: (error) => {
      const message = error instanceof ApiError ? error.message : 'QR tidak dapat diproses.'
      setLastError(message)
      toast.error(message)
    },
    onSettled: () => {
      processingRef.current = false
    },
  })

  const processScan = useCallback((rawValue: string) => {
    if (processingRef.current || scan.isPending) return

    const token = extractToken(rawValue)
    if (!token || token.length < 32) {
      setLastError('QR tidak dikenali untuk absensi.')
      return
    }

    processingRef.current = true
    scan.mutate(token)
  }, [scan])

  const startCamera = async () => {
    setLastError(null)
    setScanResult(null)

    if (device.data && !device.data.can_scan) {
      setLastError(device.data.message)
      return
    }

    if (!navigator.mediaDevices?.getUserMedia || !videoRef.current) {
      setCameraState('unsupported')
      return
    }

    setCameraState('starting')
    try {
      if (window.BarcodeDetector) {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
        streamRef.current = stream
        videoRef.current.srcObject = stream
        await videoRef.current.play()
        setCameraState('active')
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] })

        const tick = async () => {
          if (!videoRef.current || !streamRef.current) return
          try {
            const codes = await detector.detect(videoRef.current)
            if (codes[0]?.rawValue) processScan(codes[0].rawValue)
          } finally {
            frameRef.current = window.requestAnimationFrame(tick)
          }
        }

        frameRef.current = window.requestAnimationFrame(tick)
        return
      }

      const reader = new BrowserMultiFormatReader()
      controlsRef.current = await reader.decodeFromVideoDevice(undefined, videoRef.current, (result) => {
        const text = result?.getText()
        if (text) processScan(text)
      })
      setCameraState('active')
    } catch {
      setCameraState('denied')
      setLastError('Izin kamera ditolak atau perangkat tidak memiliki kamera yang dapat digunakan.')
    }
  }

  useEffect(() => stopCamera, [stopCamera])

  const requestDevice = useMutation({
    mutationFn: () => api('/api/v1/member-devices', { method: 'POST', ...jsonBody({ label: deviceLabel(), fingerprint: browserFingerprint() }) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['member-device-current'] })
      toast.success('Permohonan perangkat dikirim.')
    },
    onError: (error) => toast.error(error instanceof ApiError ? error.message : 'Permohonan perangkat gagal dikirim.'),
  })

  if (scanResult) {
    return (
      <div className="scan-success">
        <span><CheckCircle2 size={72} /></span>
        <h1>{scanResult.phase === 'check_out' ? 'Check-out berhasil' : 'Check-in berhasil'}</h1>
        <time>{formatDate(scanResult.recorded_at, 'HH.mm')} WITA</time>
        <section className="success-event-card">
          <h2>Absensi Harian</h2>
          <p>{formatDate(`${scanResult.attendance.day.attendance_date}T00:00:00+08:00`, 'EEEE, dd MMMM yyyy')}</p>
        </section>
        {scanResult.already_recorded ? <StatusBadge tone="info">Sudah tercatat sebelumnya</StatusBadge> : null}
        <Button onClick={() => setScanResult(null)}>Pindai lagi</Button>
      </div>
    )
  }

  return (
    <div className="scanner-page">
      <header className="scanner-header">
        <button type="button" className="icon-button" onClick={stopCamera} aria-label="Tutup kamera"><X size={24} /></button>
        <h1>Pindai QR</h1>
        <ShieldCheck size={22} />
      </header>
      <p className="scanner-copy">Arahkan kamera ke QR pada layar absensi.</p>

      <DeviceGate context={device.data} loading={device.isLoading} onRequest={() => requestDevice.mutate()} requesting={requestDevice.isPending} />

      <section className={`camera-frame camera-frame--${cameraState}`}>
        <video ref={videoRef} muted playsInline />
        <span className="scan-corner scan-corner--tl" />
        <span className="scan-corner scan-corner--tr" />
        <span className="scan-corner scan-corner--bl" />
        <span className="scan-corner scan-corner--br" />
        {cameraState !== 'active' ? (
          <div className="camera-placeholder">
            {cameraState === 'starting' ? <RefreshCw className="spin" size={42} /> : <Camera size={46} />}
            <strong>{cameraLabel(cameraState)}</strong>
          </div>
        ) : null}
      </section>

      {lastError ? <p className="scanner-error">{lastError}</p> : null}

      <div className="scanner-actions">
        <Button onClick={startCamera} disabled={cameraState === 'starting' || scan.isPending || Boolean(device.data && !device.data.can_scan)} icon={<Camera size={18} />}>{cameraState === 'active' ? 'Memindai...' : 'Mulai kamera'}</Button>
        {cameraState === 'active' ? <Button variant="secondary" onClick={stopCamera}>Matikan kamera</Button> : null}
      </div>

    </div>
  )
}

function DeviceGate({ context, loading, requesting, onRequest }: { context?: MemberDeviceContext; loading: boolean; requesting: boolean; onRequest: () => void }) {
  if (loading) {
    return <section className="scanner-session-card"><RefreshCw className="spin" size={34} /><div><strong>Memeriksa perangkat</strong><p>Mohon tunggu sebentar.</p></div><i /></section>
  }

  if (!context) return null

  if (context.can_scan) {
    return <section className="scanner-session-card"><ShieldCheck size={34} /><div><strong>{context.device?.status === 'approved' ? 'Perangkat disetujui' : 'Akun siap digunakan'}</strong><p>{context.message}</p></div><StatusBadge tone="success">Siap</StatusBadge></section>
  }

  const pending = context.device?.status === 'pending'
  const rejected = context.device?.status === 'rejected' || context.device?.status === 'revoked'

  return (
    <section className={`scanner-session-card scanner-session-card--blocked ${rejected ? 'scanner-session-card--danger' : ''}`}>
      {pending ? <Clock3 size={34} /> : <ShieldCheck size={34} />}
      <div>
        <strong>{pending ? 'Menunggu persetujuan' : rejected ? 'Perangkat tidak aktif' : 'Ajukan perangkat ini'}</strong>
        <p>{context.message}</p>
      </div>
      {!context.device ? <Button onClick={onRequest} disabled={requesting}>{requesting ? 'Mengirim...' : 'Ajukan'}</Button> : <StatusBadge tone={pending ? 'warning' : 'danger'}>{pending ? 'Menunggu' : 'Tidak aktif'}</StatusBadge>}
    </section>
  )
}

function deviceLabel() {
  const ua = navigator.userAgent
  if (/Android/i.test(ua)) return 'Ponsel Android'
  if (/iPhone|iPad/i.test(ua)) return 'Perangkat iOS'
  if (/Windows/i.test(ua)) return 'Perangkat Windows'
  if (/Mac/i.test(ua)) return 'Perangkat Mac'

  return 'Perangkat anggota'
}

function cameraLabel(state: CameraState) {
  if (state === 'starting') return 'Menyalakan kamera...'
  if (state === 'denied') return 'Kamera belum diizinkan'
  if (state === 'unsupported') return 'Kamera tidak tersedia'

  return 'Kamera siap digunakan'
}
