import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Send, Trash2 } from 'lucide-react'
import { api, ApiError, type Campaign, type Contact, type ContactList, type Paged, type SenderAddress, type Template } from '../api'
import { BackLink, CampaignStatus, Modal, fmtDate, useToast } from '../components/ui'

const n = (v: number) => v.toLocaleString('ru-RU')
type Rendered = { subject: string; html: string; text: string | null }

export default function CampaignEditor() {
  const { id } = useParams()
  const isNew = id === 'new'
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [params] = useSearchParams()

  const cq = useQuery({
    queryKey: ['campaigns', id],
    queryFn: () => api<{ data: Campaign }>(`/campaigns/${id}`),
    enabled: !isNew,
    refetchInterval: (query) => {
      const c = query.state.data?.data
      return c && c.status !== 'draft' && (c.status === 'sending' || (c.stats?.queued ?? 0) > 0) ? 3000 : false
    },
  })
  const campaign = cq.data?.data

  if (!isNew && cq.isError) return <main className="page"><BackLink to="/campaigns">Все рассылки</BackLink><div className="callout err"><div><b>Рассылка не найдена</b></div></div></main>
  if (!isNew && !campaign) return <main className="page"><BackLink to="/campaigns">Все рассылки</BackLink></main>
  if (campaign && campaign.status !== 'draft') return <CampaignReport campaign={campaign} />

  return <CampaignDraft key={campaign?.id ?? 'new'} campaign={campaign} initialList={params.get('list')} onSaved={(c) => {
    qc.setQueryData(['campaigns', String(c.id)], { data: c })
    qc.invalidateQueries({ queryKey: ['campaigns'] })
    if (isNew) nav(`/campaigns/${c.id}`, { replace: true })
  }} onDeleted={() => { qc.invalidateQueries({ queryKey: ['campaigns'] }); toast('Рассылка удалена'); nav('/campaigns') }} />
}

