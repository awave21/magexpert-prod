import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { ApiError } from '../api'
import { useAuth } from '../auth'
import { AuthAside } from './AuthAside'
import { PasswordInput } from '../components/ui'

type Field = 'organization' | 'name' | 'email' | 'password' | 'accept_policy'

const FIELDS: [Exclude<Field, 'accept_policy'>, string, string, string, string][] = [
  ['organization', 'Название организации', 'text', 'МагЭксперт', 'organization'],
  ['name', 'Ваше имя', 'text', 'Анна Иванова', 'name'],
  ['email', 'Email', 'email', 'name@company.ru', 'email'],
  ['password', 'Пароль', 'password', 'Не короче 8 символов', 'new-password'],
]

export default function Register() {
  const { register } = useAuth()
  const [form, setForm] = useState<Record<Exclude<Field, 'accept_policy'>, string>>({ organization: '', name: '', email: '', password: '' })
  const [accepted, setAccepted] = useState(false)
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null); setErrors({})
    try { await register({ ...form, accept_policy: accepted }) } catch (err) {
      if (err instanceof ApiError) {
        const fieldErrors: Partial<Record<Field, string>> = {}
        for (const [key, messages] of Object.entries(err.errors)) fieldErrors[key as Field] = messages[0]
        setErrors(fieldErrors)
        if (Object.keys(fieldErrors).length === 0) setError(err.message)
      } else {
        setError('Сервер не отвечает. Попробуйте позже.')
      }
    } finally { setBusy(false) }
  }

  const ready = Object.values(form).every((v) => v.trim() !== '') && accepted

  return (
    <div className="auth">
      <section className="auth-form">
        <div className="auth-card">
          <span className="logo" style={{ width: 40, height: 40, borderRadius: 12 }}><Mail size={20} /></span>
          <div>
            <h1>Создать аккаунт</h1>
            <p style={{ marginTop: 10, fontSize: 15, color: 'var(--ink-2)' }}>Организация создаётся сразу: подключите домен и отправьте первое письмо.</p>
          </div>
          <form className="stack" onSubmit={submit} noValidate>
            {FIELDS.map(([id, label, type, ph, autoComplete]) => (
              <div className="field" key={id}>
                <label htmlFor={id}>{label}</label>
                {type === 'password' ? (
                  <PasswordInput
                    id={id}
                    autoComplete={autoComplete}
                    className={`input${errors[id] ? ' err' : ''}`}
                    placeholder={ph}
                    value={form[id]}
                    onChange={(e) => setForm({ ...form, [id]: e.target.value })}
                    required
                  />
                ) : (
                  <input
                    id={id}
                    type={type}
                    autoComplete={autoComplete}
                    className={`input${errors[id] ? ' err' : ''}`}
                    placeholder={ph}
                    value={form[id]}
                    onChange={(e) => setForm({ ...form, [id]: e.target.value })}
                    required
                  />
                )}
                {errors[id] && <div className="hint err" role="alert">{errors[id]}</div>}
              </div>
            ))}
            <label className="check-row">
              <input type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
              <span className="sub" style={{ fontSize: 13 }}>Соглашаюсь с <Link to="/privacy" target="_blank">политикой обработки персональных данных</Link> и подтверждаю, что у получателей моих рассылок есть согласие на получение писем</span>
            </label>
            {errors.accept_policy && <div className="hint err" role="alert">{errors.accept_policy}</div>}
            {error && <div className="hint err" role="alert">{error}</div>}
            <button className="btn primary lg" disabled={busy || !ready}>{busy ? 'Создаём…' : 'Создать аккаунт'}</button>
          </form>
          <p style={{ color: 'var(--ink-2)' }}>Уже есть аккаунт? <Link to="/" style={{ fontWeight: 500 }}>Войти</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
