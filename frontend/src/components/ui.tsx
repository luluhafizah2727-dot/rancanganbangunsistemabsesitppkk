import { LoaderCircle, X } from 'lucide-react'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { apiFieldErrors } from '../lib/api'

export function LoadingScreen() {
  return (
    <div className="loading-screen" role="status">
      <LoaderCircle className="spin" size={30} />
      <span>Memuat sistem...</span>
    </div>
  )
}

export function Button({ variant = 'primary', icon, children, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
  icon?: ReactNode
}) {
  return (
    <button className={`button button--${variant} ${className}`} {...props}>
      {icon}
      <span>{children}</span>
    </button>
  )
}

export function StatusBadge({ tone = 'neutral', children }: { tone?: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; children: ReactNode }) {
  return <span className={`status status--${tone}`}>{children}</span>
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <header className="page-header">
      <div>
        <h1>{title}</h1>
        {description ? <p>{description}</p> : null}
      </div>
      {actions ? <div className="page-header__actions">{actions}</div> : null}
    </header>
  )
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <div className="empty-state">
      <strong>{title}</strong>
      <p>{description}</p>
    </div>
  )
}

export function Modal({ title, children, onClose, className = '' }: { title: string; children: ReactNode; onClose: () => void; className?: string }) {
  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className={`modal ${className}`} role="dialog" aria-modal="true" aria-labelledby="modal-title" onMouseDown={(event) => event.stopPropagation()}>
        <header className="modal__header">
          <h2 id="modal-title">{title}</h2>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Tutup">
            <X size={20} />
          </button>
        </header>
        {children}
      </section>
    </div>
  )
}

export function ConfirmDialog({
  title,
  description,
  confirmLabel = 'Konfirmasi',
  confirmVariant = 'primary',
  disabled = false,
  children,
  onConfirm,
  onCancel,
}: {
  title: string
  description: string
  confirmLabel?: string
  confirmVariant?: 'primary' | 'danger'
  disabled?: boolean
  children?: ReactNode
  onConfirm: () => void | Promise<void>
  onCancel: () => void
}) {
  return (
    <Modal title={title} onClose={onCancel} className="modal--confirm">
      <div className="modal__content confirm-dialog">
        <p>{description}</p>
        {children}
        <div className="form-actions">
          <Button type="button" variant="secondary" onClick={onCancel} disabled={disabled}>Batal</Button>
          <Button type="button" variant={confirmVariant} onClick={onConfirm} disabled={disabled}>{confirmLabel}</Button>
        </div>
      </div>
    </Modal>
  )
}

export function FormErrorSummary({ error, fallback }: { error: unknown; fallback?: string }) {
  const errors = apiFieldErrors(error)
  if (!errors.length && !fallback) return null

  return (
    <div className="form-error-summary" role="alert">
      {(errors[0] ?? fallback) ? <strong>{errors[0] ?? fallback}</strong> : null}
      {errors.length > 1 ? <small>{errors.length - 1} hal lain perlu diperbaiki.</small> : null}
    </div>
  )
}
