import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Plus, RefreshCw, Users } from 'lucide-react'
import { api, type ContactList } from '../api'
import { Empty, PageHead, ago } from '../components/ui'
import { usePermissions } from '../auth'

const n = (v: number) => v.toLocaleString('ru-RU')

export default function Subscribers() {
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['lists'], queryFn: () => api<{ data: ContactList[] }>('/lists') })
  const lists = q.data?.data ?? []
  const total = lists.reduce((s, l) => s + l.deliverable_count, 0)

  const { canEdit } = usePermissions()
  const open = () => nav('/subscribers/new')

  return (
    <main className="page">
      <PageHead title="Подписчики" sub={lists.length ? `${n(total)} подписчиков в ${lists.length} ${lists.length === 1 ? 'базе' : 'базах'}` : 'Базы адресов, по которым идут рассылки'} guide="subscriber-lists"
        actions={canEdit && <button className="btn primary" onClick={open}><Plus size={16} />Новая база</button>} />

      {q.isSuccess && lists.length === 0 ? (
        <Empty title="Баз пока нет" text="Создайте базу: вставьте адреса списком или загрузите файл CSV из Excel. Потом выберите её в рассылке."
          action={canEdit ? <button className="btn primary" onClick={open}><Plus size={16} />Новая база</button> : undefined} />
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
                  <td className="r num-cell">{n(l.deliverable_count)}{l.checks.unchecked > 0 && <div className="sub">проверяется {n(l.checks.unchecked)}</div>}{(l.pending_count ?? 0) > 0 && <div className="sub">ждут подтверждения {n(l.pending_count ?? 0)}</div>}</td>
                  <td className="r num-cell muted">{n(l.contacts_count - l.subscribed_count - (l.pending_count ?? 0))}</td>
                  <td className="r num-cell muted">{n(l.checks.typo + l.checks.disposable + l.checks.no_mx + l.checks.invalid)}</td>
                  <td className="sub">{ago(l.updated_at)}</td>
                  <td className="r"><ChevronRight size={16} className="muted" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

    </main>
  )
}
