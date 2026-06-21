import { id } from 'date-fns/locale'
import { formatInTimeZone } from 'date-fns-tz'

const APP_TIMEZONE = 'Asia/Makassar'

export function formatDate(value: string | null | undefined, pattern = 'dd MMM yyyy, HH.mm') {
  return value ? formatInTimeZone(new Date(value), APP_TIMEZONE, pattern, { locale: id }) : '-'
}

export function initials(name: string) {
  return name
    .split(' ')
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
}
