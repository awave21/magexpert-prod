import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { ApiError } from '../api'
import { useAuth } from '../auth'
import { AuthAside } from './AuthAside'

export default function Login() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

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
            <p className="muted" style={{ marginTop: 10, fontSize: 15, color: 'var(--ink-2)' }}>Домены, шаблоны и журнал отправки вашей организации.</p>
          </div>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="email">Email</label>
              <input id="email" type="email" autoComplete="email" className={`input${error ? ' err' : ''}`} placeholder="name@company.ru" value={email} onChange={(e) => setEmail(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="password">Пароль</label>
              <input id="password" type="password" autoComplete="current-password" className={`input${error ? ' err' : ''}`} placeholder="Введите пароль" value={password} onChange={(e) => setPassword(e.target.value)} required />
              {error && <div className="hint err" role="alert">{error}</div>}
            </div>
            <button className="btn primary lg" disabled={busy || !email || !password}>{busy ? 'Входим…' : 'Войти'}</button>
          </form>
          <div className="divider">или</div>
          <button className="btn lg" type="button" disabled title="Вход через Яндекс появится позже" style={{ marginTop: -12 }}>
            <span style={{ width: 22, height: 22, borderRadius: '50%', background: '#FC3F1D', color: '#fff', display: 'inline-grid', placeItems: 'center', fontSize: 13, fontWeight: 700 }}>Я</span>
            Войти через Яндекс
          </button>
          <p style={{ color: 'var(--ink-2)' }}>Нет аккаунта? <Link to="/register" style={{ fontWeight: 500 }}>Зарегистрироваться</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
