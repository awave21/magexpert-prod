import { useState, type FormEvent } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Plus } from 'lucide-react'
import { api, ApiError, type AuditEntry, type Paged, type Role, type TeamMember } from '../api'
import { useAuth } from '../auth'
import { Modal, PageHead, ago, fmtDate, useToast } from '../components/ui'

const ROLES: Record<Role, { label: string; text: string }> = {
  admin: { label: 'Администратор', text: 'Всё, включая домены, адреса отправителей, API-ключи, блокировки и команду' },
  editor: { label: 'Редактор', text: 'Письма, базы и рассылки: может запускать и отменять' },
  viewer: { label: 'Просмотр', text: 'Видит всё, ничего не меняет' },
}

export default function Team() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const toast = useToast()
  const isAdmin = user?.role === 'admin'

  const uq = useQuery({ queryKey: ['users'], queryFn: () => api<{ data: TeamMember[] }>('/users') })
  const members = uq.data?.data ?? []

  const [page, setPage] = useState(1)
  const aq = useQuery({
    queryKey: ['audit-log', page],
    queryFn: () => api<Paged<AuditEntry>>(`/audit-log?page=${page}`),
    placeholderData: keepPreviousData,
  })
  const log = aq.data?.data ?? []

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
    <main className="page">
      <PageHead title="Команда" sub="Кто работает в Sender и что делал"
        actions={isAdmin && <button className="btn primary" onClick={() => { setForm({ name: '', email: '', role: 'editor' }); setErrors({}); setAdding(true) }}><Plus size={16} />Добавить</button>} />

      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Человек</th><th>Роль</th><th>Заходил</th><th /></tr></thead>
          <tbody>
            {members.map((m) => {
              const self = m.id === user?.id
              return (
                <tr key={m.id} className={m.is_active ? undefined : 'muted'}>
                  <td><div style={{ fontWeight: 500 }}>{m.name}{self && <span className="sub"> · это вы</span>}</div><div className="sub mono">{m.email}</div></td>
                  <td>
                    {isAdmin && !self ? (
                      <select aria-label={`Роль ${m.name}`} className="select sm" value={m.role} disabled={update.isPending}
                        onChange={(e) => update.mutate({ id: m.id, body: { role: e.target.value as Role } })}>
                        {(Object.keys(ROLES) as Role[]).map((r) => <option key={r} value={r}>{ROLES[r].label}</option>)}
                      </select>
                    ) : <span title={ROLES[m.role].text}>{ROLES[m.role].label}</span>}
                  </td>
                  <td className="sub">{m.is_active ? ago(m.last_seen_at) : 'доступ отключён'}</td>
                  <td className="r">
                    {isAdmin && !self && (
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
      <p className="hint" style={{ marginTop: 8 }}>{(Object.keys(ROLES) as Role[]).map((r) => `${ROLES[r].label} — ${ROLES[r].text.charAt(0).toLowerCase()}${ROLES[r].text.slice(1)}`).join('. ')}.</p>

      <div className="section-title" style={{ marginTop: 36 }}>Журнал действий</div>
      {aq.isSuccess && log.length === 0 ? (
        <p className="hint">Здесь появятся запуски и отмены рассылок, удаление баз и писем, новые ключи и домены.</p>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Когда</th><th>Кто</th><th>Что сделал</th></tr></thead>
            <tbody>
              {log.map((e) => (
                <tr key={e.id}>
                  <td className="sub" style={{ whiteSpace: 'nowrap' }}>{fmtDate(e.created_at)}</td>
                  <td>{e.user}</td>
                  <td>{e.action}{e.subject && <> «{e.subject}»</>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {aq.data && aq.data.meta.last_page > 1 && (
        <div className="pager">
          <span>Стр. {page} из {aq.data.meta.last_page}</span>
          <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Назад</button>
          <button className="btn sm" disabled={page >= aq.data.meta.last_page} onClick={() => setPage(page + 1)}>Дальше</button>
        </div>
      )}

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
                {(Object.keys(ROLES) as Role[]).map((r) => <option key={r} value={r}>{ROLES[r].label}</option>)}
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
    </main>
  )
}
