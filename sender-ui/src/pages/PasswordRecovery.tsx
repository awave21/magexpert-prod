import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { api, ApiError, tokenStore } from '../api'
import { PasswordInput } from '../components/ui'
import { AuthAside } from './AuthAside'

export default function PasswordRecovery({ reset = false }: { reset?: boolean }) {
  const [link] = useState(() => new URLSearchParams(window.location.hash.slice(1)))
  const [email, setEmail] = useState(reset ? link.get('email') ?? '' : '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const token = link.get('token') ?? ''
  const invalid = reset && (!token || !email)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true); setError(null)
    try {
      const result = await api<{ message: string }>(reset ? '/reset-password' : '/forgot-password', {
        method: 'POST',
        body: reset ? { email, token, password, password_confirmation: confirmation } : { email },
      })
      setMessage(result.message)
      if (reset) {
        tokenStore.clear()
        setPassword(''); setConfirmation('')
        window.history.replaceState(null, '', window.location.pathname)
      }
    } catch (err) {
      setError(err instanceof ApiError
        ? Object.values(err.errors).flat()[0] ?? err.message
        : 'Не удалось выполнить запрос. Попробуйте ещё раз.')
    } finally { setBusy(false) }
  }

  return (
    <div className="auth">
      <section className="auth-form">
        <div className="auth-card">
          <span className="logo" style={{ width: 40, height: 40, borderRadius: 12 }}><Mail size={20} /></span>
          <div>
            <h1>{reset ? 'Новый пароль' : 'Восстановление пароля'}</h1>
            <p className="muted" style={{ marginTop: 10, fontSize: 15 }}>
              {reset ? 'Задайте новый пароль для входа в MagSender.' : 'Укажите email вашего аккаунта. Мы отправим ссылку для смены пароля.'}
            </p>
          </div>
          {message ? <p role="status">{message}</p> : invalid ? (
            <p role="alert">В ссылке не хватает данных. Запросите новое письмо для восстановления пароля.</p>
          ) : (
            <form className="stack" onSubmit={submit}>
              <div className="field">
                <label htmlFor="recovery-email">Email</label>
                <input id="recovery-email" type="email" autoComplete="email" className="input" value={email} readOnly={reset} onChange={(event) => setEmail(event.target.value)} required />
              </div>
              {reset && <>
                <div className="field">
                  <label htmlFor="new-password">Новый пароль</label>
                  <PasswordInput id="new-password" autoComplete="new-password" className="input" value={password} onChange={(event) => setPassword(event.target.value)} minLength={8} maxLength={128} required />
                  <div className="hint">От 8 до 128 символов</div>
                </div>
                <div className="field">
                  <label htmlFor="confirm-password">Повторите пароль</label>
                  <PasswordInput id="confirm-password" autoComplete="new-password" className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} minLength={8} maxLength={128} required />
                </div>
              </>}
              {error && <div className="hint err" role="alert">{error}</div>}
              <button className="btn primary lg" disabled={busy || !email || (reset && (!password || !confirmation))}>
                {busy ? 'Подождите…' : reset ? 'Сохранить пароль' : 'Отправить ссылку'}
              </button>
            </form>
          )}
          {reset && (!message || error) && <Link to="/forgot-password">Запросить новую ссылку</Link>}
          <p><Link to="/login" reloadDocument>Вернуться ко входу</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
