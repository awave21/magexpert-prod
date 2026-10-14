import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Filter, Plus } from 'lucide-react'
import { api, type SavedSegment } from '../api'
import { Empty, PageHead, ago } from '../components/ui'
import { usePermissions } from '../auth'
import { describeConditions } from '../segments'

const n = (v: number) => v.toLocaleString('ru-RU')

/** Сегменты: части баз по условиям. В рассылке выбираются так же, как база. */
export default function Segments() {
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['segments'], queryFn: () => api<{ data: SavedSegment[] }>('/segments') })
  const segments = q.data?.data ?? []
  const { canEdit } = usePermissions()
  const add = canEdit && <button className="btn primary" onClick={() => nav('/subscribers/segments/new')}><Plus size={16} />Новый сегмент</button>

  return (
    <main className="page">
      <PageHead title="Сегменты" sub="Часть базы по условиям. В рассылке выбирается так же, как база" actions={add} />

      {q.isSuccess && segments.length === 0 ? (
        <Empty title="Сегментов пока нет" text="Создайте сегмент из базы: только активным, по полю или по тегу. Потом выберите его в рассылке как обычную базу." action={add || undefined} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Сегмент</th><th className="r">Получат рассылку</th><th>База</th><th>Изменён</th><th /></tr></thead>
            <tbody>
              {segments.map((s) => (
                <tr key={s.id} className="click" onClick={() => nav(`/subscribers/segments/${s.id}`)}>
                  <td>
                    <div className="row" style={{ gap: 12, flexWrap: 'nowrap' }}>
                      <span className="list-ic"><Filter size={16} /></span>
                      <div style={{ minWidth: 0 }}><div style={{ fontWeight: 500 }}>{s.name}</div><div className="sub">{describeConditions(s.conditions)}</div></div>
                    </div>
                  </td>
                  <td className="r num-cell">{n(s.count)}<div className="sub">из {n(s.list_count)}</div></td>
                  <td>{s.list.name}</td>
                  <td className="sub">{ago(s.updated_at)}</td>
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
