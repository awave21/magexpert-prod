import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, ExternalLink, Info, Loader2, RefreshCw, ShieldAlert, Trash2 } from 'lucide-react'
import { api, type DnsAdvice, type DnsAdviceRecord, type Domain, type SenderAddress } from '../api'
import { BackLink, CopyButton, Modal, Status, ago, useToast } from '../components/ui'
import { SenderAddresses } from '../components/SenderAddresses'
import { guideFor } from '../components/dnsGuides'

const INFO: Record<DnsAdviceRecord['key'], { title: string; text: string }> = {
  verification: { title: 'Подтверждение владения', text: 'Показывает Sender, что домен ваш. Без неё письма с домена не отправляются.' },
  dkim: { title: 'DKIM — подпись писем', text: 'Почтовые сервисы проверяют по ней, что письмо действительно от вас и не подделано.' },
  spf: { title: 'SPF — разрешённые серверы', text: 'Список серверов, которым можно отправлять письма от имени домена.' },
  dmarc: { title: 'DMARC — правило проверки', text: 'Говорит почтовым сервисам, что делать с письмами, не прошедшими проверку.' },
}

const ACTION: Record<DnsAdviceRecord['action'], { label: string; tone: string }> = {
  ok: { label: 'Готово, ничего не делать', tone: 'ok' },
  keep: { label: 'Уже есть своя, ничего не делать', tone: 'ok' },
  add: { label: 'Добавить новую запись', tone: 'accent' },
  replace: { label: 'Изменить существующую запись', tone: 'warn' },
  conflict: { label: 'Нужно внимание', tone: 'err' },
}

type Tab = 'dns' | 'senders'

