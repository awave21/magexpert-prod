import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, RefreshCw, X } from 'lucide-react'
import { api, type DnsRecord, type Domain, type VerifyReport } from '../api'
import { BackLink, CopyField, Modal, Status, ago, useToast } from '../components/ui'
import { SenderAddresses } from '../components/SenderAddresses'

const INFO: Record<DnsRecord['key'], { title: string; text: string }> = {
  verification: { title: 'Подтверждение владения', text: 'Показывает, что домен ваш' },
  dkim: { title: 'DKIM', text: 'Цифровая подпись писем' },
  spf: { title: 'SPF', text: 'Разрешённые серверы отправки' },
  dmarc: { title: 'DMARC', text: 'Политика проверки писем' },
}

export default function DomainDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [report, setReport] = useState<VerifyReport | null>(null)
  const [confirm, setConfirm] = useState(false)
  const q = useQuery({ queryKey: ['domain', id], queryFn: () => api<{ data: Domain }>(`/domains/${id}`) })

  const verify = useMutation({
    mutationFn: () => api<{ report: VerifyReport }>(`/domains/${id}/verify`, { method: 'POST' }),
    onSuccess: (r) => {
      setReport(r.report)
      qc.invalidateQueries({ queryKey: ['domain', id] })
      qc.invalidateQueries({ queryKey: ['domains'] })
      toast(r.report.verification ? 'Домен подтверждён' : 'Запись подтверждения пока не найдена', !r.report.verification)
    },
    onError: (e) => toast((e as Error).message, true),
  })
  const remove = useMutation({
    mutationFn: () => api(`/domains/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['domains'] }); toast('Домен удалён'); nav('/domains') },
  })

  const d = q.data?.data
  if (q.isError) return <main className="page"><div className="callout err"><div><b>Домен не найден</b></div></div></main>
  if (!d) return <main className="page muted">Загрузка…</main>

  const records = d.dns_records ?? []
  const found = (k: DnsRecord['key']) => (report ? report[k] : d.status === 'verified' && k === 'verification' ? true : null)
  const done = report ? Object.values(report).filter(Boolean).length : null

  return (
    <main className="page">
      <BackLink to="/domains">Все домены</BackLink>
      <div className="page-head">
        <div>
          <div className="row"><h1 className="mono" style={{ fontSize: 28 }}>{d.domain}</h1><Status value={d.status} /></div>
          <p>Добавлен {ago(d.created_at)}{d.last_checked_at && ` · проверен ${ago(d.last_checked_at)}`}</p>
        </div>
        <div className="row">
          <button className="btn danger" onClick={() => setConfirm(true)}>Удалить</button>
          <button className="btn primary" onClick={() => verify.mutate()} disabled={verify.isPending}><RefreshCw size={15} />{verify.isPending ? 'Проверяем…' : 'Проверить записи'}</button>
        </div>
      </div>

      {d.status !== 'verified' ? (
        <div className="callout warn" style={{ marginBottom: 8 }}>
          <div><b>{done !== null ? `${done} из 4 записей найдено` : 'Домен ещё не подтверждён'}</b>
            <span>Внесите записи ниже у регистратора домена и нажмите «Проверить записи». Обновление DNS может занять от нескольких минут до пары часов. Пока домен не подтверждён, письма с него не отправляются.</span></div>
        </div>
      ) : (
        <div className="callout ok" style={{ marginBottom: 8 }}>
          <span className="ic"><Check size={15} /></span>
          <div><b>Домен подтверждён</b><span>Письма с адресов @{d.domain} отправляются.</span></div>
        </div>
      )}


      <SenderAddresses domain={d.domain} verified={d.status === 'verified'} />

      <div className="section-title" style={{ marginTop: 40 }}>DNS-записи</div>
      <div>
        {records.map((r) => {
          const f = found(r.key)
          return (
            <div className="rec" key={r.key}>
              <div className="rec-head">
                <span className={`rec-ic ${f === true ? 'ok' : f === false ? (r.required ? 'err' : 'warn') : 'warn'}`}>{f === true ? <Check size={15} /> : f === false ? <X size={15} /> : <span style={{ fontSize: 13, fontWeight: 600 }}>?</span>}</span>
                <div><b style={{ fontWeight: 600 }}>{INFO[r.key].title}</b><div className="sub">{INFO[r.key].text}</div></div>
                <span className={`chip${r.required ? ' accent' : ''}`}>{r.required ? 'Обязательна' : 'Рекомендуется'}</span>
              </div>
              <div className="rec-grid">
                <div><div className="label">Тип</div><div className="copyfield">{r.type}</div></div>
                <div><div className="label">Имя</div><CopyField value={r.host} /></div>
                <div><div className="label">Значение</div><CopyField value={r.value} /></div>
              </div>
              {r.key === 'spf' && <div className="hint" style={{ marginTop: 8 }}>Если у домена уже есть SPF, не создавайте вторую запись: добавьте адрес сервера в существующую.</div>}
            </div>
          )
        })}
      </div>

      {confirm && (
        <Modal title="Удалить домен?" onClose={() => setConfirm(false)}>
          <p style={{ color: 'var(--ink-2)' }}>Письма с <span className="mono">{d.domain}</span> перестанут отправляться. Журнал сообщений сохранится.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setConfirm(false)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate()} disabled={remove.isPending}>Удалить домен</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
