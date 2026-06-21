/* eslint-disable react-refresh/only-export-components */
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, type ReactNode } from 'react'
import { api, ApiError, ensureCsrf, jsonBody } from './api'
import type { User } from '../types'

interface AuthContextValue {
  user: User | null
  loading: boolean
  login: (loginId: string, password: string, remember?: boolean) => Promise<User>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const publicEntry = ['/login', '/register', '/gawai', '/kiosk'].includes(window.location.pathname)
  const query = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: () => api<User>('/api/v1/auth/me'),
    enabled: !publicEntry,
    retry: false,
  })

  const login = async (loginId: string, password: string, remember = false) => {
    await ensureCsrf()
    const user = await api<User>('/api/v1/auth/login', {
      method: 'POST',
      ...jsonBody({ login_id: loginId, password, remember }),
    })
    queryClient.setQueryData(['auth', 'me'], user)
    return user
  }

  const logout = async () => {
    try {
      await api('/api/v1/auth/logout', { method: 'POST' })
    } catch (error) {
      if (!(error instanceof ApiError) || ![401, 419].includes(error.status)) {
        throw error
      }
    }

    queryClient.setQueryData(['auth', 'me'], null)
    queryClient.clear()
  }

  const refresh = async () => {
    await query.refetch()
  }

  return (
    <AuthContext.Provider value={{ user: query.data ?? null, loading: query.isLoading, login, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth harus digunakan di dalam AuthProvider.')
  return value
}