export default function DomainDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const [confirm, setConfirm] = useState(false)

  const q = useQuery({ queryKey: ['domain', id], queryFn: () => api<{ data: Domain }>(`/domains/${id}`) })
  const advice = useQuery({ queryKey: ['dns-check', id], queryFn: () => api<{ data: DnsAdvice }>(`/domains/${id}/dns-check`), staleTime: 60_000 })
  const senders = useQuery({ queryKey: ['sender-addresses'], queryFn: () => api<{ data: SenderAddress[] }>('/sender-addresses') })

  const verify = useMutation({
    mutationFn: () => api<{ report: Record<string, boolean> }>(`/domains/${id}/verify`, { method: 'POST' }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['domain', id] })
      qc.invalidateQueries({ queryKey: ['domains'] })
      qc.invalidateQueries({ queryKey: ['dns-check', id] })
      toast(r.report.verification ? 'Домен подтверждён' : 'Запись подтверждения пока не видна. DNS обновляется до нескольких часов', !r.report.verification)
    },
    onError: (e) => toast((e as Error).message, true),
  })
  const remove = useMutation({
    mutationFn: () => api(`/domains/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['domains'] }); toast('Домен удалён'); nav('/domains') },
  })

  const d = q.data?.data
  if (q.isError) return <main className="page"><BackLink to="/domains">Все домены</BackLink><div className="callout err"><div><b>Домен не найден</b></div></div></main>
  if (!d) return <main className="page muted">Загрузка…</main>

  const verified = d.status === 'verified'
  const tab: Tab = (params.get('tab') as Tab) ?? (verified ? 'senders' : 'dns')
  const domainSenders = senders.data?.data.filter((s) => s.domain === d.domain) ?? []

  return (
    <main className="page">
      <BackLink to="/domains">Все домены</BackLink>
      <div className="page-head" style={{ marginBottom: 20 }}>
        <div>
          <div className="row"><h1 className="mono" style={{ fontSize: 28 }}>{d.domain}</h1><Status value={d.status} /></div>
          <p>Добавлен {ago(d.created_at)}{d.last_checked_at && ` · проверен ${ago(d.last_checked_at)}`}</p>
        </div>
        <div className="row">
          <button className="btn icon" onClick={() => setConfirm(true)} aria-label="Удалить домен" title="Удалить домен"><Trash2 size={15} /></button>
          {tab === 'dns' && <button className="btn primary" onClick={() => verify.mutate()} disabled={verify.isPending}><RefreshCw size={15} className={verify.isPending ? 'spin' : ''} />{verify.isPending ? 'Проверяем…' : 'Проверить записи'}</button>}
        </div>
      </div>

      <div className="tabs" role="tablist" style={{ marginBottom: 28 }}>
        <button role="tab" aria-selected={tab === 'dns'} className={`tab${tab === 'dns' ? ' active' : ''}`} onClick={() => setParams({ tab: 'dns' })}>
          Настройка DNS {verified && <Check size={14} style={{ color: 'var(--ok)', verticalAlign: -2 }} />}
        </button>
        <button role="tab" aria-selected={tab === 'senders'} className={`tab${tab === 'senders' ? ' active' : ''}`} onClick={() => setParams({ tab: 'senders' })}>
          Адреса отправителей{domainSenders.length > 0 && <span className="muted"> · {domainSenders.length}</span>}
        </button>
      </div>

      {tab === 'dns'
        ? <DnsSetup domain={d} advice={advice.data?.data} loading={advice.isLoading} failed={advice.isError} />
        : (
          <>
            {!verified && (
              <div className="callout warn" style={{ marginBottom: 20 }}>
                <span className="ic"><AlertTriangle size={15} /></span>
                <div><b>Сначала настройте DNS</b><span>Адреса можно добавить уже сейчас, но письма с них пойдут только после подтверждения домена. <a href="#" onClick={(e) => { e.preventDefault(); setParams({ tab: 'dns' }) }}>Перейти к настройке DNS</a></span></div>
              </div>
            )}
            <p className="muted" style={{ marginTop: 0, maxWidth: 640 }}>
              Адрес отправителя — это то, что получатель увидит в поле «От кого», например <span className="mono">news@{d.domain}</span>. Каждый адрес нужно подтвердить: на него придёт письмо со ссылкой. Потом адрес выбирается в настройках письма в разделе «Контент».
            </p>
            <SenderAddresses domain={d.domain} verified={verified} />
          </>
        )}

      {confirm && (
        <Modal title="Удалить домен?" onClose={() => setConfirm(false)}>
          <p style={{ color: 'var(--ink-2)' }}>Письма с <span className="mono">{d.domain}</span> перестанут отправляться. Журнал сообщений сохранится. DNS-записи у регистратора останутся: их можно удалить вручную.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setConfirm(false)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate()} disabled={remove.isPending}>Удалить домен</button>
          </div>
        </Modal>
      )}
    </main>
  )
}

function DnsSetup({ domain, advice, loading, failed }: { domain: Domain; advice?: DnsAdvice; loading: boolean; failed: boolean }) {
  if (loading) return <div className="muted row"><Loader2 size={16} className="spin" />Смотрим, какие записи уже есть у домена…</div>
  if (failed || !advice) return <div className="callout err"><div><b>Не удалось проверить DNS домена</b><span>Обновите страницу через минуту.</span></div></div>

  const zone = advice.zone
  const guide = guideFor(advice.dns_provider?.key)
  const todo = advice.records.filter((r) => r.action === 'add' || r.action === 'replace' || r.action === 'conflict')
  const mail = advice.mail_provider

  return (
    <div className="dns-setup">
      <div className={`callout ${todo.length ? 'warn' : 'ok'}`}>
        <span className="ic">{todo.length ? <Info size={15} /> : <Check size={15} />}</span>
        <div>
          <b>{todo.length ? `Осталось сделать: ${todo.length} из ${advice.records.length}` : 'Все записи на месте'}</b>
          <span>{todo.length
            ? 'Внесите записи по шагам ниже и нажмите «Проверить записи». Изменения DNS расходятся по интернету от нескольких минут до пары часов, это нормально.'
            : domain.status === 'verified' ? 'Домен подтверждён, письма с него отправляются.' : 'Нажмите «Проверить записи», чтобы подтвердить домен.'}</span>
        </div>
      </div>

      <div className="dns-grid">
        <section className="dns-card">
          <div className="dns-card-head">
            <span className="muted">Где вносить записи</span>
            <b>{advice.dns_provider?.name ?? 'Не удалось определить'}</b>
            {advice.dns_provider?.url && <a href={advice.dns_provider.url} target="_blank" rel="noreferrer" className="row" style={{ gap: 4 }}>Открыть панель <ExternalLink size={13} /></a>}
          </div>
          <ol className="dns-steps">
            {guide.steps(zone).map((s) => <li key={s}>{s}</li>)}
            <li>Для каждой записи ниже: в поле {guide.nameField} вставьте значение из «Имя», в поле значения — из «Значение».{guide.rootHint ? ` ${guide.rootHint}` : ''}</li>
          </ol>
          {guide.warning && <div className="hint warn-text"><AlertTriangle size={14} />{guide.warning}</div>}
        </section>

        <section className="dns-card dont">
          <div className="dns-card-head">
            <span className="muted">Что не трогать</span>
            <b><ShieldAlert size={16} style={{ verticalAlign: -3, marginRight: 6 }} />{mail ? `Почта домена: ${mail.name}` : 'Остальные записи домена'}</b>
          </div>
          <ul className="dns-dont">
            {mail && <li><b>MX-записи</b> ({mail.mx.join(', ')}) — по ним приходит ваша рабочая почта. Не удаляйте и не меняйте.</li>}
            {mail?.dkim && <li><b>TXT-запись {mail.dkim}</b> — подпись писем {mail.name}. Не удаляйте.</li>}
            {mail?.spf && <li>В SPF должен остаться <span className="mono">{mail.spf}</span>. В значении ниже он уже есть.</li>}
            <li>Записи A, CNAME и другие TXT нужны сайту и сервисам, не удаляйте их. Sender только добавляет свои записи и не требует ничего удалять.</li>
          </ul>
        </section>
      </div>

      <div className="section-title" style={{ marginTop: 36 }}>Записи</div>
      <div>
        {advice.records.map((r) => <DnsRecordCard key={r.key} r={r} />)}
      </div>
    </div>
  )
}

function DnsRecordCard({ r }: { r: DnsAdviceRecord }) {
  const a = ACTION[r.action]
  const value = r.suggested ?? r.value
  const done = r.action === 'ok' || r.action === 'keep'

  return (
    <div className={`rec dns-rec${done ? ' done' : ''}`}>
      <div className="rec-head">
        <span className={`rec-ic ${done ? 'ok' : r.action === 'conflict' ? 'err' : 'warn'}`}>{done ? <Check size={15} /> : r.action === 'conflict' ? '!' : r.action === 'replace' ? '↻' : '+'}</span>
        <div style={{ flex: 1, minWidth: 0 }}><b style={{ fontWeight: 600 }}>{INFO[r.key].title}</b><div className="sub">{INFO[r.key].text}</div></div>
        <span className={`chip ${a.tone}`}>{a.label}</span>
        {!r.required && !done && <span className="chip">Рекомендуется</span>}
      </div>

      {r.note && <div className={`callout ${r.action === 'conflict' ? 'err' : r.action === 'replace' ? 'warn' : ''}`} style={{ margin: '0 0 14px' }}><div><span>{r.note}</span></div></div>}

      {!done && (
        <>
          {r.action === 'replace' && r.current.length > 0 && (
            <div className="dns-current"><span className="muted">Сейчас в записи</span><span className="mono">{r.current.join(' · ')}</span></div>
          )}
          <div className="rec-grid">
            <div><div className="label">Тип</div><div className="copyfield">{r.type}</div></div>
            <div>
              <div className="label">Имя</div>
              <div className="copyfield"><span>{r.name}</span><CopyButton text={r.name} /></div>
              <div className="hint" style={{ marginTop: 4 }}>{r.name === '@' ? 'это сам домен' : `полностью: ${r.host}`}</div>
            </div>
            <div><div className="label">{r.action === 'replace' ? 'Новое значение' : 'Значение'}</div><div className="copyfield"><span>{value}</span><CopyButton text={value} /></div></div>
          </div>
        </>
      )}
    </div>
  )
}
