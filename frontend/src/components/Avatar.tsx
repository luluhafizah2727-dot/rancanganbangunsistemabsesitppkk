import { initials } from '../lib/format'

interface AvatarProps {
  name: string
  src?: string | null
  size?: 'small' | 'medium' | 'large'
}

export function Avatar({ name, src, size = 'medium' }: AvatarProps) {
  return (
    <span className={`avatar avatar--${size}`} aria-hidden="true">
      {src ? <img src={src} alt="" /> : initials(name)}
    </span>
  )
}
