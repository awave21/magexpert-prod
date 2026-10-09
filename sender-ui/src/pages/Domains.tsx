import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Plus } from 'lucide-react'
import { api, ApiError, type Domain } from '../api'
import { Empty, Modal, PageHead, Status, ago, fmtDate } from '../components/ui'

export default function Domains() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [err, setErr] = useState<string | null>(null)

  const add = useMutation({
    mutationFn: (domain: string) => api<{ data: Domain }>('/domains', { method: 'POST', body: { domain } }),
    onSuccess: (r) => { qc.invalidateQueries({ queryKey: ['domains'] }); nav(`/domains/${r.data.id}`) },
    onError: (e) => setErr(e instanceof ApiError ? (e.errors.domain?.[0] ?? e.message) : 'Не удалось добавить домен'),
  })
  const submit = (e: FormEvent) => { e.preventDefault(); setErr(null); add.mutate(name.trim()) }

  const list = q.data?.data ?? []
  return (
    <main className="page">
      <PageHead title="Домены" sub="Письма можно отправлять только с подтверждённых доменов"
        actions={<button className="btn primary" onClick={() => setAdding(true)}><Plus size={16} />Добавить домен</button>} />

      {q.isSuccess && list.length === 0 ? (
        <Empty title="Доменов пока нет" text="Добавьте домен, с которого будут уходить письма." action={<button className="btn primary" onClick={() => setAdding(true)}>Добавить домен</button>} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Домен</th><th>Статус</th><th>Проверен</th><th /></tr></thead>
            <tbody>
              {list.map((d) => (
                <tr key={d.id} className="click" onClick={() => nav(`/domains/${d.id}`)}>
                  <td><div className="mono" style={{ fontWeight: 500 }}>{d.domain}</div><div className="sub">Добавлен {fmtDate(d.created_at)}</div></td>
                  <td><Status value={d.status} /></td>
                  <td className="sub">{d.last_checked_at ? ago(d.last_checked_at) : 'ещё не проверялся'}</td>
                  <td className="r"><ChevronRight size={16} className="muted" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="section-title" style={{ marginTop: 48 }}>Как подключить домен</div>
      <div className="metrics" style={{ gap: 24 }}>
        {[
          ['Добавьте домен', 'Sender создаст ключ DKIM и токен подтверждения.'],
          ['Внесите записи в DNS', 'Значения можно скопировать на странице домена.'],
          ['Проверьте', 'Когда запись найдена, домен получает статус «Подтверждён».'],
        ].map(([t, d], i) => (
          <div key={t} className="row" style={{ alignItems: 'flex-start', flexWrap: 'nowrap' }}>
            <span className="badge" style={{ background: 'var(--accent)', color: 'var(--on-accent)', minWidth: 24, height: 24 }}>{i + 1}</span>
            <div><b style={{ fontWeight: 600 }}>{t}</b><div className="sub" style={{ fontSize: 13 }}>{d}</div></div>
          </div>
        ))}
      </div>

      {adding && (
        <Modal title="Новый домен" onClose={() => setAdding(false)}>
          <form className="stack" onSubmit={submit}>
            <div className="field">
              <label htmlFor="dom">Домен</label>
              <input id="dom" autoFocus className={`input mono${err ? ' err' : ''}`} placeholder="news.example.ru" value={name} onChange={(e) => setName(e.target.value)} />
              {err ? <div className="hint err">{err}</div> : <div className="hint">Лучше отдельный поддомен для рассылок, например mail.example.ru</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setAdding(false)}>Отмена</button>
              <button className="btn primary" disabled={!name.trim() || add.isPending}>{add.isPending ? 'Добавляем…' : 'Добавить'}</button>
            </div>
          </form>
        </Modal>
      )}
    </main>
  )
}
