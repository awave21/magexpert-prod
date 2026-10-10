import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, type SenderAddress, type Template } from '../api'
import { CopyButton, PageHead } from '../components/ui'

const VAR_RE = /\{\{\s*(?:#if\s+)?([a-zA-Z0-9_.]+)\s*\}\}/g
const varsOf = (t: Template) => {
  const found = new Set<string>()
  for (const src of [t.subject, t.preheader ?? '', t.body_html]) for (const m of src.matchAll(VAR_RE)) found.add(m[1])
  return [...found]
}

function Code({ text }: { text: string }) {
  return <div className="code-block"><pre className="mono">{text}</pre><CopyButton text={text} label="Скопировать" /></div>
}

export default function ApiDocs() {
  const tq = useQuery({ queryKey: ['templates'], queryFn: () => api<{ data: Template[] }>('/templates') })
  const aq = useQuery({ queryKey: ['sender-addresses'], queryFn: () => api<{ data: SenderAddress[] }>('/sender-addresses') })
  const templates = useMemo(() => tq.data?.data ?? [], [tq.data])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const current = templates.find((t) => t.id === selectedId) ?? templates[0]
  const origin = window.location.origin
  const endpoint = `${origin}/api/sender/v1/messages`

  const body = current
    ? JSON.stringify({ template: current.id, to: 'anna@example.com', data: Object.fromEntries(varsOf(current).map((k) => [k, '…'])) }, null, 2)
    : '{\n  "template": 12,\n  "to": "anna@example.com",\n  "data": {}\n}'
  const curl = `curl -X POST ${endpoint} \\\n  -H "Authorization: Bearer mxs_ВАШ_КЛЮЧ" \\\n  -H "Content-Type: application/json" \\\n  -d '${body.replace(/\n\s*/g, ' ')}'`
  const sender = (t: Template) => aq.data?.data.find((a) => a.id === t.sender_address_id)

  return (
    <main className="page api-page">
      <PageHead title="API" sub="Как приложению отправлять письма через Sender" />

      <section className="api-sec">
        <h2>Отправка письма</h2>
        <p>Приложение передаёт только ID шаблона, адрес получателя и значения переменных. Отправитель, имя, тема, прехедер и адрес для ответов берутся из настроек шаблона.</p>
        <div className="api-endpoint"><span className="chip accent">POST</span><span className="mono">{endpoint}</span><CopyButton text={endpoint} label="Скопировать адрес" /></div>
        <div className="api-kv">
          <span>Заголовок</span><span className="mono">Authorization: Bearer mxs_…</span>
          <span>Ключ</span><span>Создаётся в разделе <Link to="/api-keys">«API-ключи»</Link> и показывается один раз.</span>
        </div>
        {templates.length > 1 && (
          <div className="row" style={{ gap: 10 }}>
            <span className="sub">Пример для шаблона</span>
            <select className="select" style={{ height: 34, width: 'auto' }} value={current?.id ?? ''} onChange={(e) => setSelectedId(Number(e.target.value))} aria-label="Шаблон для примера">
              {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
        )}
        <h3>Тело запроса</h3>
        <Code text={body} />
        <h3>То же через curl</h3>
        <Code text={curl} />
      </section>

      <section className="api-sec">
        <h2>Ответ</h2>
        <p>Sender сразу ставит письмо в очередь и отвечает, не дожидаясь отправки.</p>
        <Code text={'{\n  "id": "9f6c2a1e-…",\n  "status": "queued",\n  "to": "anna@example.com",\n  "subject": "Вы зарегистрированы: …"\n}'} />
        <table className="table api-table">
          <thead><tr><th>Код</th><th>Когда</th></tr></thead>
          <tbody>
            <tr><td className="mono">202</td><td>Письмо принято и стоит в очереди</td></tr>
            <tr><td className="mono">401</td><td>Нет ключа или ключ отозван</td></tr>
            <tr><td className="mono">404</td><td>Шаблона с таким ID нет</td></tr>
            <tr><td className="mono">422</td><td>Неверные данные или письмо заблокировано: в ответе <span className="mono">status: blocked</span> и причина в <span className="mono">error</span></td></tr>
          </tbody>
        </table>
        <h3>Статус письма</h3>
        <p><span className="mono">GET {origin}/api/sender/v1/messages/{'{id}'}</span> с тем же ключом. Статусы: <span className="mono">queued</span> в очереди, <span className="mono">sending</span> отправляется, <span className="mono">sent</span> отправлено, <span className="mono">failed</span> ошибка, <span className="mono">blocked</span> заблокировано.</p>
      </section>

      <section className="api-sec">
        <h2>Шаблоны</h2>
        <p>ID шаблона не меняется. Вместо ID можно передать алиас шаблона, например <span className="mono">password-reset</span>.</p>
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>ID</th><th>Шаблон</th><th>Ключ</th><th>Отправитель</th><th>Переменные</th></tr></thead>
            <tbody>
              {templates.map((t) => {
                const s = sender(t)
                const vars = varsOf(t)
                return (
                  <tr key={t.id}>
                    <td><span className="chip mono id-chip">{t.id}<CopyButton text={String(t.id)} label="Скопировать ID" /></span></td>
                    <td><Link to={`/templates/${t.id}`}>{t.name}</Link></td>
                    <td className="mono sub">{t.slug}</td>
                    <td>{s ? <><div>{s.name}</div><div className="mono sub">{s.email}</div></> : <span className="warn-text">Не выбран</span>}</td>
                    <td><div className="api-vars">{vars.length ? vars.map((v) => <span key={v} className="chip mono">{v}</span>) : <span className="sub">нет</span>}</div></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>
    </main>
  )
}
