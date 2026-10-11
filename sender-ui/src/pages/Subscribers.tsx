import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Plus, RefreshCw, Users } from 'lucide-react'
import { api, ApiError, type ContactList, type ListSource } from '../api'
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
  const [source, setSource] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const sq = useQuery({ queryKey: ['list-sources'], queryFn: () => api<{ data: ListSource[] }>('/list-sources') })
  const sources = sq.data?.data ?? []
  const create = useMutation({
    mutationFn: () => api<{ data: ContactList }>('/lists', { method: 'POST', body: { name, description: description || null, source: source || null } }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['lists'] })
      toast(r.data.source ? `База создана: ${n(r.data.contacts_count)} адресов` : 'База создана')
      nav(r.data.source ? `/subscribers/${r.data.id}` : `/subscribers/${r.data.id}?import=1`)
    },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.name?.[0] ?? e.errors.source?.[0] ?? e.message) : 'Ошибка'),
  })
  const open = () => { setName(''); setDescription(''); setSource(''); setErr(null); setCreating(true) }

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
                      <div style={{ minWidth: 0 }}><div style={{ fontWeight: 500 }}>{l.name}</div>{l.source_label ? <div className="sub row" style={{ gap: 4 }}><RefreshCw size={12} />{l.source_label}</div> : l.description && <div className="sub">{l.description}</div>}</div>
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
            {sources.length > 0 && (
              <div className="field">
                <label id="list-source-label">Откуда адреса</label>
                <div className="seg" role="group" aria-labelledby="list-source-label" style={{ alignSelf: 'flex-start' }}>
                  <button type="button" className={!source ? 'on' : ''} aria-pressed={!source} onClick={() => setSource('')}>Из файла</button>
                  {sources.map((s) => <button key={s.key} type="button" className={source === s.key ? 'on' : ''} aria-pressed={source === s.key} onClick={() => { setSource(s.key); if (!name.trim()) setName('Пользователи сайта') }}>{s.label}</button>)}
                </div>
                {source && <div className="hint">База повторяет этот список: обновляется по кнопке и сама перед каждой рассылкой. Кто выключил согласие, из базы пропадает.</div>}
              </div>
            )}
            <div className="field">
              <label htmlFor="list-desc">Описание <span className="muted">· необязательно</span></label>
              <input id="list-desc" className="input" placeholder="Откуда адреса и для каких рассылок" value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setCreating(false)}>Отмена</button>
              <button className="btn primary" disabled={!name.trim() || create.isPending}>{source ? (create.isPending ? 'Собираем адреса…' : 'Создать базу') : 'Создать и загрузить адреса'}</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
