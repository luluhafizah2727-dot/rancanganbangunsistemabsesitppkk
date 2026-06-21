import type { AttendanceRequest, AttendanceRequestStatus } from '../types'

export function requestTypeLabel(item: Pick<AttendanceRequest, 'type' | 'other_label'>) {
  return item.type === 'other' ? item.other_label || 'Lainnya' : {
    missed_check_in: 'Check-in terlewat',
    missed_check_out: 'Check-out terlewat',
    time_correction: 'Koreksi waktu',
    permission: 'Izin',
    leave: 'Cuti',
    sick: 'Sakit',
    official_duty: 'Dinas',
  }[item.type]
}

export function requestStatusLabel(status: AttendanceRequestStatus) {
  return { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak', cancelled: 'Dibatalkan' }[status]
}

export function requestTone(status: AttendanceRequestStatus): 'warning' | 'success' | 'danger' | 'neutral' {
  return ({ pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'neutral' } as const)[status]
}
