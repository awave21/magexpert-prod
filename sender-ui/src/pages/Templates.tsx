import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Plus } from 'lucide-react'
import { api, type Template } from '../api'
import { Empty, PageHead, ago } from '../components/ui'

export default function Templates() {
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['templates'], queryFn: () => api<{ data: Template[] }>('/templates') })
  const list = q.data?.data ?? []
  return (
    <main className="page">
      <PageHead title="Шаблоны" sub="Тексты писем, которые приложение отправляет по ключу шаблона"
        actions={<button className="btn primary" onClick={() => nav('/templates/new')}><Plus size={16} />Новый шаблон</button>} />
      {q.isSuccess && list.length === 0 ? (
        <Empty title="Шаблонов пока нет" text="Создайте первый шаблон или установите системные командой sender:install-defaults." />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Название</th><th>Ключ</th><th>Тема письма</th><th>Изменён</th><th /></tr></thead>
            <tbody>
              {list.map((t) => (
                <tr key={t.id} className="click" onClick={() => nav(`/templates/${t.id}`)}>
                  <td style={{ fontWeight: 500 }}>{t.name}</td>
                  <td><span className="chip mono">{t.slug}</span></td>
                  <td className="muted" style={{ maxWidth: 360 }}>{t.subject}</td>
                  <td className="sub">{ago(t.updated_at)}</td>
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
