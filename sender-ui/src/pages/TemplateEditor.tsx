import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Blocks, Check, Copy, Lock, Monitor, MoreHorizontal, PencilRuler, Send, Smartphone, Trash2, Unlock } from 'lucide-react'
import { api, ApiError, type Domain, type SenderAddress, type Folder, type Message, type Template, type Variables } from '../api'
import { useAuth } from '../auth'
import { CopyButton, Modal, ago, useToast } from '../components/ui'

type Form = { slug: string; name: string; subject: string; body_html: string; body_text: string; folder_id: number | null; sender_address_id: number | null; reply_to: string; preheader: string }

const EMPTY: Form = {
  sender_address_id: null, reply_to: '', preheader: '',
  slug: '', name: '', subject: '', folder_id: null, body_text: '',
  body_html: '<p>Здравствуйте, {{ name }}!</p>\n<p>Текст письма.</p>\n',
}

const esc = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')

// те же правила, что в TemplateRenderer на сервере: {{#if x}}…{{/if}} выводится, если x заполнена
// условия раскрываются изнутри наружу, как в TemplateRenderer на сервере
const IF_RE = /\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}((?:(?!\{\{#if\s)[\s\S])*?)\{\{\/if\}\}/g
const render = (src: string, data: Record<string, string>, escape = true) => {
  let out = src
  for (let prev = ''; prev !== out;) { prev = out; out = out.replace(IF_RE, (_, k: string, body: string) => (data[k] ? body : '')) }
  return out.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_, k: string) => (escape ? esc(data[k] ?? '') : (data[k] ?? '')))
}

const payload = (f: Form) => ({ ...f, body_text: f.body_text || null, reply_to: f.reply_to || null, preheader: f.preheader || null })

const FRAME_CSS = 'body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#1C1F27;margin:0;padding:28px 24px;background:#fff;word-wrap:break-word}img{max-width:100%;height:auto}a{color:#3B51D3}'

export default function TemplateEditor() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const isNew = id === 'new'
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { user } = useAuth()

  const [form, setForm] = useState<Form>(() => ({ ...EMPTY, folder_id: Number(params.get('folder')) || null }))
  const [saved, setSaved] = useState<Form | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  const [slugLocked, setSlugLocked] = useState(!isNew)
  const [menu, setMenu] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [testOpen, setTestOpen] = useState(false)
  const [testTo, setTestTo] = useState('')

  const fq = useQuery({ queryKey: ['template-folders'], queryFn: () => api<{ data: Folder[] }>('/template-folders') })
  const vq = useQuery({ queryKey: ['variables'], queryFn: () => api<Variables>('/variables') })
  const dq = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const aq = useQuery({ queryKey: ['sender-addresses'], queryFn: () => api<{ data: SenderAddress[] }>('/sender-addresses') })
  const q = useQuery({ queryKey: ['template', id], queryFn: () => api<{ data: Template }>(`/templates/${id}`), enabled: !isNew })

  useEffect(() => {
    const t = q.data?.data
    if (!t) return
    const f = { slug: t.slug, name: t.name, subject: t.subject, body_html: t.body_html, body_text: t.body_text ?? '', folder_id: t.folder_id ?? null, sender_address_id: t.sender_address_id ?? null, reply_to: t.reply_to ?? '', preheader: t.preheader ?? '' }
    setForm(f)
    setSaved(f)
  }, [q.data])

  // справочник переменных: базовые и свои организации, с примерами для предпросмотра
  const known = useMemo(() => {
    const m = new Map<string, { label: string; sample: string; custom: boolean }>()
    for (const v of vq.data?.base ?? []) m.set(v.key, { label: v.label, sample: v.sample, custom: false })
    for (const v of vq.data?.custom ?? []) m.set(v.key, { label: v.label, sample: v.default_value ?? '', custom: true })
    return m
  }, [vq.data])
  const data = useMemo(() => {
    const d: Record<string, string> = {}
    for (const [k, v] of known) d[k] = v.sample
    return d
  }, [known])

  const isBlocks = q.data?.data.editor === 'blocks'
  const dirty = isNew ? form.name !== '' || form.subject !== '' : saved !== null && JSON.stringify(saved) !== JSON.stringify(form)
  const verified = dq.data?.data.find((d) => d.status === 'verified')
  const addresses = aq.data?.data ?? []
  const sender = addresses.find((x) => x.id === form.sender_address_id) ?? null
  const fromLine = sender?.email ?? (verified ? `noreply@${verified.domain}` : 'адрес не выбран')
  const fromName = sender?.name ?? user?.organization ?? 'Отправитель'

  // предупреждение при уходе со страницы с несохранёнными правками
  useEffect(() => {
    if (!dirty) return
    const h = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [dirty])

  const set = (k: keyof Form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))



  const save = useMutation({
    mutationFn: () => api<{ data: Template }>(isNew ? '/templates' : `/templates/${id}`, { method: isNew ? 'POST' : 'PUT', body: payload(form) }),
    onSuccess: (r) => {
      setErrors({})
      setSaved(form)
      qc.invalidateQueries({ queryKey: ['templates'] })
      qc.invalidateQueries({ queryKey: ['template-folders'] })
      qc.setQueryData(['template', String(r.data.id)], r)
      toast('Письмо сохранено')
      if (isNew) nav(`/templates/${r.data.id}`, { replace: true })
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast(e.message, true) } },
  })
  const remove = useMutation({
    mutationFn: () => api(`/templates/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['templates'] }); toast('Письмо удалено'); nav('/templates') },
  })
  const duplicate = useMutation({
    mutationFn: () => api<{ data: Template }>('/templates', { method: 'POST', body: { ...payload(form), name: `${form.name} (копия)`, slug: `${form.slug}-copy-${Date.now().toString(36).slice(-4)}` } }),
    onSuccess: (r) => { qc.invalidateQueries({ queryKey: ['templates'] }); toast('Копия создана'); nav(`/templates/${r.data.id}`) },
    onError: (e) => toast((e as Error).message, true),
  })
  const sendTest = useMutation({
    mutationFn: async () => {
      if (dirty) await save.mutateAsync()
      return api<{ data: Message }>(`/templates/${id}/test`, { method: 'POST', body: { to: testTo, data } })
    },
    onSuccess: (r) => {
      setTestOpen(false)
      toast(r.data.status === 'blocked' ? `Не отправлено: ${r.data.error}` : `Тест поставлен в очередь на ${r.data.to}`, r.data.status === 'blocked')
    },
    onError: (e) => toast(e instanceof ApiError ? (e.errors.to?.[0] ?? e.message) : 'Не удалось отправить', true),
  })

  const submit = useCallback((e?: FormEvent) => { e?.preventDefault(); if (!save.isPending) save.mutate() }, [save])

  // Cmd/Ctrl+S сохраняет
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); submit() }
    }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [submit])

  const err = (k: string) => errors[k]?.[0]
  const backTo = form.folder_id ? `/templates?folder=${form.folder_id}` : '/templates'
  const folderName = fq.data?.data.find((f) => f.id === form.folder_id)?.name
  const previewHtml = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>${FRAME_CSS}</style>${render(form.body_html, data)}`

  if (!isNew && q.isError) return <main className="page"><div className="callout err"><div><b>Письмо не найдено</b></div></div></main>
  if (!isNew && !saved) return <main className="page muted">Загрузка…</main>

  return (
    <main className="page tpl">
      <form onSubmit={submit}>
        {/* шапка: путь, название, состояние сохранения, действия */}
        <div className="tpl-head">
          <div className="tpl-title">
            <Link to={backTo} className="back"><ArrowLeft size={15} />{folderName ?? 'Весь контент'}</Link>
            <h1>{isNew ? 'Новое письмо' : form.name || 'Без названия'}</h1>
            <div className="tpl-meta">
              {!isNew && <span className="chip mono id-chip" title="ID письма: его передаёт приложение при отправке">ID {id}<CopyButton text={String(id)} label="Скопировать ID шаблона" /></span>}
              <span className={`save-state${dirty ? ' dirty' : ''}`}>
                {dirty ? <><i />Есть несохранённые изменения</> : isNew ? 'Ещё не сохранён' : <><Check size={13} />Сохранено {ago(q.data?.data.updated_at ?? null)}</>}
              </span>
            </div>
          </div>
          <div className="row">
            {!isNew && <button type="button" className="btn" onClick={() => { setTestTo(user?.email ?? ''); setTestOpen(true) }}><Send size={15} />Отправить тест</button>}
            <button className="btn primary" disabled={save.isPending || (!dirty && !isNew)} title="Cmd+S">{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
            {!isNew && (
              <div className="menu-wrap">
                <button type="button" className="btn icon ghost" aria-label="Ещё" aria-expanded={menu} onClick={() => setMenu((m) => !m)}><MoreHorizontal size={18} /></button>
                {menu && (
                  <div className="menu" role="menu" onMouseLeave={() => setMenu(false)}>
                    <button type="button" role="menuitem" onClick={() => { setMenu(false); duplicate.mutate() }}><Copy size={15} />Создать копию</button>
                    <button type="button" role="menuitem" className="danger" onClick={() => { setMenu(false); setConfirmDelete(true) }}><Trash2 size={15} />Удалить шаблон</button>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        <div className="tpl-grid">
          {/* левая колонка: содержимое */}
          <div className="tpl-edit">
            <section className="tpl-section">
              <div className="field">
                <label htmlFor="subject">Тема письма</label>
                <input id="subject" className={`input${err('subject') ? ' err' : ''}`} value={form.subject} onChange={set('subject')} placeholder="Вы зарегистрированы: {{ event_title }}" />
                {err('subject') && <div className="hint err">{err('subject')}</div>}
              </div>
            </section>

            <section className="tpl-section">
              <div className="tpl-sub"><h2>Письмо</h2></div>
              {isNew ? (
                <div className="hint">Сохраните письмо, затем соберите письмо в редакторе.</div>
              ) : (
                <div className="tpl-open">
                  <div className="tpl-open-head">
                    <span className="tpl-open-ic"><Blocks size={22} /></span>
                    <div>
                      <b>{isBlocks ? 'Собрано из блоков' : 'Написано кодом'}</b>
                      <span>{isBlocks ? 'Текст, картинки, кнопки и стили' : 'Перенесите в блоки, чтобы править без кода'}</span>
                    </div>
                  </div>
                  <Link to={`/templates/${id}/blocks`} className="btn primary lg tpl-open-btn"><PencilRuler size={17} />{isBlocks ? 'Открыть редактор' : 'Собрать из блоков'}</Link>
                </div>
              )}
              {err('body_html') && <div className="hint err">{err('body_html')}</div>}
            </section>

            <section className="tpl-section">
              <div className="tpl-sub"><h2>Отправка</h2></div>
              <div className="tpl-settings">
                <div className="field span2">
                  <label htmlFor="sender">Отправитель</label>
                  <select id="sender" className={`select${err('sender_address_id') ? ' err' : ''}`} value={form.sender_address_id ?? ''}
                    onChange={(e) => setForm((f) => ({ ...f, sender_address_id: e.target.value ? Number(e.target.value) : null }))}>
                    <option value="">{addresses.length ? 'Выберите адрес' : 'Адресов пока нет'}</option>
                    {addresses.map((x) => <option key={x.id} value={x.id}>{x.name} · {x.email}{!x.confirmed ? ' (ждёт подтверждения)' : !x.verified ? ' (домен не подтверждён)' : ''}</option>)}
                  </select>
                  <div className={`hint${err('sender_address_id') ? ' err' : sender && (!sender.verified || !sender.confirmed) ? ' warn-text' : ''}`}>
                    {err('sender_address_id') ?? (sender && !sender.confirmed
                      ? `Адрес не подтверждён: откройте письмо со ссылкой в ящике ${sender.email}. До этого письма по шаблону не уходят.`
                      : sender && !sender.verified
                      ? 'Домен этого адреса не подтверждён: письма не уйдут, пока не внесены DNS-записи.'
                      : <>Адреса и имена отправителей задаются в разделе <Link to="/domains">«Домены»</Link>.</>)}
                  </div>
                </div>
                <div className="field span2">
                  <label htmlFor="reply-to">Ответы приходят на</label>
                  <input id="reply-to" type="email" className={`input mono${err('reply_to') ? ' err' : ''}`} value={form.reply_to} onChange={set('reply_to')} placeholder={sender?.email ?? 'support@mag-expert.ru'} />
                  <div className={`hint${err('reply_to') ? ' err' : ''}`}>{err('reply_to') ?? 'Если пусто, ответ уйдёт на адрес отправителя'}</div>
                </div>
                <div className="field span2">
                  <label htmlFor="preheader">Прехедер</label>
                  <input id="preheader" className={`input${err('preheader') ? ' err' : ''}`} value={form.preheader} onChange={set('preheader')} placeholder="Ссылка на трансляцию придёт за час до начала" maxLength={255} />
                  <div className={`hint${err('preheader') ? ' err' : ''}`}>{err('preheader') ?? 'Строка после темы в списке писем. Если пусто, почта покажет начало текста.'}</div>
                </div>
              </div>
            </section>

            <section className="tpl-section">
              <div className="tpl-sub"><h2>Настройки</h2></div>
              <div className="tpl-settings">
                <div className="field">
                  <label htmlFor="name">Название</label>
                  <input id="name" className={`input${err('name') ? ' err' : ''}`} value={form.name} onChange={set('name')} placeholder="Регистрация на мероприятие" />
                  {err('name') && <div className="hint err">{err('name')}</div>}
                </div>
                <div className="field">
                  <label htmlFor="folder">Папка</label>
                  <select id="folder" className="select" value={form.folder_id ?? ''} onChange={(e) => setForm((f) => ({ ...f, folder_id: e.target.value ? Number(e.target.value) : null }))}>
                    <option value="">Без папки</option>
                    {(fq.data?.data ?? []).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                  </select>
                </div>
                <div className="field span2">
                  <label htmlFor="slug">Алиас для API</label>
                  <div className="row" style={{ flexWrap: 'nowrap', gap: 8 }}>
                    <input id="slug" className={`input mono${err('slug') ? ' err' : ''}`} value={form.slug} onChange={set('slug')} readOnly={slugLocked} placeholder="event-registration" />
                    {!isNew && (
                      <button type="button" className="btn icon" onClick={() => setSlugLocked((l) => !l)} aria-label={slugLocked ? 'Разрешить изменение ключа' : 'Запретить изменение ключа'} title={slugLocked ? 'Изменить ключ' : 'Закрыть изменение'}>
                        {slugLocked ? <Lock size={15} /> : <Unlock size={15} />}
                      </button>
                    )}
                  </div>
                  <div className={`hint${err('slug') ? ' err' : !slugLocked && !isNew ? ' warn-text' : ''}`}>
                    {err('slug') ?? (!slugLocked && !isNew ? 'Приложение отправляет письма по этому ключу. После изменения поменяйте его и в коде сайта, иначе письма перестанут уходить.' : 'Латиница, цифры и дефис. По ключу приложение выбирает шаблон.')}
                  </div>
                </div>
              </div>
            </section>

          </div>

          {/* правая колонка: живой предпросмотр */}
          <aside className="tpl-preview" aria-label="Предпросмотр">
            <div className="tpl-bar">
              <span className="label">Как увидит получатель</span>
              <div className="seg" role="tablist" aria-label="Устройство">
                <button type="button" role="tab" aria-selected={device === 'desktop'} className={device === 'desktop' ? 'on' : ''} onClick={() => setDevice('desktop')} aria-label="Компьютер" title="Компьютер"><Monitor size={14} /></button>
                <button type="button" role="tab" aria-selected={device === 'mobile'} className={device === 'mobile' ? 'on' : ''} onClick={() => setDevice('mobile')} aria-label="Телефон" title="Телефон"><Smartphone size={14} /></button>
              </div>
            </div>
            <div className={`inbox ${device}`}>
              <div className="inbox-head">
                <div className="inbox-from"><span className="avatar sm">{(user?.organization ?? 'S').charAt(0)}</span>
                  <div><b>{fromName}</b><span className="sub">{fromLine}</span></div>
                </div>
                <div className="inbox-subject">{render(form.subject, data, false) || <span className="muted">Без темы</span>}</div>
                {form.preheader && <div className="inbox-pre">{render(form.preheader, data, false)}</div>}
                {form.reply_to && <div className="sub">Ответ: <span className="mono">{form.reply_to}</span></div>}
              </div>
              <iframe className="inbox-frame" title="Предпросмотр письма" sandbox="allow-same-origin" srcDoc={previewHtml} />
            </div>
            <div className="hint">Обновляется сразу, без сохранения. Значения переменных подставлены для примера: при отправке их передаёт приложение.</div>
          </aside>
        </div>
      </form>

      {testOpen && (
        <Modal title="Тестовое письмо" onClose={() => setTestOpen(false)}>
          <form className="stack" onSubmit={(e) => { e.preventDefault(); sendTest.mutate() }}>
            <div className="field">
              <label htmlFor="test-to">Кому</label>
              <input id="test-to" type="email" autoFocus className="input mono" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
              <div className="hint">Отправим с {fromLine} от имени «{fromName}» с примерами значений переменных. Письмо появится в журнале.</div>
            </div>
            {dirty && <div className="callout warn"><div><b>Есть несохранённые изменения</b><span>Сначала сохраним шаблон, потом отправим.</span></div></div>}
            {!verified && <div className="callout err"><div><b>Нет подтверждённого домена</b><span>Подтвердите домен в разделе «Домены», иначе тест не отправится.</span></div></div>}
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setTestOpen(false)}>Отмена</button>
              <button className="btn primary" disabled={!testTo.trim() || sendTest.isPending || !verified}><Send size={15} />{dirty ? 'Сохранить и отправить' : 'Отправить'}</button>
            </div>
          </form>
        </Modal>
      )}

      {confirmDelete && (
        <Modal title="Удалить письмо?" onClose={() => setConfirmDelete(false)}>
          <p style={{ color: 'var(--ink-2)' }}>Приложение не сможет отправлять письма по ключу <span className="mono">{form.slug}</span>. Журнал отправленных писем сохранится.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setConfirmDelete(false)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate()}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
