import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Eye, MousePointerClick, Search, X } from 'lucide-react'
import { api, type Message, type Paged } from '../api'
import { Empty, PageHead, Status, ago, fmtDate } from '../components/ui'

const TABS = [['', 'Все'], ['delivered', 'Доставлено'], ['opened', 'Открыто'], ['clicked', 'Клик'], ['sent', 'Отправлено'], ['queued', 'В очереди'], ['bounced', 'Не доставлено'], ['failed', 'Ошибка'], ['blocked', 'Заблокировано']] as const

// цепочка письма: создано → отправлено → доставлено → открыто → клик
function Timeline({ m }: { m: Message }) {
  const steps: { label: string; at: string | null; tone: 'ok' | 'err' | 'wait'; note?: string }[] = [
    { label: 'Создано', at: m.created_at, tone: 'ok' },
    { label: 'Отправлено', at: m.sent_at, tone: m.sent_at ? 'ok' : 'wait', note: m.sent_at ? 'принято нашим почтовым сервером' : undefined },
    m.status === 'bounced'
      ? { label: 'Не доставлено', at: m.events?.find((e) => e.type === 'bounced')?.created_at ?? null, tone: 'err', note: 'сервер получателя отказал' }
      : { label: 'Доставлено', at: m.delivered_at, tone: m.delivered_at ? 'ok' : 'wait', note: m.delivered_at ? 'принято сервером получателя' : m.sent_at ? 'ждём ответа сервера получателя' : undefined },
  ]
  if (m.tracked) {
    steps.push({ label: 'Открыто', at: m.opened_at, tone: m.opened_at ? 'ok' : 'wait', note: m.opens_count > 1 ? `открывали ${m.opens_count} раз` : undefined })
    steps.push({ label: 'Переход по ссылке', at: m.clicked_at, tone: m.clicked_at ? 'ok' : 'wait', note: m.clicks_count > 1 ? `${m.clicks_count} переходов` : undefined })
  }
  return (
    <ol className="timeline">
      {steps.map((st) => (
        <li key={st.label} className={st.at ? st.tone : 'wait'}>
          <i /><div><b>{st.label}</b>{st.at && <span className="sub"> · {fmtDate(st.at)}</span>}{st.note && <div className="sub">{st.note}</div>}</div>
        </li>
      ))}
    </ol>
  )
}

const EVENT_LABEL: Record<string, string> = { delivered: 'Доставлено', deferred: 'Временная ошибка', bounced: 'Отказ', open: 'Открыто', click: 'Переход' }

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
    <main className="page">
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
              <thead><tr><th>Статус</th><th>Получатель и тема</th><th>Письмо</th><th className="r">Создано</th></tr></thead>
              <tbody>
                {list.map((x) => (
                  <tr key={x.id} className={`click${openId === x.id ? ' selected' : ''}`} onClick={() => setOpenId(x.id)}>
                    <td style={{ width: 170 }}>
                      <Status value={x.status} />
                      {(x.opened_at || x.clicked_at) && (
                        <div className="engage">
                          {x.opened_at && <span title={`Открыто ${fmtDate(x.opened_at)}`}><Eye size={13} />открыто</span>}
                          {x.clicked_at && <span title={`Переход ${fmtDate(x.clicked_at)}`}><MousePointerClick size={13} />клик</span>}
                        </div>
                      )}
                    </td>
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
                {m.error && <div className={`callout ${m.status === 'sent' ? 'warn' : 'err'}`}><div><b>{m.status === 'sent' ? 'Повторная попытка' : 'Причина'}</b><span>{m.error}</span></div></div>}
                <Timeline m={m} />
                <dl style={{ display: 'grid', gridTemplateColumns: '110px minmax(0,1fr)', gap: '10px 16px', margin: 0 }}>
                  <dt className="muted">Кому</dt><dd className="mono" style={{ margin: 0 }}>{m.to}</dd>
                  <dt className="muted">От кого</dt><dd className="mono" style={{ margin: 0 }}>{m.from}</dd>
                  <dt className="muted">Письмо</dt><dd style={{ margin: 0 }}>{m.template ?? '—'}</dd>
                  <dt className="muted">Попыток</dt><dd style={{ margin: 0 }}>{m.attempts}</dd>
                  <dt className="muted">Создано</dt><dd style={{ margin: 0 }}>{fmtDate(m.created_at)}</dd>
                  <dt className="muted">Отправлено</dt><dd style={{ margin: 0 }}>{fmtDate(m.sent_at)}</dd>
                  <dt className="muted">ID</dt><dd className="mono" style={{ margin: 0, overflowWrap: 'anywhere', fontSize: 12 }}>{m.id}</dd>
                </dl>
                {m.events && m.events.length > 0 && (
                  <div>
                    <div className="label" style={{ marginBottom: 6 }}>События</div>
                    <table className="table events-table"><tbody>
                      {m.events.map((e, i) => (
                        <tr key={i}>
                          <td className="sub" style={{ whiteSpace: 'nowrap', width: 110 }}>{fmtDate(e.created_at)}</td>
                          <td>{EVENT_LABEL[e.type] ?? e.type}{e.is_auto && <span className="chip" style={{ marginLeft: 6 }} title="Почтовая программа или антивирус, а не человек">авто</span>}
                            {e.detail && <div className="sub mono" style={{ overflowWrap: 'anywhere' }}>{e.detail}</div>}</td>
                        </tr>
                      ))}
                    </tbody></table>
                  </div>
                )}
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
