import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Profile, type SocialProvider, type User } from '../api'
import { useAuth } from '../auth'
import { PasswordInput, useToast } from '../components/ui'
import './profile.css'

const PROVIDERS: Record<SocialProvider, string> = { yandex: 'Яндекс', vkid: 'ВКонтакте' }
const EMPTY_PASSWORD = { current_password: '', password: '', password_confirmation: '' }

export default function ProfilePage() {
  const qc = useQueryClient()
  const toast = useToast()
  const { user, updateUser } = useAuth()

  const q = useQuery({ queryKey: ['profile'], queryFn: () => api<Profile>('/profile') })
  const providers = q.data?.providers ?? []

  const [name, setName] = useState(user?.name ?? '')
  const [nameErrors, setNameErrors] = useState<Record<string, string[]>>({})
  useEffect(() => { if (q.data) setName(q.data.data.name) }, [q.data])

  const [pw, setPw] = useState(EMPTY_PASSWORD)
  const [pwErrors, setPwErrors] = useState<Record<string, string[]>>({})

  const saveName = useMutation({
    mutationFn: () => api<{ data: User }>('/profile', { method: 'PUT', body: { name } }),
    onSuccess: (r) => {
      setNameErrors({})
      updateUser(r.data)
      qc.setQueryData<Profile>(['profile'], (old) => old ? { ...old, data: r.data } : old)
      toast('Данные сохранены')
    },
    onError: (e) => { if (e instanceof ApiError) { setNameErrors(e.errors); toast(e.message, true) } },
  })

  const current = q.data?.data ?? user
  // после регистрации через Яндекс или ВКонтакте пароля нет: его задают без текущего
  const hasPassword = current?.password_set !== false

  const changePassword = useMutation({
    mutationFn: () => api<{ message: string }>('/profile/password', {
      method: 'PUT',
      body: hasPassword ? pw : { password: pw.password, password_confirmation: pw.password_confirmation },
    }),
    onSuccess: (r) => {
      setPwErrors({}); setPw(EMPTY_PASSWORD)
      toast(r.message || (hasPassword ? 'Пароль изменён' : 'Пароль задан'))
      if (current && !hasPassword) {
        const next = { ...current, password_set: true }
        updateUser(next)
        qc.setQueryData<Profile>(['profile'], (old) => old ? { ...old, data: next } : old)
      }
    },
    onError: (e) => { if (e instanceof ApiError) { setPwErrors(e.errors); toast(e.message, true) } },
  })

  const nameErr = (k: string) => nameErrors[k]?.[0]
  const pwErr = (k: string) => pwErrors[k]?.[0]
  const nameChanged = name.trim() !== '' && name.trim() !== (current?.name ?? '')

  const submitName = (e: FormEvent) => { e.preventDefault(); saveName.mutate() }
  const submitPassword = (e: FormEvent) => { e.preventDefault(); changePassword.mutate() }

  return (
    <div className="profile-page">
      <section className="profile-section">
        <h2 className="section-title">Личные данные</h2>
        <form className="stack" onSubmit={submitName} noValidate>
          <div className="field">
            <label htmlFor="p-name">Имя</label>
            <input id="p-name" className={`input${nameErr('name') ? ' err' : ''}`} value={name} autoComplete="name"
              onChange={(e) => setName(e.target.value)} />
            {nameErr('name') && <div className="hint err">{nameErr('name')}</div>}
          </div>
          <div className="field">
            <label htmlFor="p-email">Email</label>
            <input id="p-email" className="input" value={current?.email ?? ''} readOnly disabled />
            <div className="hint">По нему вы входите в Sender. Чтобы сменить email, напишите администратору организации.</div>
          </div>
          {providers.length > 0 && (
            <div className="field">
              <span className="label">Вход через</span>
              <div className="profile-providers">
                {providers.map((p) => <span key={p} className="chip">{PROVIDERS[p] ?? p}</span>)}
              </div>
            </div>
          )}
          <div className="profile-actions">
            <button className="btn primary" disabled={saveName.isPending || !nameChanged}>
              {saveName.isPending ? 'Сохраняем…' : 'Сохранить'}
            </button>
          </div>
        </form>
      </section>

      <section className="profile-section">
        <h2 className="section-title">{hasPassword ? 'Пароль' : 'Задать пароль'}</h2>
        <form className="stack" onSubmit={submitPassword} noValidate>
          {hasPassword ? (
            <div className="field">
              <label htmlFor="p-current">Текущий пароль</label>
              <PasswordInput id="p-current" autoComplete="current-password" className={`input${pwErr('current_password') ? ' err' : ''}`}
                value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} />
              {pwErr('current_password') && <div className="hint err">{pwErr('current_password')}</div>}
            </div>
          ) : (
            <div className="hint">Вы вошли через Яндекс или ВКонтакте, и пароля у вас пока нет. Задайте его, чтобы входить по email и паролю и передавать владение организацией.</div>
          )}
          <div className="field">
            <label htmlFor="p-new">Новый пароль</label>
            <PasswordInput id="p-new" autoComplete="new-password" className={`input${pwErr('password') ? ' err' : ''}`}
              value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} />
            <div className={`hint${pwErr('password') ? ' err' : ''}`}>{pwErr('password') ?? 'Не короче 8 символов'}</div>
          </div>
          <div className="field">
            <label htmlFor="p-repeat">Повторите новый пароль</label>
            <PasswordInput id="p-repeat" autoComplete="new-password" className={`input${pwErr('password_confirmation') ? ' err' : ''}`}
              value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} />
            {pwErr('password_confirmation') && <div className="hint err">{pwErr('password_confirmation')}</div>}
          </div>
          <div className="hint">
            После {hasPassword ? 'смены' : 'установки'} пароля на других устройствах нужно будет войти заново, ключи MCP для ИИ-ассистентов будут отозваны. Здесь вы останетесь в Sender.
          </div>
          <div className="profile-actions">
            <button className="btn primary" disabled={changePassword.isPending || (hasPassword && !pw.current_password) || !pw.password || !pw.password_confirmation}>
              {changePassword.isPending ? 'Сохраняем…' : hasPassword ? 'Сменить пароль' : 'Задать пароль'}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}
