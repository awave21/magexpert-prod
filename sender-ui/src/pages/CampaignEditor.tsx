import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Clock, Pause, Play, Send, Trash2, X } from 'lucide-react'
import { api, ApiError, type Campaign, type Contact, type ContactList, type ListField, type Paged, type Segment, type SenderAddress, type Template } from '../api'
import { BackLink, CampaignStatus, Modal, fmtDate, useToast } from '../components/ui'
import { WEEKDAYS, estimateFinish, fmtWhen, nextOpening, perHour, type Schedule } from '../schedule'

const n = (v: number) => v.toLocaleString('ru-RU')
// поля базы «Пользователи сайта»; поля из файла показываются как есть
const FIELD_LABELS: Record<string, string> = { specialization: 'специальность', city: 'город', company: 'организация', position: 'должность' }
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
      if (!c || c.status === 'draft') return false
      if (c.status === 'scheduled' || c.status === 'paused') return 15000
      return c.status === 'sending' || (c.stats?.queued ?? 0) > 0 ? 3000 : false
    },
  })
  const campaign = cq.data?.data

  if (!isNew && cq.isError) return <main className="page"><BackLink to="/campaigns">Все рассылки</BackLink><div className="callout err"><div><b>Рассылка не найдена</b></div></div></main>
  if (!isNew && !campaign) return <main className="page"><BackLink to="/campaigns">Все рассылки</BackLink></main>
  if (campaign && campaign.status !== 'draft') return <CampaignReport campaign={campaign} onChanged={(c) => { qc.setQueryData(['campaigns', String(c.id)], { data: c }); qc.invalidateQueries({ queryKey: ['campaigns'] }) }} />

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
  const [mode, setMode] = useState<'now' | 'drip'>(campaign?.send_mode ?? 'drip')
  const [batchSize, setBatchSize] = useState(String(campaign?.batch_size ?? 100))
  const [batchInterval, setBatchInterval] = useState(String(campaign?.batch_interval ?? 5))
  const [dailyLimit, setDailyLimit] = useState(campaign?.daily_limit ? String(campaign.daily_limit) : '')
  const [windowOn, setWindowOn] = useState(campaign ? Boolean(campaign.window_start) : true)
  const [windowStart, setWindowStart] = useState(campaign?.window_start ?? '09:00')
  const [windowEnd, setWindowEnd] = useState(campaign?.window_end ?? '20:00')
  const [days, setDays] = useState<number[]>(campaign?.window_days ?? [1, 2, 3, 4, 5])
  const [later, setLater] = useState(Boolean(campaign?.scheduled_at))
  const [scheduledAt, setScheduledAt] = useState(campaign?.scheduled_at ? toLocalInput(campaign.scheduled_at) : '')
  const [activeOn, setActiveOn] = useState(Boolean(campaign?.segment?.active_days))
  const [activeDays, setActiveDays] = useState(String(campaign?.segment?.active_days ?? 90))
  const [field, setField] = useState(campaign?.segment?.field ?? '')
  const [values, setValues] = useState<string[]>(campaign?.segment?.values ?? [])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [openedAt] = useState(() => Date.now())

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

  const segment: Segment | null = activeOn || (field && values.length)
    ? { active_days: activeOn ? Number(activeDays) || null : null, field: field && values.length ? field : null, values: field && values.length ? values : null }
    : null
  const fq = useQuery({
    queryKey: ['list-fields', listId],
    queryFn: () => api<{ data: ListField[] }>(`/lists/${listId}/fields`),
    enabled: listId !== '',
  })
  const fields = fq.data?.data ?? []
  const countQ = useQuery({
    queryKey: ['segment-count', listId, JSON.stringify(segment)],
    queryFn: () => api<{ data: { count: number } }>(`/lists/${listId}/count`, { method: 'POST', body: { segment } }),
    enabled: listId !== '' && segment !== null,
  })

  const drip = mode === 'drip'
  const schedule: Schedule = {
    batch: Number(batchSize) || 0,
    interval: Number(batchInterval) || 0,
    daily: Number(dailyLimit) || null,
    start: windowOn ? windowStart : null,
    end: windowOn ? windowEnd : null,
    days: windowOn ? days : null,
  }
  const body = {
    name: name.trim(), template_id: templateId || null, list_id: listId || null, track,
    send_mode: mode,
    batch_size: drip ? schedule.batch || null : null,
    batch_interval: drip ? schedule.interval || null : null,
    daily_limit: drip ? schedule.daily : null,
    window_start: drip ? schedule.start : null,
    window_end: drip ? schedule.end : null,
    window_days: drip ? schedule.days : null,
    scheduled_at: later && scheduledAt ? new Date(scheduledAt).toISOString() : null,
    segment,
  }
  const saved = campaign ? {
    name: campaign.name, template_id: campaign.template?.id ?? null, list_id: campaign.list?.id ?? null, track: campaign.track,
    send_mode: campaign.send_mode, batch_size: campaign.batch_size, batch_interval: campaign.batch_interval, daily_limit: campaign.daily_limit,
    window_start: campaign.window_start, window_end: campaign.window_end, window_days: campaign.window_days,
    scheduled_at: campaign.scheduled_at ? new Date(campaign.scheduled_at).toISOString() : null,
    segment: campaign.segment ?? null,
  } : null
  const dirty = !saved || JSON.stringify(saved) !== JSON.stringify(body)
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
    onSuccess: (r) => { setConfirm(false); toast(r.data.status === 'scheduled' ? 'Рассылка запланирована' : 'Рассылка запущена'); qc.invalidateQueries({ queryKey: ['lists'] }); onSaved(r.data) },
    onError: (e) => { setConfirm(false); fail(e) },
  })

  const [testOpen, setTestOpen] = useState(false)
  const [testTo, setTestTo] = useState('')
  const [testAs, setTestAs] = useState('')
  // тест идёт от имени рассылки: несохранённые изменения сначала сохраняются
  const test = useMutation({
    mutationFn: async () => {
      const saved = dirty || !campaign ? (await api<{ data: Campaign }>(campaign ? `/campaigns/${campaign.id}` : '/campaigns', { method: campaign ? 'PUT' : 'POST', body })).data : campaign
      await api(`/campaigns/${saved.id}/test`, { method: 'POST', body: { to: testTo, contact_email: testAs.trim() || null } })
      return saved
    },
    onSuccess: (saved) => { setTestOpen(false); toast(`Тест отправлен на ${testTo}`); if (saved !== campaign) onSaved(saved) },
    onError: fail,
  })

  const [removing, setRemoving] = useState(false)
  const remove = useMutation({ mutationFn: () => api(`/campaigns/${campaign?.id}`, { method: 'DELETE' }), onSuccess: onDeleted })

  const recipients = segment ? (countQ.data?.data.count ?? 0) : (list?.deliverable_count ?? 0)
  const rejected = list ? list.checks.typo + list.checks.disposable + list.checks.no_mx + list.checks.invalid : 0
  const problems = [
    !template && 'Выберите письмо',
    !list && 'Выберите базу',
    list && recipients === 0 && !countQ.isFetching && (segment ? 'Под условия отбора не подходит ни один подписчик' : 'В базе нет адресов, которым можно отправить письмо'),
    field && values.length === 0 && 'Выберите значения поля для отбора',
    template && !template.sender_address_id && 'У письма не выбран отправитель',
    sender && !sender.confirmed && `Адрес ${sender.email} не подтверждён`,
    drip && schedule.batch < 1 && 'Укажите, сколько писем отправлять за раз',
    drip && schedule.interval < 1 && 'Укажите паузу между пачками',
    drip && windowOn && !(windowStart < windowEnd) && 'Конец окна отправки должен быть позже начала',
    drip && windowOn && days.length === 0 && 'Выберите дни отправки',
    later && !scheduledAt && 'Укажите время старта',
  ].filter(Boolean) as string[]
  const startAt = later && scheduledAt ? new Date(scheduledAt).getTime() : openedAt
  const finish = drip && recipients > 0 ? estimateFinish(schedule, recipients, startAt) : null
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
            <button type="button" className="btn" disabled={!template} onClick={() => { setErrors({}); setTestOpen(true) }}><Send size={15} />Отправить тест</button>
            <button className="btn" disabled={!dirty || !name.trim() || save.isPending}>{save.isPending ? 'Сохраняем…' : 'Сохранить черновик'}</button>
            <button type="button" className="btn primary" disabled={!ready || send.isPending} onClick={() => setConfirm(true)}>{later ? 'Запланировать' : 'Отправить'}</button>
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
                {list && (
                  <div className="step-info schedule">
                    <b style={{ fontWeight: 500 }}>Кому из базы</b>
                    <label className="check-row">
                      <input type="checkbox" checked={activeOn} onChange={(e) => setActiveOn(e.target.checked)} />
                      <span><b>Только активным</b><span className="sub">Открывали письма или переходили по ссылкам. Новые подписчики сюда не попадут: о них ещё нечего знать. Хороший выбор на время прогрева.</span></span>
                    </label>
                    {activeOn && (
                      <div className="schedule-row">
                        <label htmlFor="c-active">за последние</label>
                        <input id="c-active" type="number" min={1} inputMode="numeric" className="input num" value={activeDays} onChange={(e) => setActiveDays(e.target.value)} />
                        <span>дней</span>
                      </div>
                    )}
                    {fields.length > 0 && (
                      <>
                        <div className="schedule-row">
                          <label htmlFor="c-field">Только где</label>
                          <select id="c-field" className="select sm" value={field} onChange={(e) => { setField(e.target.value); setValues([]) }}>
                            <option value="">выберите поле</option>
                            {fields.map((f) => <option key={f.key} value={f.key}>{FIELD_LABELS[f.key] ?? f.key}</option>)}
                          </select>
                        </div>
                        {field && (
                          <div className="segment-values" role="group" aria-label={`Значения поля ${field}`}>
                            {(fields.find((f) => f.key === field)?.values ?? []).map((v) => (
                              <button key={v.value} type="button" className={`day${values.includes(v.value) ? ' on' : ''}`} aria-pressed={values.includes(v.value)}
                                onClick={() => setValues(values.includes(v.value) ? values.filter((x) => x !== v.value) : [...values, v.value])}>{v.value} · {n(v.count)}</button>
                            ))}
                          </div>
                        )}
                      </>
                    )}
                    {segment && <div className="muted">{countQ.isFetching ? 'Считаем…' : `Подходят ${n(recipients)} из ${n(list.deliverable_count)}`}</div>}
                  </div>
                )}
              </div>
            </div>

            <div className="campaign-step">
              <span className="step-num">3</span>
              <div className="field" style={{ flex: 1 }}>
                <label id="c-mode-label">Как отправить</label>
                <div className="seg" role="group" aria-labelledby="c-mode-label">
                  <button type="button" className={drip ? 'on' : ''} aria-pressed={drip} onClick={() => setMode('drip')}>Плавно, пачками</button>
                  <button type="button" className={!drip ? 'on' : ''} aria-pressed={!drip} onClick={() => setMode('now')}>Всем сразу</button>
                </div>
                {drip ? (
                  <div className="step-info schedule">
                    <div className="schedule-row">
                      <label htmlFor="c-batch">По</label>
                      <input id="c-batch" type="number" min={1} inputMode="numeric" className={`input num${errors.batch_size ? ' err' : ''}`} value={batchSize} onChange={(e) => setBatchSize(e.target.value)} />
                      <label htmlFor="c-interval">писем раз в</label>
                      <input id="c-interval" type="number" min={1} max={1440} inputMode="numeric" className={`input num${errors.batch_interval ? ' err' : ''}`} value={batchInterval} onChange={(e) => setBatchInterval(e.target.value)} />
                      <span>мин</span>
                    </div>
                    <div className="schedule-row">
                      <label htmlFor="c-daily">Не больше</label>
                      <input id="c-daily" type="number" min={1} inputMode="numeric" className={`input num wide${errors.daily_limit ? ' err' : ''}`} value={dailyLimit} placeholder="без лимита" onChange={(e) => setDailyLimit(e.target.value)} />
                      <span>писем в сутки</span>
                    </div>
                    <label className="check-row">
                      <input type="checkbox" checked={windowOn} onChange={(e) => setWindowOn(e.target.checked)} />
                      <span><b>Только в рабочее время</b><span className="sub">Время московское. Вне окна пачки ждут.</span></span>
                    </label>
                    {windowOn && (
                      <>
                        <div className="schedule-row">
                          <label htmlFor="c-wstart">с</label>
                          <input id="c-wstart" type="time" className={`input time${errors.window_start ? ' err' : ''}`} value={windowStart} onChange={(e) => setWindowStart(e.target.value)} />
                          <label htmlFor="c-wend">до</label>
                          <input id="c-wend" type="time" className={`input time${errors.window_end ? ' err' : ''}`} value={windowEnd} onChange={(e) => setWindowEnd(e.target.value)} />
                        </div>
                        <div className="days" role="group" aria-label="Дни отправки">
                          {WEEKDAYS.map(([d, label]) => (
                            <button key={d} type="button" className={`day${days.includes(d) ? ' on' : ''}`} aria-pressed={days.includes(d)}
                              onClick={() => setDays(days.includes(d) ? days.filter((x) => x !== d) : [...days, d].sort())}>{label}</button>
                          ))}
                        </div>
                      </>
                    )}
                    {(errors.batch_size || errors.batch_interval || errors.daily_limit || errors.window_end || errors.window_days) && (
                      <div className="hint err">{errors.batch_size ?? errors.batch_interval ?? errors.daily_limit ?? errors.window_end ?? errors.window_days}</div>
                    )}
                    {schedule.batch > 0 && schedule.interval > 0 && (
                      <div className="muted">≈ {n(perHour(schedule))} писем в час{finish ? `, ${n(recipients)} писем уйдут к ${fmtWhen(finish)}` : ''}</div>
                    )}
                  </div>
                ) : (
                  <div className="hint">Все письма сразу встанут в очередь. Для базы больше нескольких сотен адресов лучше отправлять плавно: почтовые сервисы с подозрением относятся к резким всплескам с нового сервера.</div>
                )}
                <label className="check-row" style={{ marginTop: 12 }}>
                  <input type="checkbox" checked={later} onChange={(e) => setLater(e.target.checked)} />
                  <span><b>Начать позже</b><span className="sub">Письма подготовим сразу, отправка начнётся в выбранное время.</span></span>
                </label>
                {later && (
                  <>
                    <input aria-label="Время старта" type="datetime-local" className={`input${errors.scheduled_at ? ' err' : ''}`} style={{ maxWidth: 260 }} value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} />
                    {errors.scheduled_at && <div className="hint err">{errors.scheduled_at}</div>}
                  </>
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
                <span>{problems.length ? problems.join('. ') + '.' : `Письмо уйдёт ${n(recipients)} подписчикам${drip ? ` пачками по ${n(schedule.batch)}` : ''}${later ? `, начало ${fmtWhen(startAt)}` : ''}. Адреса из блокировок и отписавшиеся пропускаются, ссылка отписки добавится в письмо сама.`}</span>
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
          <p style={{ color: 'var(--ink-2)', margin: 0 }}>Письмо «{template.name}» уйдёт подписчикам базы «{list.name}»{segment ? ', которые подходят под условия отбора' : ''}: <b>{n(recipients)}</b> адресов.</p>
          <ul className="confirm-list">
            <li><Clock size={14} />{drip && nextOpening(schedule, startAt) > startAt ? `Первая пачка ${fmtWhen(nextOpening(schedule, startAt))}: сейчас вне окна отправки` : later ? `Начало ${fmtWhen(startAt)}` : 'Начало сразу'}</li>
            <li><Send size={14} />{drip ? `По ${n(schedule.batch)} писем раз в ${schedule.interval} мин${schedule.daily ? `, до ${n(schedule.daily)} в сутки` : ''}${windowOn ? `, ${windowStart}–${windowEnd} МСК` : ''}` : 'Все письма сразу'}</li>
            {finish && <li><ArrowRight size={14} />Последняя пачка примерно {fmtWhen(finish)}</li>}
          </ul>
          <p className="hint" style={{ margin: 0 }}>До того как уйдут все письма, рассылку можно поставить на паузу или отменить.</p>
          <div className="row" style={{ justifyContent: 'flex-end', marginTop: 20 }}>
            <button className="btn" onClick={() => setConfirm(false)}>Отмена</button>
            <button className="btn primary" disabled={send.isPending} onClick={() => send.mutate()}><Send size={15} />{send.isPending ? 'Запускаем…' : later ? 'Запланировать' : `Отправить ${n(recipients)}`}</button>
          </div>
        </Modal>
      )}

      {testOpen && (
        <Modal title="Тестовое письмо" onClose={() => setTestOpen(false)}>
          <form className="stack" onSubmit={(e) => { e.preventDefault(); test.mutate() }}>
            <div className="field">
              <label htmlFor="t-to">Кому</label>
              <input id="t-to" type="email" autoFocus className="input mono" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="you@example.com" />
            </div>
            {list && (
              <div className="field">
                <label htmlFor="t-as">Как увидит подписчик</label>
                <input id="t-as" type="email" className={`input mono${errors.contact_email ? ' err' : ''}`} value={testAs} onChange={(e) => setTestAs(e.target.value)} placeholder={sample?.email ?? 'адрес из базы'} />
                <div className={`hint${errors.contact_email ? ' err' : ''}`}>{errors.contact_email ?? `Имя и другие поля возьмём у этого подписчика базы «${list.name}»${sample ? `, по умолчанию ${sample.email}` : ''}. В теме будет пометка «Тест», внизу ссылка отписки.`}</div>
              </div>
            )}
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

function CampaignReport({ campaign: c, onChanged }: { campaign: Campaign; onChanged: (c: Campaign) => void }) {
  const toast = useToast()
  const s = c.stats ?? { pending: 0, cancelled: 0, queued: 0, sent: 0, delivered: 0, bounced: 0, failed: 0, blocked: 0, opened: 0, clicked: 0, links: [] }
  const total = Math.max(c.recipients_count, s.pending + s.queued + s.sent + s.failed + s.blocked + s.bounced + s.cancelled, 1)
  const pct = (v: number) => `${(v / total) * 100}%`
  const share = (v: number, of: number) => (of > 0 ? `${Math.round((v / of) * 1000) / 10}%` : '—')
  const notSent = s.failed + s.blocked + s.bounced
  const left = s.pending + s.queued
  const running = c.status === 'sending' || c.status === 'scheduled'

  const [cancelling, setCancelling] = useState(false)
  const action = useMutation({
    mutationFn: (what: 'pause' | 'resume' | 'cancel') => api<{ data: Campaign }>(`/campaigns/${c.id}/${what}`, { method: 'POST' }),
    onSuccess: (r, what) => {
      setCancelling(false)
      toast({ pause: 'Рассылка на паузе', resume: 'Отправка продолжается', cancel: 'Рассылка отменена' }[what])
      onChanged(r.data)
    },
    onError: (e) => toast(e instanceof Error ? e.message : 'Ошибка', true),
  })

  const progress = (() => {
    if (c.status === 'scheduled') return `Начнётся ${fmtDate(c.scheduled_at)}. Письма для ${n(c.recipients_count)} подписчиков подготовлены.`
    if (c.status === 'sending' && !c.prepared) return `Готовим письма: ${n(s.pending + s.queued + s.sent + notSent)} из ${n(c.recipients_count)}`
    if (c.status === 'paused') return `На паузе. Осталось отправить ${n(left)}, новые пачки не уходят.`
    if (c.status === 'cancelled') return `Отменена ${fmtDate(c.finished_at)}. Не отправлено из-за отмены: ${n(s.cancelled)}.`
    if (c.status === 'sending') {
      const parts = [`Отправлено ${n(s.sent)} из ${n(total)}`]
      if (s.pending > 0 && c.send_mode === 'drip' && c.next_batch_at) parts.push(`следующая пачка ${fmtDate(c.next_batch_at)}`)
      if (c.estimated_finish_at) parts.push(`последняя примерно ${fmtDate(c.estimated_finish_at)}`)
      if (s.pending === 0 && s.queued > 0) parts.push(`в очереди ${n(s.queued)}`)
      return parts.join(', ')
    }
    return s.queued > 0 ? `Отправляется: осталось ${n(s.queued)}` : `Отправка завершена ${fmtDate(c.finished_at)}`
  })()

  return (
    <main className="page">
      <BackLink to="/campaigns">Все рассылки</BackLink>
      <div className="tpl-head" style={{ marginBottom: 28 }}>
        <div className="tpl-title">
          <h1>{c.name}</h1>
          <div className="tpl-meta"><CampaignStatus campaign={c} /><span className="sub">запущена {fmtDate(c.started_at)}</span></div>
        </div>
        <div className="row">
          {running && <button className="btn" disabled={action.isPending} onClick={() => action.mutate('pause')}><Pause size={15} />Пауза</button>}
          {c.status === 'paused' && <button className="btn primary" disabled={action.isPending} onClick={() => action.mutate('resume')}><Play size={15} />Продолжить</button>}
          {(running || c.status === 'paused') && <button className="btn" disabled={action.isPending} onClick={() => setCancelling(true)}><X size={15} />Отменить</button>}
          <Link to="/messages" className="btn">Журнал отправки</Link>
        </div>
      </div>

      <div className="progress" role="img" aria-label={`Отправлено ${s.sent} из ${total}`}>
        <i className="ok" style={{ width: pct(s.sent) }} />
        <i className="err" style={{ width: pct(s.failed + s.bounced) }} />
        <i className="neutral" style={{ width: pct(s.blocked + s.cancelled) }} />
      </div>
      <div className="sub" style={{ marginTop: 8 }}>{progress}</div>
      {c.send_mode === 'drip' && c.batch_size && (
        <div className="sub" style={{ marginTop: 4 }}>
          По {n(c.batch_size)} писем раз в {c.batch_interval} мин{c.daily_limit ? `, до ${n(c.daily_limit)} в сутки` : ''}{c.window_start ? `, ${c.window_start}–${c.window_end} МСК` : ''}{c.window_days?.length && c.window_days.length < 7 ? `, ${WEEKDAYS.filter(([d]) => c.window_days?.includes(d)).map(([, l]) => l).join(' ')}` : ''}
        </div>
      )}

      {cancelling && (
        <Modal title="Отменить рассылку?" onClose={() => setCancelling(false)}>
          <p style={{ color: 'var(--ink-2)', margin: 0 }}>Ещё не отправленные письма ({n(left)}) не уйдут. Уже отправленные письма и статистика останутся. Вернуть рассылку после отмены нельзя.</p>
          <div className="row" style={{ justifyContent: 'flex-end', marginTop: 20 }}>
            <button className="btn" onClick={() => setCancelling(false)}>Не отменять</button>
            <button className="btn danger" disabled={action.isPending} onClick={() => action.mutate('cancel')}>Отменить рассылку</button>
          </div>
        </Modal>
      )}

      <div className="funnel">
        <div><span className="label">Получателей</span><span className="num">{n(c.recipients_count)}</span><span className="pct">в базе при запуске</span></div>
        <div><span className="label">Отправлено</span><span className="num">{n(s.sent)}</span><span className="pct">{left > 0 ? `ещё ${n(left)} ждут отправки` : share(s.sent, c.recipients_count)}</span></div>
        <div><span className="label">Доставлено</span><span className="num" style={{ color: 'var(--ok)' }}>{n(s.delivered)}</span><span className="pct">{share(s.delivered, s.sent)} от отправленных</span></div>
        {c.track && <div><span className="label">Открыли</span><span className="num">{n(s.opened)}</span><span className="pct">{share(s.opened, s.delivered || s.sent)} от доставленных</span></div>}
        {(s.complained ?? 0) > 0 && <div><span className="label">Жалобы на спам</span><span className="num" style={{ color: 'var(--err)' }}>{n(s.complained ?? 0)}</span><span className="pct">{share(s.complained ?? 0, s.delivered || s.sent)}, адреса отписаны</span></div>}
        {c.track && <div><span className="label">Перешли по ссылке</span><span className="num">{n(s.clicked)}</span><span className="pct">{share(s.clicked, s.delivered || s.sent)} от доставленных</span></div>}
        <div><span className="label">Не отправлено</span><span className="num" style={{ color: notSent ? 'var(--err)' : undefined }}>{n(notSent + s.cancelled)}</span>
          <span className="pct">{[s.bounced && `${n(s.bounced)} отказ сервера`, s.failed && `${n(s.failed)} ошибка`, s.blocked && `${n(s.blocked)} в блокировках`, s.cancelled && `${n(s.cancelled)} отменено`].filter(Boolean).join(', ') || 'без потерь'}</span></div>
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

// значение для <input type="datetime-local"> во времени компьютера
function toLocalInput(iso: string): string {
  const d = new Date(iso)
  const pad = (v: number) => String(v).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
