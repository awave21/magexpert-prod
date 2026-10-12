import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { api, ApiError, type OrganizationInfo, type TeamMember } from '../api'
import { useAuth } from '../auth'
import { PasswordInput, useToast } from '../components/ui'
import { SettingsHead } from '../components/SettingsParts'
import { ROLES } from './roles'

// вкладка «Организация»: название и передача владения, только для владельца
export default function SettingsOrganization() {
  const qc = useQueryClient()
  const toast = useToast()
  const { user, refresh } = useAuth()
  const navigate = useNavigate()

  const oq = useQuery({ queryKey: ['organization'], queryFn: () => api<{ data: OrganizationInfo }>('/organization') })
  const uq = useQuery({ queryKey: ['users'], queryFn: () => api<{ data: TeamMember[] }>('/users') })
  const candidates = (uq.data?.data ?? []).filter((m) => m.is_active && m.id !== user?.id)

  const [name, setName] = useState('')
  const [nameError, setNameError] = useState<string | null>(null)
  useEffect(() => { if (oq.data) setName(oq.data.data.name) }, [oq.data])

  const rename = useMutation({
    mutationFn: () => api<{ data: OrganizationInfo }>('/organization', { method: 'PUT', body: { name } }),
    onSuccess: (r) => {
      setNameError(null)
      qc.setQueryData(['organization'], r)
      qc.invalidateQueries({ queryKey: ['audit-log'] })
      refresh().catch(() => undefined)
      toast('Название сохранено')
    },
    onError: (e) => { if (e instanceof ApiError) { setNameError(e.errors.name?.[0] ?? e.message); toast(e.message, true) } },
  })

  const [target, setTarget] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const transfer = useMutation({
    mutationFn: () => api<{ data: OrganizationInfo; message: string }>('/organization/transfer', { method: 'POST', body: { user_id: Number(target), current_password: password } }),
    onSuccess: async (r) => {
      setErrors({}); setPassword(''); setTarget(''); setConfirm(false)
      toast(r.message || 'Владение передано')
      qc.invalidateQueries({ queryKey: ['users'] })
      qc.invalidateQueries({ queryKey: ['organization'] })
      qc.invalidateQueries({ queryKey: ['audit-log'] })
      // роль изменилась: вкладка «Организация» пропадёт, остальное станет как у администратора
      await refresh().catch(() => undefined)
      navigate('/settings/team', { replace: true })
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])) : {})
      toast(e instanceof Error ? e.message : 'Ошибка', true)
    },
  })

  const chosen = candidates.find((m) => String(m.id) === target)
  const nameChanged = name.trim().length >= 2 && name.trim() !== (oq.data?.data.name ?? '')

  return (
    <div>
      <SettingsHead title="Организация" sub="Это видит и меняет только владелец" />

      <section className="org-section">
        <h3>Название</h3>
        <form className="stack" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); rename.mutate() }}>
          <div className="field">
            <label htmlFor="org-name">Название организации</label>
            <input id="org-name" className={`input${nameError ? ' err' : ''}`} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} />
            <div className={`hint${nameError ? ' err' : ''}`}>{nameError ?? 'Видно всей команде в шапке Sender'}</div>
          </div>
          <div className="org-actions">
            <button className="btn primary" disabled={!nameChanged || rename.isPending}>{rename.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
          </div>
        </form>
      </section>

      <section className="org-section">
        <h3>Передать владение</h3>
        <div className="callout warn" style={{ marginBottom: 16 }}>
          <span className="ic"><AlertTriangle size={16} /></span>
          <div>
            <b>Владелец в организации один</b>
            <span>Новый владелец получит все права, включая передачу владения. Вы станете администратором и вернуть владение сами не сможете — только если новый владелец передаст его обратно.</span>
          </div>
        </div>
        {user?.password_set === false ? (
          <p className="hint">
            Владение передаётся с подтверждением паролем, а вы входите через Яндекс или ВКонтакте и пароля пока нет.{' '}
            <Link to="/profile">Задайте пароль в профиле</Link>, затем вернитесь сюда.
          </p>
        ) : uq.isSuccess && candidates.length === 0 ? (
          <p className="hint">Передать некому: сначала добавьте человека во вкладке «Команда».</p>
        ) : (
          <form className="stack" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); setErrors({}); transfer.mutate() }}>
            <div className="field">
              <label htmlFor="org-target">Кому</label>
              <select id="org-target" className={`select${errors.user_id ? ' err' : ''}`} value={target} onChange={(e) => { setTarget(e.target.value); setConfirm(false) }}>
                <option value="">Выберите участника</option>
                {candidates.map((m) => <option key={m.id} value={m.id}>{m.name} · {m.email} ({ROLES[m.role]?.label ?? m.role})</option>)}
              </select>
              {errors.user_id && <div className="hint err">{errors.user_id}</div>}
            </div>
            <div className="field">
              <label htmlFor="org-password">Ваш текущий пароль</label>
              <PasswordInput id="org-password" autoComplete="current-password" className={`input${errors.current_password ? ' err' : ''}`}
                value={password} onChange={(e) => setPassword(e.target.value)} />
              {errors.current_password && <div className="hint err">{errors.current_password}</div>}
            </div>
            <label className="row" style={{ gap: 8, alignItems: 'flex-start' }}>
              <input type="checkbox" checked={confirm} onChange={(e) => setConfirm(e.target.checked)} />
              <span>{chosen ? `Понимаю: владельцем станет ${chosen.name}, а я — администратором` : 'Понимаю: я перестану быть владельцем'}</span>
            </label>
            <div className="org-actions">
              <button className="btn danger" disabled={!target || !password || !confirm || transfer.isPending}>
                {transfer.isPending ? 'Передаём…' : 'Передать владение'}
              </button>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}
