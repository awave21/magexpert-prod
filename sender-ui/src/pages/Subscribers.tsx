import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Plus, Users } from 'lucide-react'
import { api, ApiError, type ContactList } from '../api'
import { Empty, Modal, PageHead, ago, useToast } from '../components/ui'

const n = (v: number) => v.toLocaleString('ru-RU')

export default function Subscribers() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: ['lists'], queryFn: () => api<{ data: ContactList[] }>('/lists') })
  const lists = q.data?.data ?? []
  const total = lists.reduce((s, l) => s + l.deliverable_count, 0)

  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const create = useMutation({
    mutationFn: () => api<{ data: ContactList }>('/lists', { method: 'POST', body: { name, description: description || null } }),
    onSuccess: (r) => { qc.invalidateQueries({ queryKey: ['lists'] }); toast('База создана'); nav(`/subscribers/${r.data.id}?import=1`) },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const open = () => { setName(''); setDescription(''); setErr(null); setCreating(true) }

  return (
    <main className="page">
      <PageHead title="Подписчики" sub={lists.length ? `${n(total)} подписчиков в ${lists.length} ${lists.length === 1 ? 'базе' : 'базах'}` : 'Базы адресов, по которым идут рассылки'}
        actions={<button className="btn primary" onClick={open}><Plus size={16} />Новая база</button>} />

      {q.isSuccess && lists.length === 0 ? (
        <Empty title="Баз пока нет" text="Создайте базу и загрузите в неё адреса из файла CSV или Excel. Потом выберите её в рассылке."
          action={<button className="btn primary" onClick={open}><Plus size={16} />Новая база</button>} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>База</th><th className="r">Получат рассылку</th><th className="r">Отписались</th><th className="r">Не прошли проверку</th><th>Изменена</th><th /></tr></thead>
            <tbody>
              {lists.map((l) => (
                <tr key={l.id} className="click" onClick={() => nav(`/subscribers/${l.id}`)}>
                  <td>
                    <div className="row" style={{ gap: 12, flexWrap: 'nowrap' }}>
                      <span className="list-ic"><Users size={16} /></span>
                      <div style={{ minWidth: 0 }}><div style={{ fontWeight: 500 }}>{l.name}</div>{l.description && <div className="sub">{l.description}</div>}</div>
                    </div>
                  </td>
                  <td className="r num-cell">{n(l.deliverable_count)}{l.checks.unchecked > 0 && <div className="sub">проверяется {n(l.checks.unchecked)}</div>}</td>
                  <td className="r num-cell muted">{n(l.contacts_count - l.subscribed_count)}</td>
                  <td className="r num-cell muted">{n(l.checks.typo + l.checks.disposable + l.checks.no_mx + l.checks.invalid)}</td>
                  <td className="sub">{ago(l.updated_at)}</td>
                  <td className="r"><ChevronRight size={16} className="muted" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {creating && (
        <Modal title="Новая база" onClose={() => setCreating(false)}>
          <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); setErr(null); create.mutate() }} noValidate>
            <div className="field">
              <label htmlFor="list-name">Название</label>
              <input id="list-name" autoFocus className={`input${err ? ' err' : ''}`} placeholder="Врачи-кардиологи" value={name} onChange={(e) => setName(e.target.value)} />
              {err && <div className="hint err">{err}</div>}
            </div>
            <div className="field">
              <label htmlFor="list-desc">Описание <span className="muted">· необязательно</span></label>
              <input id="list-desc" className="input" placeholder="Откуда адреса и для каких рассылок" value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setCreating(false)}>Отмена</button>
              <button className="btn primary" disabled={!name.trim() || create.isPending}>Создать и загрузить адреса</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
