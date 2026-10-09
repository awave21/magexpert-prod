import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Plus } from 'lucide-react'
import { api, type Campaign } from '../api'
import { CampaignStatus, Empty, PageHead, ago } from '../components/ui'

const n = (v: number) => v.toLocaleString('ru-RU')

export default function Campaigns() {
  const nav = useNavigate()
  const q = useQuery({
    queryKey: ['campaigns'],
    queryFn: () => api<{ data: Campaign[] }>('/campaigns'),
    refetchInterval: (query) => (query.state.data?.data.some((c) => c.status === 'sending' || (c.stats?.queued ?? 0) > 0) ? 4000 : false),
  })
  const list = q.data?.data ?? []
  const create = () => nav('/campaigns/new')

  return (
    <main className="page">
      <PageHead title="Рассылки" sub="Письмо из раздела «Контент» по базе из раздела «Подписчики»"
        actions={<button className="btn primary" onClick={create}><Plus size={16} />Новая рассылка</button>} />

      {q.isSuccess && list.length === 0 ? (
        <Empty title="Рассылок пока нет" text="Выберите письмо и базу подписчиков, проверьте и отправьте."
          action={<button className="btn primary" onClick={create}><Plus size={16} />Новая рассылка</button>} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Рассылка</th><th>Статус</th><th className="r">Получатели</th><th className="r">Доставлено</th><th>Когда</th><th /></tr></thead>
            <tbody>
              {list.map((c) => (
                <tr key={c.id} className="click" onClick={() => nav(`/campaigns/${c.id}`)}>
                  <td>
                    <div style={{ fontWeight: 500 }}>{c.name}</div>
                    <div className="sub">{c.template?.name ?? 'Письмо не выбрано'} → {c.list?.name ?? 'база не выбрана'}</div>
                  </td>
                  <td><CampaignStatus campaign={c} /></td>
                  <td className="r num-cell">{c.status === 'draft' ? '—' : n(c.recipients_count)}</td>
                  <td className="r num-cell">{c.stats ? n(c.stats.sent) : '—'}</td>
                  <td className="sub">{ago(c.started_at ?? c.created_at)}</td>
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
