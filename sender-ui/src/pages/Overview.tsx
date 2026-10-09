import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight } from 'lucide-react'
import { api, type Stats } from '../api'
import { PageHead, Status, ago } from '../components/ui'

export default function Overview() {
  const q = useQuery({ queryKey: ['stats'], queryFn: () => api<Stats>('/stats'), refetchInterval: 15000 })
  const s = q.data
  const max = Math.max(1, ...(s?.days.map((d) => d.sent + d.failed) ?? [1]))

  return (
    <main className="page">
      <PageHead title="Обзор" sub="Что происходит с отправкой писем за последние 7 дней" />
      {q.isError && <div className="callout err"><div><b>Не удалось загрузить статистику</b><span>{(q.error as Error).message}</span></div></div>}
      {s && s.unverified_domains > 0 && (
        <div className="callout warn" style={{ marginBottom: 28 }}>
          <span className="ic"><AlertTriangle size={15} /></span>
          <div><b>Есть неподтверждённые домены: {s.unverified_domains}</b><span>Письма с них не отправляются. <Link to="/domains">Открыть домены</Link></span></div>
        </div>
      )}
      <div className="metrics">
        <div className="metric"><div className="label">Отправлено</div><div className="num">{s?.totals.sent ?? '…'}</div><div className="cap">за 7 дней</div></div>
        <div className="metric"><div className="label">В очереди</div><div className="num" style={{ color: 'var(--warn)' }}>{s?.totals.queued ?? '…'}</div><div className="cap">ждут отправки сейчас</div></div>
        <div className="metric"><div className="label">С ошибкой</div><div className="num" style={{ color: 'var(--err)' }}>{s?.totals.failed ?? '…'}</div><div className="cap">нужно проверить</div></div>
        <div className="metric"><div className="label">Заблокировано</div><div className="num" style={{ color: 'var(--ink-2)' }}>{s?.totals.blocked ?? '…'}</div><div className="cap">домен или адрес под запретом</div></div>
      </div>

      <div className="section-title">Отправка по дням</div>
      {s && (
        <>
          <div className="bars" role="img" aria-label="Число писем по дням">
            {s.days.map((d, i) => {
              const total = d.sent + d.failed
              return (
                <div key={d.date} className={`bar${i === s.days.length - 1 ? ' now' : ''}`} title={`${d.label}: отправлено ${d.sent}, ошибок ${d.failed}`}>
                  <span className="v">{total}</span>
                  <div className="col" style={{ height: `${(total / max) * 100}%` }}>
                    {d.failed > 0 && <div className="e" style={{ height: `${(d.failed / total) * 100}%` }} />}
                  </div>
                </div>
              )
            })}
          </div>
          <div className="bar-labels">{s.days.map((d) => <span key={d.date} style={{ textTransform: 'capitalize' }}>{d.label}</span>)}</div>
        </>
      )}

      <div className="row" style={{ justifyContent: 'space-between', marginTop: 36 }}>
        <div className="section-title" style={{ margin: 0 }}>Последние сообщения</div>
        <Link to="/messages" className="row" style={{ gap: 4, fontWeight: 500 }}>Весь журнал <ArrowRight size={14} /></Link>
      </div>
      <table className="table" style={{ marginTop: 10 }}>
        <tbody>
          {s?.recent.map((m) => (
            <tr key={m.id}>
              <td style={{ width: 150 }}><Status value={m.status} /></td>
              <td><div className="mono">{m.to}</div><div className="sub">{m.subject}</div></td>
              <td className="r sub">{ago(m.created_at)}</td>
            </tr>
          ))}
          {s && s.recent.length === 0 && <tr><td className="muted">Писем пока не было</td></tr>}
        </tbody>
      </table>
    </main>
  )
}
