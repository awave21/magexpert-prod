import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, setUnauthorizedHandler, tokenStore, type User } from './api'

type Ctx = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  register: (data: { organization: string; name: string; email: string; password: string; accept_policy: boolean }) => Promise<void>
  logout: () => Promise<void>
}
const AuthContext = createContext<Ctx>(null as never)
export const useAuth = () => useContext(AuthContext)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(!!tokenStore.get())

  useEffect(() => {
    setUnauthorizedHandler(() => { tokenStore.clear(); setUser(null) })
    if (!tokenStore.get()) return
    api<{ data: User }>('/me')
      .then((r) => setUser(r.data))
      .catch(() => tokenStore.clear())
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const r = await api<{ token: string; user: { data?: User } & User }>('/login', { method: 'POST', body: { email, password } })
    tokenStore.set(r.token)
    setUser(r.user.data ?? r.user)
  }, [])

  const register = useCallback(async (data: { organization: string; name: string; email: string; password: string; accept_policy: boolean }) => {
    const r = await api<{ token: string; user: { data?: User } & User }>('/register', { method: 'POST', body: data })
    tokenStore.set(r.token)
    setUser(r.user.data ?? r.user)
  }, [])

  const logout = useCallback(async () => {
    try { await api('/logout', { method: 'POST' }) } catch { /* токен всё равно удаляем */ }
    tokenStore.clear()
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, loading, login, register, logout }), [user, loading, login, register, logout])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
