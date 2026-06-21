export type Role = 'super_admin' | 'operator' | 'member'
export type AttendanceStatus = 'pending' | 'present' | 'permission' | 'leave' | 'sick' | 'official_duty' | 'absent'
export type AttendanceRequestType = 'missed_check_in' | 'missed_check_out' | 'time_correction' | 'permission' | 'leave' | 'sick' | 'official_duty' | 'other'
export type AttendanceRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

export interface ApiEnvelope<T> {
  data: T
  meta: Record<string, unknown>
  links: Record<string, string | null>
}

export interface ApiErrorPayload {
  message: string
  code?: string
  errors?: Record<string, string[]>
  request_id?: string
}

export interface User {
  id: string
  login_id: string
  name: string
  email: string | null
  phone: string | null
  avatar_url: string | null
  status: 'pending' | 'active' | 'suspended' | 'rejected'
  roles: Role[]
  must_change_password: boolean
  member: Member | null
  registration_source?: string
  approved_at?: string | null
  last_login_at?: string | null
  created_at?: string | null
}

export type Account = User

export interface Member {
  id: string
  member_number: string
  position: string | null
  department: string | null
  address: string | null
  user: {
    id: string
    name: string
    email: string | null
    phone: string | null
    avatar_url: string | null
    status: string
    must_change_password: boolean
  } | null
}

export interface AttendanceDay {
  id: string
  attendance_date: string
  is_working_day: boolean
  source: 'weekly' | 'exception' | 'legacy_event'
  status: 'scheduled' | 'open' | 'closed' | 'holiday'
  check_in_target_at: string | null
  check_in_opens_at: string | null
  check_in_closes_at: string | null
  check_out_target_at: string | null
  check_out_opens_at: string | null
  check_out_closes_at: string | null
  note: string | null
}

export interface WeeklySchedule {
  id: string
  weekday: number
  is_working_day: boolean
  check_in_time: string | null
  check_in_before_minutes: number
  check_in_after_minutes: number
  check_out_time: string | null
  check_out_before_minutes: number
  check_out_after_minutes: number
}

export interface AttendanceException extends Omit<WeeklySchedule, 'weekday'> {
  attendance_date: string
  note: string
}

export interface AttendanceSettings {
  weekly: WeeklySchedule[]
  exceptions: AttendanceException[]
  timezone: string
}

export interface AttendanceDevice {
  id: string
  code: string
  name: string
  location: string | null
  status: 'pending' | 'active' | 'inactive' | 'revoked'
  ip_allowlist: string[]
  last_ip: string | null
  last_seen_at: string | null
  activated_at: string | null
  revoked_at: string | null
  credential_expires_at?: string | null
}

export interface Attendance {
  id: string
  member: Member
  day: AttendanceDay
  status: AttendanceStatus
  check_in_at: string | null
  check_in_status: 'on_time' | 'late' | null
  check_out_at: string | null
  check_out_status: 'on_time' | 'early' | null
  source: 'system' | 'qr' | 'manual' | 'mixed' | 'legacy' | 'seed' | 'approved_request'
  note: string | null
  check_in_device: string | null
  check_out_device: string | null
}

export interface AttendanceSummary {
  expected: number
  present: number
  permission: number
  leave: number
  sick: number
  official_duty: number
  absent: number
  pending: number
  checked_out: number
}

export interface DashboardData {
  attendance_day: AttendanceDay
  current_phase: 'check_in' | 'check_out' | null
  next_working_day: AttendanceDay | null
  metrics: AttendanceSummary & { total_members: number; active_devices: number; pending_requests: number }
  recent_attendance: Attendance[]
  device_status: Array<AttendanceDevice & { online: boolean }>
  pending_requests: AttendanceRequest[]
  server_time: string
}

export interface QrPayload {
  token: string
  attendance_day_public_id: string
  attendance_date: string
  day: AttendanceDay
  device: Pick<AttendanceDevice, 'id' | 'code' | 'name' | 'location'>
  device_public_id: string
  phase: 'check_in' | 'check_out'
  attendance_summary: AttendanceSummary
  issued_at: string
  expires_at: string
  expires_at_timestamp: number
  server_time: string
}

export interface RecentAttendance {
  id: string
  member_name: string
  position: string | null
  phase: 'check_in' | 'check_out'
  recorded_at: string | null
}

export interface DeviceContext {
  registered: boolean
  device?: AttendanceDevice
  qr?: QrPayload | null
  qr_unavailable_reason?: string | null
  attendance_day?: AttendanceDay
  current_phase?: 'check_in' | 'check_out' | null
  next_working_day?: AttendanceDay | null
  attendance_summary?: AttendanceSummary
  recent_attendance?: RecentAttendance[]
  server_time: string
}

export interface AttendanceRequest {
  id: string
  member: Member
  type: AttendanceRequestType
  date_from: string
  date_to: string
  proposed_check_in_at: string | null
  proposed_check_out_at: string | null
  approved_check_in_at: string | null
  approved_check_out_at: string | null
  other_label: string | null
  reason: string
  has_attachment: boolean
  attachment_name: string | null
  attachment_size: number | null
  status: AttendanceRequestStatus
  review_note: string | null
  reviewer: { id: string; name: string } | null
  reviewed_at: string | null
  cancelled_at: string | null
  created_at: string
}

export type MemberDeviceStatus = 'pending' | 'approved' | 'rejected' | 'revoked'
export type MemberDeviceBindingMode = 'audit_only' | 'approval_required'

export interface MemberDevice {
  id: string
  member: Member
  label: string | null
  status: MemberDeviceStatus
  user_agent: string | null
  ip_address: string | null
  last_seen_at: string | null
  reviewer: { id: string; name: string } | null
  reviewed_at: string | null
  review_note: string | null
  revoked_at: string | null
  created_at: string
}

export interface MemberDeviceContext {
  mode: MemberDeviceBindingMode
  required: boolean
  can_scan: boolean
  device: MemberDevice | null
  message: string
}

export interface AttendanceListResponse {
  day: AttendanceDay
  current_phase: 'check_in' | 'check_out' | null
  attendances: Attendance[]
}

export interface MemberToday {
  day: AttendanceDay
  current_phase: 'check_in' | 'check_out' | null
  attendance: Attendance | null
  server_time: string
}

export interface ScanResponse {
  attendance: Attendance
  phase: 'check_in' | 'check_out'
  recorded_at: string
  already_recorded: boolean
  message: string
}
