import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Crown, Plus } from 'lucide-react'
import { api, ApiError, type Role, type TeamMember } from '../api'
import { useAuth, usePermissions } from '../auth'
import { Modal, ago, useToast } from '../components/ui'
import { SettingsHead } from '../components/SettingsParts'
import { ASSIGNABLE, MATRIX, MATRIX_ROLES, ROLES } from './roles'

// вкладка «Команда» в настройках: люди, роли, доступ; журнал действий — отдельная вкладка
export default function Team() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const toast = useToast()
  const { isAdmin } = usePermissions()

  const uq = useQuery({ queryKey: ['users'], queryFn: () => api<{ data: TeamMember[] }>('/users') })
  // владелец первым, остальные по имени (как отдаёт сервер)
  const members = [...(uq.data?.data ?? [])].sort((a, b) => Number(b.role === 'owner') - Number(a.role === 'owner'))

  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: Partial<Pick<TeamMember, 'role' | 'is_active'>> }) => api(`/users/${id}`, { method: 'PUT', body }),
    onSuccess: () => { toast('Доступ изменён'); qc.invalidateQueries({ queryKey: ['users'] }); qc.invalidateQueries({ queryKey: ['audit-log'] }) },
    onError: (e) => toast(e instanceof Error ? e.message : 'Ошибка', true),
  })

  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState<{ name: string; email: string; role: Role }>({ name: '', email: '', role: 'editor' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [created, setCreated] = useState<{ email: string; password: string } | null>(null)
  const add = useMutation({
    mutationFn: () => api<{ data: TeamMember; password: string }>('/users', { method: 'POST', body: form }),
    onSuccess: (r) => {
      setAdding(false)
      setCreated({ email: r.data.email, password: r.password })
      qc.invalidateQueries({ queryKey: ['users'] })
      qc.invalidateQueries({ queryKey: ['audit-log'] })
    },
    onError: (e) => setErrors(e instanceof ApiError ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])) : { email: 'Ошибка' }),
  })

  const copy = async (text: string) => {
    try { await navigator.clipboard.writeText(text); toast('Скопировано') } catch { toast('Не удалось скопировать: выделите и скопируйте вручную', true) }
  }

  return (
    <div>
      <SettingsHead title="Команда" sub="Кто работает в Sender и с какими правами" guide="team-and-api"
        actions={isAdmin && <button className="btn primary" onClick={() => { setForm({ name: '', email: '', role: 'editor' }); setErrors({}); setAdding(true) }}><Plus size={16} />Добавить</button>} />

      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Человек</th><th>Роль</th><th>Заходил</th><th /></tr></thead>
          <tbody>
            {members.map((m) => {
              const self = m.id === user?.id
              const owner = m.role === 'owner'
              const editable = isAdmin && !self && !owner
              return (
                <tr key={m.id} className={m.is_active ? undefined : 'muted'}>
                  <td><div style={{ fontWeight: 500 }}>{m.name}{self && <span className="sub"> · это вы</span>}</div><div className="sub mono">{m.email}</div></td>
                  <td>
                    {owner ? (
                      <span className="chip accent role-owner" title={ROLES.owner.text}><Crown size={12} />Владелец</span>
                    ) : editable ? (
                      <select aria-label={`Роль ${m.name}`} className="select sm" value={m.role} disabled={update.isPending}
                        onChange={(e) => update.mutate({ id: m.id, body: { role: e.target.value as Role } })}>
                        {ASSIGNABLE.map((r) => <option key={r} value={r}>{ROLES[r].label}</option>)}
                      </select>
                    ) : <span title={ROLES[m.role]?.text}>{ROLES[m.role]?.label ?? m.role}</span>}
                  </td>
                  <td className="sub">{m.is_active ? ago(m.last_seen_at) : 'доступ отключён'}</td>
                  <td className="r">
                    {editable && (
                      <button className="btn sm text" disabled={update.isPending} onClick={() => update.mutate({ id: m.id, body: { is_active: !m.is_active } })}>
                        {m.is_active ? 'Отключить' : 'Включить'}
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <section className="roles-help" aria-label="Что может каждая роль">
        <h3>Что может каждая роль</h3>
        <table className="roles-matrix">
          <thead><tr><th />{MATRIX_ROLES.map((r) => <th key={r} title={ROLES[r].text}>{ROLES[r].label}</th>)}</tr></thead>
          <tbody>
            {MATRIX.map((row) => (
              <tr key={row.what}>
                <td>{row.what}</td>
                {MATRIX_ROLES.map((r) => row.roles.includes(r)
                  ? <td key={r} className="yes" aria-label="да">✓</td>
                  : <td key={r} className="no" aria-label="нет">—</td>)}
              </tr>
            ))}
          </tbody>
        </table>
        <p className="hint" style={{ marginTop: 8 }}>Свои имя и пароль меняет каждый, в любой роли. Администратор не может изменить или отключить владельца.</p>
      </section>

      {adding && (
        <Modal title="Добавить в команду" onClose={() => setAdding(false)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); setErrors({}); add.mutate() }} noValidate>
            <div className="field">
              <label htmlFor="u-name">Имя</label>
              <input id="u-name" autoFocus className={`input${errors.name ? ' err' : ''}`} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              {errors.name && <div className="hint err">{errors.name}</div>}
            </div>
            <div className="field">
              <label htmlFor="u-email">Email для входа</label>
              <input id="u-email" type="email" className={`input mono${errors.email ? ' err' : ''}`} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
              {errors.email && <div className="hint err">{errors.email}</div>}
            </div>
            <div className="field">
              <label htmlFor="u-role">Роль</label>
              <select id="u-role" className="select" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })}>
                {ASSIGNABLE.map((r) => <option key={r} value={r}>{ROLES[r].label}</option>)}
              </select>
              <div className="hint">{ROLES[form.role].text}</div>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setAdding(false)}>Отмена</button>
              <button className="btn primary" disabled={!form.name.trim() || !form.email.trim() || add.isPending}>Добавить</button>
            </div>
          </form>
        </Modal>
      )}

      {created && (
        <Modal title="Доступ создан" onClose={() => setCreated(null)}>
          <p style={{ color: 'var(--ink-2)', margin: 0 }}>Передайте пароль человеку лично. Больше он нигде не показывается.</p>
          <div className="stack" style={{ marginTop: 16 }}>
            <div className="row" style={{ justifyContent: 'space-between' }}><span className="muted">Логин</span><span className="mono">{created.email}</span></div>
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <span className="muted">Пароль</span>
              <span className="row" style={{ gap: 8 }}><span className="mono">{created.password}</span>
                <button className="btn icon sm ghost" onClick={() => copy(created.password)} aria-label="Скопировать пароль" title="Скопировать пароль"><Copy size={14} /></button></span>
            </div>
          </div>
          <div className="row" style={{ justifyContent: 'flex-end', marginTop: 20 }}>
            <button className="btn primary" onClick={() => setCreated(null)}>Готово</button>
          </div>
        </Modal>
      )}
    </div>
  )
}
