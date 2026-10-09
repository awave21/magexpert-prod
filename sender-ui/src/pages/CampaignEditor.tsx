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

  const dirty = !campaign || name !== campaign.name || (templateId || null) !== (campaign.template?.id ?? null) || (listId || null) !== (campaign.list?.id ?? null)
  const body = { name: name.trim(), template_id: templateId || null, list_id: listId || null }
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

  const recipients = list?.subscribed_count ?? 0
  const problems = [
    !template && 'Выберите письмо',
    !list && 'Выберите базу',
    list && recipients === 0 && 'В базе нет подписчиков',
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
                  {lists.map((l) => <option key={l.id} value={l.id}>{l.name} · {n(l.subscribed_count)}</option>)}
                </select>
                {errors.list_id && <div className="hint err">{errors.list_id}</div>}
                {lq.isSuccess && lists.length === 0 && <div className="hint">Баз пока нет. <Link to="/subscribers">Создайте базу и загрузите адреса</Link></div>}
                {list && (
                  <div className="step-info">
                    <div><span className="muted">Получат</span><b>{n(recipients)}</b>&nbsp;подписчиков</div>
                    {list.contacts_count > recipients && <div className="muted">{n(list.contacts_count - recipients)} отписались и не получат письмо</div>}
                    <Link to={`/subscribers/${list.id}`}>Открыть базу <ArrowRight size={13} /></Link>
                  </div>
                )}
              </div>
            </div>

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
  const s = c.stats ?? { queued: 0, sent: 0, failed: 0, blocked: 0 }
  const total = Math.max(c.recipients_count, s.queued + s.sent + s.failed + s.blocked, 1)
  const pct = (v: number) => `${(v / total) * 100}%`
  const preparing = c.status === 'sending'

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

      <div className="progress" role="img" aria-label={`Доставлено ${s.sent} из ${total}`}>
        <i className="ok" style={{ width: pct(s.sent) }} />
        <i className="err" style={{ width: pct(s.failed) }} />
        <i className="neutral" style={{ width: pct(s.blocked) }} />
      </div>
      <div className="sub" style={{ marginTop: 8 }}>{preparing ? `Ставим письма в очередь: ${n(s.queued + s.sent + s.failed + s.blocked)} из ${n(c.recipients_count)}` : s.queued > 0 ? `Отправляется: осталось ${n(s.queued)}` : `Завершена ${fmtDate(c.finished_at)}`}</div>

      <div className="metrics" style={{ marginTop: 32 }}>
        <div className="metric"><div className="label">Получателей</div><div className="num">{n(c.recipients_count)}</div><div className="cap">подписчиков в базе на момент запуска</div></div>
        <div className="metric"><div className="label">Отправлено</div><div className="num" style={{ color: 'var(--ok)' }}>{n(s.sent)}</div><div className="cap">приняты почтовыми серверами</div></div>
        <div className="metric"><div className="label">В очереди</div><div className="num" style={{ color: 'var(--warn)' }}>{n(s.queued)}</div><div className="cap">ждут отправки</div></div>
        <div className="metric"><div className="label">Не отправлено</div><div className="num" style={{ color: 'var(--err)' }}>{n(s.failed + s.blocked)}</div><div className="cap">{n(s.failed)} с ошибкой, {n(s.blocked)} в блокировках</div></div>
      </div>

      <div className="report-info">
        <div><span className="muted">Письмо</span>{c.template ? <Link to={`/templates/${c.template.id}`}>{c.template.name}</Link> : 'удалено'}</div>
        <div><span className="muted">База</span>{c.list ? <Link to={`/subscribers/${c.list.id}`}>{c.list.name}</Link> : 'удалена'}</div>
      </div>
    </main>
  )
}
