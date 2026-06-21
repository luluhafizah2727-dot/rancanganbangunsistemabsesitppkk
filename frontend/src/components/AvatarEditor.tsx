import { Camera, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { toast } from 'sonner'
import { api, ApiError } from '../lib/api'
import type { User } from '../types'
import { Avatar } from './Avatar'
import { Button, ConfirmDialog } from './ui'

export function AvatarEditor({ user, onUpdated, showPreview = true }: { user: User; onUpdated: () => Promise<void>; showPreview?: boolean }) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [confirmRemove, setConfirmRemove] = useState(false)

  const upload = async (file: File) => {
    const body = new FormData()
    body.append('avatar', file)
    setUploading(true)
    try {
      await api('/api/v1/profile/avatar', { method: 'POST', body })
      await onUpdated()
      toast.success('Foto profil berhasil diperbarui.')
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Foto profil gagal diperbarui.')
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  const remove = async () => {
    setUploading(true)
    try {
      await api('/api/v1/profile/avatar', { method: 'DELETE' })
      await onUpdated()
      setConfirmRemove(false)
      toast.success('Foto profil berhasil dihapus.')
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Foto profil gagal dihapus.')
    } finally {
      setUploading(false)
    }
  }

  return (
    <>
      <div className="avatar-editor">
        {showPreview ? <Avatar name={user.name} src={user.avatar_url} size="large" /> : null}
        <div>
          <strong>Foto profil</strong>
          <p>Gunakan JPG, PNG, atau WebP maksimal 2 MB.</p>
          <div className="avatar-editor__actions">
            <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(event) => event.target.files?.[0] && upload(event.target.files[0])} />
            <Button type="button" variant="secondary" icon={<Camera size={17} />} disabled={uploading} onClick={() => inputRef.current?.click()}>
              {uploading ? 'Memproses...' : 'Ganti foto'}
            </Button>
            {user.avatar_url ? <Button type="button" variant="danger" icon={<Trash2 size={17} />} disabled={uploading} onClick={() => setConfirmRemove(true)}>Hapus</Button> : null}
          </div>
        </div>
      </div>
      {confirmRemove ? (
        <ConfirmDialog
          title="Hapus foto profil?"
          description="Avatar akan kembali menggunakan inisial nama."
          confirmLabel={uploading ? 'Menghapus...' : 'Hapus foto'}
          confirmVariant="danger"
          disabled={uploading}
          onCancel={() => setConfirmRemove(false)}
          onConfirm={remove}
        />
      ) : null}
    </>
  )
}
