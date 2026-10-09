import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { api, type Message, type Paged } from '../api'
import { Empty, PageHead, Status, ago, fmtDate } from '../components/ui'

const TABS = [['', 'Все'], ['sent', 'Отправлено'], ['queued', 'В очереди'], ['failed', 'Ошибка'], ['blocked', 'Заблокировано']] as const

export default function Messages() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const qs = params.get('q') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [search, setSearch] = useState(qs)
  const [openId, setOpenId] = useState<string | null>(null)

  const update = (patch: Record<string, string>) => {
    const p = new URLSearchParams(params)
    Object.entries(patch).forEach(([k, v]) => (v ? p.set(k, v) : p.delete(k)))
    if (!('page' in patch)) p.delete('page')
    setParams(p)
  }

  const q = useQuery({
    queryKey: ['messages', status, qs, page],
    queryFn: () => api<Paged<Message>>(`/messages?${new URLSearchParams({ ...(status && { status }), ...(qs && { q: qs }), page: String(page) })}`),
    placeholderData: keepPreviousData,
    refetchInterval: 10000,
  })
  const detail = useQuery({ queryKey: ['message', openId], queryFn: () => api<{ data: Message }>(`/messages/${openId}`), enabled: !!openId })
  const list = q.data?.data ?? []
  const m = detail.data?.data

  return (
    <main className="page" style={{ maxWidth: 1320 }}>
      <PageHead title="Журнал сообщений" sub="Каждое письмо и что с ним произошло" />
      <div className="row" style={{ justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 16 }}>
        <div className="tabs" role="tablist" style={{ flex: 1 }}>
          {TABS.map(([v, l]) => (
            <button key={v} role="tab" aria-selected={status === v} className={`tab${status === v ? ' active' : ''}`} onClick={() => update({ status: v })}>{l}</button>
          ))}
        </div>
        <form className="search" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
          <Search size={16} />
          <input className="input" placeholder="Адрес получателя" aria-label="Поиск по адресу" value={search} onChange={(e) => setSearch(e.target.value)} />
        </form>
      </div>

      <div className={openId ? 'split' : ''} style={openId ? { gridTemplateColumns: 'minmax(0,1.4fr) minmax(0,1fr)' } : undefined}>
        <div className="table-wrap">
          {q.isSuccess && list.length === 0 ? <Empty title="Писем не найдено" text={qs || status ? 'Измените фильтр или поиск.' : 'Здесь появятся письма, когда приложение начнёт отправку.'} /> : (
            <table className="table">
              <thead><tr><th>Статус</th><th>Получатель и тема</th><th>Шаблон</th><th className="r">Создано</th></tr></thead>
              <tbody>
                {list.map((x) => (
                  <tr key={x.id} className={`click${openId === x.id ? ' selected' : ''}`} onClick={() => setOpenId(x.id)}>
                    <td style={{ width: 150 }}><Status value={x.status} /></td>
                    <td><div className="mono" style={{ fontWeight: openId === x.id ? 500 : 400 }}>{x.to}</div><div className="sub">{x.subject}</div></td>
                    <td>{x.template && <span className="chip mono">{x.template}</span>}</td>
                    <td className="r sub" style={{ whiteSpace: 'nowrap' }}>{ago(x.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {q.data && q.data.meta.last_page > 1 && (
            <div className="pager">
              <span>Стр. {q.data.meta.current_page} из {q.data.meta.last_page} · всего {q.data.meta.total}</span>
              <button className="btn sm" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>Назад</button>
              <button className="btn sm" disabled={page >= q.data.meta.last_page} onClick={() => update({ page: String(page + 1) })}>Дальше</button>
            </div>
          )}
        </div>

        {openId && (
          <aside style={{ position: 'sticky', top: 80, borderLeft: '1px solid var(--line)', paddingLeft: 28 }} aria-label="Письмо">
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <span className="nav-title" style={{ padding: 0 }}>Письмо</span>
              <button className="btn icon sm text" onClick={() => setOpenId(null)} aria-label="Закрыть"><X size={16} /></button>
            </div>
            {!m ? <div className="muted">Загрузка…</div> : (
              <div className="stack" style={{ gap: 18, marginTop: 8 }}>
                <div><Status value={m.status} /><h2 style={{ fontSize: 20, fontWeight: 600, letterSpacing: '-0.02em', marginTop: 8 }}>{m.subject}</h2></div>
                {m.error && <div className="callout err"><div><b>Причина</b><span>{m.error}</span></div></div>}
                <dl style={{ display: 'grid', gridTemplateColumns: '110px minmax(0,1fr)', gap: '10px 16px', margin: 0 }}>
                  <dt className="muted">Кому</dt><dd className="mono" style={{ margin: 0 }}>{m.to}</dd>
                  <dt className="muted">От кого</dt><dd className="mono" style={{ margin: 0 }}>{m.from}</dd>
                  <dt className="muted">Шаблон</dt><dd style={{ margin: 0 }}>{m.template ?? '—'}</dd>
                  <dt className="muted">Попыток</dt><dd style={{ margin: 0 }}>{m.attempts}</dd>
                  <dt className="muted">Создано</dt><dd style={{ margin: 0 }}>{fmtDate(m.created_at)}</dd>
                  <dt className="muted">Отправлено</dt><dd style={{ margin: 0 }}>{fmtDate(m.sent_at)}</dd>
                  <dt className="muted">ID</dt><dd className="mono" style={{ margin: 0, overflowWrap: 'anywhere', fontSize: 12 }}>{m.id}</dd>
                </dl>
                {m.variables && Object.keys(m.variables).length > 0 && (
                  <div>
                    <div className="label" style={{ marginBottom: 6 }}>Данные письма</div>
                    <pre className="mono" style={{ margin: 0, padding: '12px 14px', borderRadius: 12, background: 'var(--fill)', whiteSpace: 'pre-wrap', fontSize: 12, color: 'var(--ink-2)' }}>{JSON.stringify(m.variables, null, 2)}</pre>
                  </div>
                )}
              </div>
            )}
          </aside>
        )}
      </div>
    </main>
  )
}
