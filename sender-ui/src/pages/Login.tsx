import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { api, ApiError, type User } from '../api'
import { SocialButtons } from '../components/SocialButtons'
import { useAuth } from '../auth'
import { AuthAside } from './AuthAside'
import { PasswordInput } from '../components/ui'

export default function Login() {
  const { login, signIn } = useAuth()
  const [params, setParams] = useSearchParams()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // возврат после Яндекса или ВКонтакте: одноразовый код меняем на токен, ошибку показываем
  useEffect(() => {
    const code = params.get('oauth')
    const failed = params.get('error')
    if (failed) { setError(failed); setParams({}, { replace: true }) }
    if (!code) return
    setBusy(true)
    setParams({}, { replace: true })
    api<{ token: string; user: { data?: User } & User }>('/oauth/exchange', { method: 'POST', body: { code } })
      .then(signIn)
      .catch((err) => setError(err instanceof ApiError ? err.message : 'Не удалось войти. Попробуйте ещё раз'))
      .finally(() => setBusy(false))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try { await login(email, password) } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Сервер не отвечает. Проверьте, что API запущен.')
    } finally { setBusy(false) }
  }

  return (
    <div className="auth">
      <section className="auth-form">
        <div className="auth-card">
          <span className="logo" style={{ width: 40, height: 40, borderRadius: 12 }}><Mail size={20} /></span>
          <div>
            <h1>Вход</h1>
            <p className="muted" style={{ marginTop: 10, fontSize: 15, color: 'var(--ink-2)' }}>Рассылки, подписчики, контент и журнал отправки вашей организации.</p>
          </div>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="email">Email</label>
              <input id="email" type="email" autoComplete="email" className={`input${error ? ' err' : ''}`} placeholder="name@company.ru" value={email} onChange={(e) => setEmail(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="password">Пароль</label>
              <PasswordInput id="password" autoComplete="current-password" className={`input${error ? ' err' : ''}`} placeholder="Введите пароль" value={password} onChange={(e) => setPassword(e.target.value)} required />
              {error && <div className="hint err" role="alert">{error}</div>}
            </div>
            <button className="btn primary lg" disabled={busy || !email || !password}>{busy ? 'Входим…' : 'Войти'}</button>
          </form>
          <SocialButtons divider="или" />
          <p style={{ color: 'var(--ink-2)' }}>Нет аккаунта? <Link to="/register" style={{ fontWeight: 500 }}>Зарегистрироваться</Link></p>
          <p className="sub"><Link to="/privacy">Политика обработки персональных данных</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