function CampaignDraft({ campaign, initialList, onSaved, onDeleted }: { campaign?: Campaign; initialList: string | null; onSaved: (c: Campaign) => void; onDeleted: () => void }) {
  const toast = useToast()
  const qc = useQueryClient()
  const [name, setName] = useState(campaign?.name ?? '')
  const [templateId, setTemplateId] = useState<number | ''>(campaign?.template?.id ?? '')
  const [listId, setListId] = useState<number | ''>(campaign?.list?.id ?? (initialList ? Number(initialList) : ''))
  const [track, setTrack] = useState(campaign?.track ?? true)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const tq = useQuery({ queryKey: ['templates'], queryFn: () => api<{ data: Template[] }>('/templates') })
  const lq = useQuery({ queryKey: ['lists'], queryFn: () => api<{ data: ContactList[] }>('/lists') })
  const aq = useQuery({ queryKey: ['sender-addresses'], queryFn: () => api<{ data: SenderAddress[] }>('/sender-addresses') })
  const templates = tq.data?.data ?? []
  const lists = lq.data?.data ?? []
  const template = templates.find((t) => t.id === templateId)
  const list = lists.find((l) => l.id === listId)
  const sender = aq.data?.data.find((a) => a.id === template?.sender_address_id)

  // название по умолчанию — название письма
  useEffect(() => { if (!name && template) setName(template.name) }, [template]) // eslint-disable-line react-hooks/exhaustive-deps

  // предпросмотр с данными первого подписчика базы
  const sq = useQuery({
    queryKey: ['contacts', String(listId), 'sample'],
    queryFn: () => api<Paged<Contact>>(`/lists/${listId}/contacts?status=subscribed`),
    enabled: listId !== '',
  })
  const sample = sq.data?.data[0]
  const sampleName = sample?.name?.trim() ?? ''
  const sampleData = sample ? { ...sample.data, email: sample.email, user_email: sample.email, ...(sampleName ? { name: sampleName, user_name: sampleName, first_name: sampleName.split(' ')[0] } : {}) } : {}
  const pq = useQuery({
    queryKey: ['preview', templateId, sample?.id ?? 0],
    queryFn: () => api<{ data: Rendered }>(`/templates/${templateId}/preview`, { method: 'POST', body: { data: sampleData } }),
    enabled: templateId !== '',
  })

  const dirty = !campaign || name !== campaign.name || (templateId || null) !== (campaign.template?.id ?? null) || (listId || null) !== (campaign.list?.id ?? null) || track !== campaign.track
  const body = { name: name.trim(), template_id: templateId || null, list_id: listId || null, track }
  const fail = (e: unknown) => {
    if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])))
    toast(e instanceof Error ? e.message : 'Ошибка', true)
  }

  const save = useMutation({
    mutationFn: () => api<{ data: Campaign }>(campaign ? `/campaigns/${campaign.id}` : '/campaigns', { method: campaign ? 'PUT' : 'POST', body }),
    onSuccess: (r) => { setErrors({}); toast('Черновик сохранён'); onSaved(r.data) },
    onError: fail,
  })

  const [confirm, setConfirm] = useState(false)
  const send = useMutation({
    mutationFn: async () => {
      const saved = dirty || !campaign ? (await api<{ data: Campaign }>(campaign ? `/campaigns/${campaign.id}` : '/campaigns', { method: campaign ? 'PUT' : 'POST', body })).data : campaign
      return api<{ data: Campaign }>(`/campaigns/${saved.id}/send`, { method: 'POST' })
    },
    onSuccess: (r) => { setConfirm(false); toast('Рассылка запущена'); qc.invalidateQueries({ queryKey: ['lists'] }); onSaved(r.data) },
    onError: (e) => { setConfirm(false); fail(e) },
  })

  const [testOpen, setTestOpen] = useState(false)
  const [testTo, setTestTo] = useState('')
  const test = useMutation({
    mutationFn: () => api(`/templates/${templateId}/test`, { method: 'POST', body: { to: testTo, data: sampleData } }),
    onSuccess: () => { setTestOpen(false); toast(`Тест отправлен на ${testTo}`) },
    onError: (e) => toast(e instanceof Error ? e.message : 'Ошибка', true),
  })

  const [removing, setRemoving] = useState(false)
  const remove = useMutation({ mutationFn: () => api(`/campaigns/${campaign?.id}`, { method: 'DELETE' }), onSuccess: onDeleted })

  const recipients = list?.deliverable_count ?? 0
  const rejected = list ? list.checks.typo + list.checks.disposable + list.checks.no_mx + list.checks.invalid : 0
  const problems = [
    !template && 'Выберите письмо',
    !list && 'Выберите базу',
    list && recipients === 0 && 'В базе нет адресов, которым можно отправить письмо',
    template && !template.sender_address_id && 'У письма не выбран отправитель',
    sender && !sender.confirmed && `Адрес ${sender.email} не подтверждён`,
  ].filter(Boolean) as string[]
  const ready = problems.length === 0 && name.trim() !== ''

  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }

  return (
    <main className="page">
      <BackLink to="/campaigns">Все рассылки</BackLink>
      <form onSubmit={submit} noValidate>
        <div className="tpl-head" style={{ marginBottom: 28 }}>
          <div className="tpl-title">
            <h1>{campaign ? campaign.name || 'Без названия' : 'Новая рассылка'}</h1>
            {campaign && <div className="tpl-meta"><CampaignStatus campaign={campaign} /></div>}
          </div>
          <div className="row">
            {campaign && <button type="button" className="btn icon" onClick={() => setRemoving(true)} aria-label="Удалить черновик" title="Удалить черновик"><Trash2 size={15} /></button>}
            <button type="button" className="btn" disabled={!template} onClick={() => setTestOpen(true)}><Send size={15} />Отправить тест</button>
            <button className="btn" disabled={!dirty || !name.trim() || save.isPending}>{save.isPending ? 'Сохраняем…' : 'Сохранить черновик'}</button>
            <button type="button" className="btn primary" disabled={!ready || send.isPending} onClick={() => setConfirm(true)}>Отправить</button>
          </div>
        </div>

        <div className="tpl-grid">
          <div className="tpl-edit">
            <div className="field">
              <label htmlFor="c-name">Название рассылки</label>
              <input id="c-name" className={`input${errors.name ? ' err' : ''}`} value={name} placeholder="Анонс ноябрьских вебинаров" onChange={(e) => setName(e.target.value)} />
              <div className={`hint${errors.name ? ' err' : ''}`}>{errors.name ?? 'Видно только вам, получатели видят тему письма'}</div>
            </div>

            <div className="campaign-step">
              <span className="step-num">1</span>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="c-template">Что отправить</label>
                <select id="c-template" className={`select${errors.template_id ? ' err' : ''}`} value={templateId} onChange={(e) => setTemplateId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">Выберите письмо из раздела «Контент»</option>
                  {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
                {errors.template_id && <div className="hint err">{errors.template_id}</div>}
                {template && (
                  <div className="step-info">
                    <div><span className="muted">Тема</span>{template.subject}</div>
                    <div><span className="muted">От кого</span>{sender ? `${sender.name} <${sender.email}>` : <span className="warn-text"><AlertTriangle size={14} />не выбран</span>}</div>
                    <Link to={`/templates/${template.id}`}>Открыть письмо <ArrowRight size={13} /></Link>
                  </div>
                )}
              </div>
            </div>

            <div className="campaign-step">
              <span className="step-num">2</span>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="c-list">Кому</label>
                <select id="c-list" className={`select${errors.list_id ? ' err' : ''}`} value={listId} onChange={(e) => setListId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">Выберите базу подписчиков</option>
                  {lists.map((l) => <option key={l.id} value={l.id}>{l.name} · {n(l.deliverable_count)}</option>)}
                </select>
                {errors.list_id && <div className="hint err">{errors.list_id}</div>}
                {lq.isSuccess && lists.length === 0 && <div className="hint">Баз пока нет. <Link to="/subscribers">Создайте базу и загрузите адреса</Link></div>}
                {list && (
                  <div className="step-info">
                    <div><span className="muted">Получат</span><b>{n(recipients)}</b>&nbsp;подписчиков</div>
                    {list.contacts_count > list.subscribed_count && <div className="muted">{n(list.contacts_count - list.subscribed_count)} отписались</div>}
                    {rejected > 0 && <div className="muted">{n(rejected)} адресов не прошли проверку и не получат письмо</div>}
                    {list.checks.unchecked > 0 && <div className="muted">{n(list.checks.unchecked)} ещё проверяются</div>}
                    <Link to={`/subscribers/${list.id}`}>Открыть базу <ArrowRight size={13} /></Link>
                  </div>
                )}
              </div>
            </div>

            <label className="check-row">
              <input type="checkbox" checked={track} onChange={(e) => setTrack(e.target.checked)} />
              <span><b>Отслеживать открытия и переходы по ссылкам</b><span className="sub">В письмо добавится невидимая картинка, а ссылки пойдут через наш сервер. Открытия считаются примерно: часть почтовых программ не показывает картинки или загружает их сама.</span></span>
            </label>

            <div className={`callout${problems.length ? ' warn' : ''}`}>
              <span className="ic">{problems.length ? <AlertTriangle size={15} /> : <Send size={15} />}</span>
              <div>
                <b>{problems.length ? 'Что осталось сделать' : 'Готово к отправке'}</b>
                <span>{problems.length ? problems.join('. ') + '.' : `Письмо уйдёт ${n(recipients)} подписчикам. Адреса из блокировок пропускаются, ссылка отписки добавится в письмо сама.`}</span>
              </div>
            </div>
          </div>

          <aside className="tpl-preview" aria-label="Предпросмотр">
            <div className="tpl-sub"><b>Как увидит получатель</b>{sample && <span className="sub">данные подписчика {sample.email}</span>}</div>
            <div className="inbox">
              <div className="inbox-head">
                <div className="inbox-from"><span className="avatar sm">{(sender?.name ?? 'S').charAt(0)}</span>
                  <div><b>{sender?.name ?? 'Отправитель'}</b><span className="sub">{sender?.email ?? 'не выбран'}</span></div></div>
                <div className="inbox-subject">{pq.data?.data.subject ?? (template ? '…' : <span className="muted">Выберите письмо</span>)}</div>
              </div>
              {pq.data ? <iframe className="inbox-frame" title="Предпросмотр письма" sandbox="allow-same-origin" srcDoc={pq.data.data.html} />
                : <div className="inbox-text muted">{template ? 'Загрузка…' : 'Здесь появится письмо'}</div>}
            </div>
          </aside>
        </div>
      </form>

      {confirm && template && list && (
        <Modal title="Отправить рассылку?" onClose={() => setConfirm(false)}>
          <p style={{ color: 'var(--ink-2)', margin: 0 }}>Письмо «{template.name}» уйдёт подписчикам базы «{list.name}»: <b>{n(recipients)}</b> адресов. Остановить отправку после запуска нельзя.</p>
          <div className="row" style={{ justifyContent: 'flex-end', marginTop: 20 }}>
            <button className="btn" onClick={() => setConfirm(false)}>Отмена</button>
            <button className="btn primary" disabled={send.isPending} onClick={() => send.mutate()}><Send size={15} />{send.isPending ? 'Запускаем…' : `Отправить ${n(recipients)}`}</button>
          </div>
        </Modal>
      )}

      {testOpen && (
        <Modal title="Тестовое письмо" onClose={() => setTestOpen(false)}>
          <form className="stack" onSubmit={(e) => { e.preventDefault(); test.mutate() }}>
            <div className="field">
              <label htmlFor="t-to">Кому</label>
              <input id="t-to" type="email" autoFocus className="input mono" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="you@example.com" />
              <div className="hint">{sample ? `Переменные возьмём у подписчика ${sample.email}` : 'Переменные подставятся значениями по умолчанию'}</div>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setTestOpen(false)}>Отмена</button>
              <button className="btn primary" disabled={!testTo.trim() || test.isPending}><Send size={15} />Отправить</button>
            </div>
          </form>
        </Modal>
      )}

      {removing && campaign && (
        <Modal title="Удалить черновик?" onClose={() => setRemoving(false)}>
          <p style={{ color: 'var(--ink-2)' }}>Рассылка «{campaign.name}» будет удалена. Письмо и база останутся.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRemoving(false)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate()}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}

function CampaignReport({ campaign: c }: { campaign: Campaign }) {
  const s = c.stats ?? { queued: 0, sent: 0, delivered: 0, bounced: 0, failed: 0, blocked: 0, opened: 0, clicked: 0, links: [] }
  const total = Math.max(c.recipients_count, s.queued + s.sent + s.failed + s.blocked + s.bounced, 1)
  const pct = (v: number) => `${(v / total) * 100}%`
  const share = (v: number, of: number) => (of > 0 ? `${Math.round((v / of) * 1000) / 10}%` : '—')
  const preparing = c.status === 'sending'
  const notSent = s.failed + s.blocked + s.bounced

  return (
    <main className="page">
      <BackLink to="/campaigns">Все рассылки</BackLink>
      <div className="tpl-head" style={{ marginBottom: 28 }}>
        <div className="tpl-title">
          <h1>{c.name}</h1>
          <div className="tpl-meta"><CampaignStatus campaign={c} /><span className="sub">запущена {fmtDate(c.started_at)}</span></div>
        </div>
        <Link to="/messages" className="btn">Журнал отправки</Link>
      </div>

      <div className="progress" role="img" aria-label={`Отправлено ${s.sent} из ${total}`}>
        <i className="ok" style={{ width: pct(s.sent) }} />
        <i className="err" style={{ width: pct(s.failed + s.bounced) }} />
        <i className="neutral" style={{ width: pct(s.blocked) }} />
      </div>
      <div className="sub" style={{ marginTop: 8 }}>{preparing ? `Ставим письма в очередь: ${n(s.queued + s.sent + notSent)} из ${n(c.recipients_count)}` : s.queued > 0 ? `Отправляется: осталось ${n(s.queued)}` : `Отправка завершена ${fmtDate(c.finished_at)}`}</div>

      <div className="funnel">
        <div><span className="label">Получателей</span><span className="num">{n(c.recipients_count)}</span><span className="pct">в базе при запуске</span></div>
        <div><span className="label">Отправлено</span><span className="num">{n(s.sent)}</span><span className="pct">{s.queued > 0 ? `ещё ${n(s.queued)} в очереди` : share(s.sent, c.recipients_count)}</span></div>
        <div><span className="label">Доставлено</span><span className="num" style={{ color: 'var(--ok)' }}>{n(s.delivered)}</span><span className="pct">{share(s.delivered, s.sent)} от отправленных</span></div>
        {c.track && <div><span className="label">Открыли</span><span className="num">{n(s.opened)}</span><span className="pct">{share(s.opened, s.delivered || s.sent)} от доставленных</span></div>}
        {c.track && <div><span className="label">Перешли по ссылке</span><span className="num">{n(s.clicked)}</span><span className="pct">{share(s.clicked, s.delivered || s.sent)} от доставленных</span></div>}
        <div><span className="label">Не отправлено</span><span className="num" style={{ color: notSent ? 'var(--err)' : undefined }}>{n(notSent)}</span>
          <span className="pct">{[s.bounced && `${n(s.bounced)} отказ сервера`, s.failed && `${n(s.failed)} ошибка`, s.blocked && `${n(s.blocked)} в блокировках`].filter(Boolean).join(', ') || 'без потерь'}</span></div>
      </div>

      {c.track && s.links.length > 0 && (
        <>
          <div className="section-title" style={{ marginTop: 36 }}>Ссылки, по которым переходили</div>
          <table className="table">
            <thead><tr><th>Ссылка</th><th className="r">Получателей</th></tr></thead>
            <tbody>{s.links.map((l) => <tr key={l.url}><td className="mono" style={{ overflowWrap: 'anywhere' }}><a href={l.url} target="_blank" rel="noreferrer">{l.url}</a></td><td className="r num-cell">{n(l.clicks)}</td></tr>)}</tbody>
          </table>
        </>
      )}

      {!c.track && <p className="hint" style={{ marginTop: 24 }}>Открытия и переходы не отслеживались: учёт был выключен в настройках рассылки.</p>}
      <p className="hint" style={{ marginTop: 12 }}>«Доставлено» — сервер получателя принял письмо. Попало ли оно во «Входящие» или в «Спам», почтовые сервисы не сообщают. Открытия считаются примерно, переходы по ссылкам — точнее.</p>

      <div className="report-info">
        <div><span className="muted">Письмо</span>{c.template ? <Link to={`/templates/${c.template.id}`}>{c.template.name}</Link> : 'удалено'}</div>
        <div><span className="muted">База</span>{c.list ? <Link to={`/subscribers/${c.list.id}`}>{c.list.name}</Link> : 'удалена'}</div>
      </div>
    </main>
  )
}
