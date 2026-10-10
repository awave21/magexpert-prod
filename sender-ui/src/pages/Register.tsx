import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { api, ApiError, type User } from '../api'
import { SocialButtons } from '../components/SocialButtons'
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
  const [params] = useSearchParams()
  const code = params.get('oauth')
  return code ? <SocialRegister code={code} /> : <PasswordRegister />
}

function PasswordRegister() {
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
          <SocialButtons divider="или зарегистрируйтесь через" />
          <p style={{ color: 'var(--ink-2)' }}>Уже есть аккаунт? <Link to="/" style={{ fontWeight: 500 }}>Войти</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}

// регистрация организации после входа через Яндекс или ВКонтакте: пароль не нужен
function SocialRegister({ code }: { code: string }) {
  const { signIn } = useAuth()
  const [pending, setPending] = useState<{ provider: string; name: string; email: string } | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [organization, setOrganization] = useState('')
  const [email, setEmail] = useState('')
  const [accepted, setAccepted] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api<{ data: { provider: string; name: string; email: string } }>(`/oauth/pending?code=${encodeURIComponent(code)}`)
      .then((r) => setPending(r.data))
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : 'Не удалось загрузить данные'))
  }, [code])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setErrors({})
    try {
      signIn(await api<{ token: string; user: User }>('/oauth/register', { method: 'POST', body: { code, organization, email, accept_policy: accepted } }))
    } catch (err) {
      setErrors(err instanceof ApiError ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { form: 'Сервер не отвечает. Попробуйте позже.' })
    } finally { setBusy(false) }
  }

  return (
    <div className="auth">
      <section className="auth-form">
        <div className="auth-card">
          <span className="logo" style={{ width: 40, height: 40, borderRadius: 12 }}><Mail size={20} /></span>
          {loadError ? (
            <>
              <div><h1>Ссылка устарела</h1><p style={{ marginTop: 10, color: 'var(--ink-2)' }}>{loadError}</p></div>
              <Link to="/register" className="btn primary lg">Начать заново</Link>
            </>
          ) : !pending ? <p className="muted">Загрузка…</p> : (
            <>
              <div>
                <h1>Почти готово</h1>
                <p style={{ marginTop: 10, fontSize: 15, color: 'var(--ink-2)' }}>
                  Вы вошли через {pending.provider}{pending.name ? ` как ${pending.name}` : ''}{pending.email ? ` (${pending.email})` : ''}. Осталось назвать организацию.
                </p>
              </div>
              <form className="stack" onSubmit={submit} noValidate>
                <div className="field">
                  <label htmlFor="org">Название организации</label>
                  <input id="org" autoFocus className={`input${errors.organization ? ' err' : ''}`} placeholder="МагЭксперт" value={organization} onChange={(e) => setOrganization(e.target.value)} />
                  {errors.organization && <div className="hint err" role="alert">{errors.organization}</div>}
                </div>
                {!pending.email && (
                  <div className="field">
                    <label htmlFor="semail">Email</label>
                    <input id="semail" type="email" className={`input${errors.email ? ' err' : ''}`} placeholder="name@company.ru" value={email} onChange={(e) => setEmail(e.target.value)} />
                    {errors.email ? <div className="hint err" role="alert">{errors.email}</div> : <div className="hint">{pending.provider} не передал email: укажите рабочий адрес</div>}
                  </div>
                )}
                <label className="check-row">
                  <input type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                  <span className="sub" style={{ fontSize: 13 }}>Соглашаюсь с <Link to="/privacy" target="_blank">политикой обработки персональных данных</Link> и подтверждаю, что у получателей моих рассылок есть согласие на получение писем</span>
                </label>
                {(errors.accept_policy || errors.code || errors.form) && <div className="hint err" role="alert">{errors.accept_policy ?? errors.code ?? errors.form}</div>}
                <button className="btn primary lg" disabled={busy || !organization.trim() || !accepted || (!pending.email && !email.trim())}>{busy ? 'Создаём…' : 'Создать аккаунт'}</button>
              </form>
            </>
          )}
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
