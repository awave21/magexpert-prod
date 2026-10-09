import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Blocks, Check, Code2, Copy, Lock, Monitor, MoreHorizontal, PencilRuler, Send, Smartphone, Trash2, Type, Unlock } from 'lucide-react'
import { api, ApiError, type Domain, type Folder, type Message, type Template, type Variables } from '../api'
import { useAuth } from '../auth'
import { CopyButton, Modal, ago, useToast } from '../components/ui'

type Form = { slug: string; name: string; subject: string; body_html: string; body_text: string; folder_id: number | null }
type Field = 'subject' | 'body_html' | 'body_text'

const EMPTY: Form = {
  slug: '', name: '', subject: '', folder_id: null, body_text: '',
  body_html: '<p>Здравствуйте, {{ name }}!</p>\n<p>Текст письма.</p>\n',
}

const VAR_RE = /\{\{\s*(?:#if\s+)?([a-zA-Z0-9_.]+)\s*\}\}/g
const varsOf = (f: Form) => {
  const found = new Set<string>()
  for (const src of [f.subject, f.body_html, f.body_text]) for (const m of src.matchAll(VAR_RE)) found.add(m[1])
  return [...found]
}

const esc = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')

// те же правила, что в TemplateRenderer на сервере: {{#if x}}…{{/if}} выводится, если x заполнена
const render = (src: string, data: Record<string, string>, escape = true) =>
  src
    .replace(/\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}([\s\S]*?)\{\{\/if\}\}/g, (_, k: string, body: string) => (data[k] ? body : ''))
    .replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_, k: string) => (escape ? esc(data[k] ?? '') : (data[k] ?? '')))

// текстовая версия из HTML: переносы на месте блоков, без тегов
const htmlToText = (html: string) =>
  html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|h[1-6]|li|tr)>/gi, '\n')
    .replace(/<li[^>]*>/gi, '• ')
    .replace(/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/gi, '$2 ($1)')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()

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
  const [tab, setTab] = useState<'html' | 'text'>('html')
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  const [slugLocked, setSlugLocked] = useState(!isNew)
  const [menu, setMenu] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [testOpen, setTestOpen] = useState(false)
  const [testTo, setTestTo] = useState('')
  const lastField = useRef<Field>('body_html')
  const refs = { subject: useRef<HTMLInputElement>(null), body_html: useRef<HTMLTextAreaElement>(null), body_text: useRef<HTMLTextAreaElement>(null) }

  const fq = useQuery({ queryKey: ['template-folders'], queryFn: () => api<{ data: Folder[] }>('/template-folders') })
  const vq = useQuery({ queryKey: ['variables'], queryFn: () => api<Variables>('/variables') })
  const dq = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const q = useQuery({ queryKey: ['template', id], queryFn: () => api<{ data: Template }>(`/templates/${id}`), enabled: !isNew })

  useEffect(() => {
    const t = q.data?.data
    if (!t) return
    const f = { slug: t.slug, name: t.name, subject: t.subject, body_html: t.body_html, body_text: t.body_text ?? '', folder_id: t.folder_id ?? null }
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
  const used = useMemo(() => varsOf(form), [form])
  const data = useMemo(() => {
    const d: Record<string, string> = {}
    for (const [k, v] of known) d[k] = v.sample
    return d
  }, [known])

  const isBlocks = q.data?.data.editor === 'blocks'
  const dirty = isNew ? form.name !== '' || form.subject !== '' : saved !== null && JSON.stringify(saved) !== JSON.stringify(form)
  const verified = dq.data?.data.find((d) => d.status === 'verified')
  const fromLine = verified ? `noreply@${verified.domain}` : 'адрес из подтверждённого домена'

  // предупреждение при уходе со страницы с несохранёнными правками
  useEffect(() => {
    if (!dirty) return
    const h = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [dirty])

  const set = (k: keyof Form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))



  const save = useMutation({
    mutationFn: () => api<{ data: Template }>(isNew ? '/templates' : `/templates/${id}`, { method: isNew ? 'POST' : 'PUT', body: { ...form, body_text: form.body_text || null } }),
    onSuccess: (r) => {
      setErrors({})
      setSaved(form)
      qc.invalidateQueries({ queryKey: ['templates'] })
      qc.invalidateQueries({ queryKey: ['template-folders'] })
      qc.setQueryData(['template', String(r.data.id)], r)
      toast('Шаблон сохранён')
      if (isNew) nav(`/templates/${r.data.id}`, { replace: true })
    },
    onError: (e) => { if (e instanceof ApiError) { setErrors(e.errors); toast(e.message, true) } },
  })
  const remove = useMutation({
    mutationFn: () => api(`/templates/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['templates'] }); toast('Шаблон удалён'); nav('/templates') },
  })
  const duplicate = useMutation({
    mutationFn: () => api<{ data: Template }>('/templates', { method: 'POST', body: { ...form, name: `${form.name} (копия)`, slug: `${form.slug}-copy-${Date.now().toString(36).slice(-4)}`, body_text: form.body_text || null } }),
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
  const apiExample = JSON.stringify({ template: form.slug || 'template-key', to: 'anna@example.com', data: Object.fromEntries(used.map((k) => [k, data[k] || '…'])) }, null, 2)

  if (!isNew && q.isError) return <main className="page"><div className="callout err"><div><b>Шаблон не найден</b></div></div></main>
  if (!isNew && !saved) return <main className="page muted">Загрузка…</main>

  return (
    <main className="page tpl">
      <form onSubmit={submit}>
        {/* шапка: путь, название, состояние сохранения, действия */}
        <div className="tpl-head">
          <div className="tpl-title">
            <Link to={backTo} className="back"><ArrowLeft size={15} />{folderName ?? 'Все шаблоны'}</Link>
            <h1>{isNew ? 'Новый шаблон' : form.name || 'Без названия'}</h1>
            <div className="tpl-meta">
              {form.slug && <span className="chip mono">{form.slug}</span>}
              <span className={`save-state${dirty ? ' dirty' : ''}`}>
                {dirty ? <><i />Есть несохранённые изменения</> : isNew ? 'Ещё не сохранён' : <><Check size={13} />Сохранено {ago(q.data?.data.updated_at ?? null)}</>}
              </span>
            </div>
          </div>
          <div className="row">
            {!isNew && isBlocks && <Link to={`/templates/${id}/blocks`} className="btn primary"><PencilRuler size={15} />Редактировать письмо</Link>}
            {!isNew && <button type="button" className="btn" onClick={() => { setTestTo(user?.email ?? ''); setTestOpen(true) }}><Send size={15} />Отправить тест</button>}
            <button className={`btn${isBlocks ? '' : ' primary'}`} disabled={save.isPending || (!dirty && !isNew)} title="Cmd+S">{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
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
                <input id="subject" ref={refs.subject} className={`input${err('subject') ? ' err' : ''}`} value={form.subject} onChange={set('subject')}
                  onFocus={() => { lastField.current = 'subject' }} placeholder="Вы зарегистрированы: {{ event_title }}" />
                {err('subject') && <div className="hint err">{err('subject')}</div>}
              </div>
            </section>

            <section className="tpl-section">
              <div className="tpl-bar">
                <div className="seg" role="tablist" aria-label="Версия письма">
                  <button type="button" role="tab" aria-selected={tab === 'html'} className={tab === 'html' ? 'on' : ''} onClick={() => setTab('html')}><Code2 size={14} />HTML</button>
                  <button type="button" role="tab" aria-selected={tab === 'text'} className={tab === 'text' ? 'on' : ''} onClick={() => setTab('text')}><Type size={14} />Текст</button>
                </div>
                {tab === 'text' && (
                  <button type="button" className="btn sm text" onClick={() => setForm((f) => ({ ...f, body_text: htmlToText(f.body_html) }))}>Собрать из HTML</button>
                )}
                {tab === 'html' && !isNew && !isBlocks && (
                  <Link to={`/templates/${id}/blocks`} className="btn sm text"><Blocks size={14} />Собрать из блоков</Link>
                )}
              </div>
              {tab === 'html' && isBlocks ? (
                <div className="tpl-blocks-card">
                  <Blocks size={22} />
                  <div><b>Письмо собрано из блоков</b><span>Текст, картинки и кнопки меняются в редакторе блоков. HTML собирается из них автоматически.</span></div>
                  <Link to={`/templates/${id}/blocks`} className="btn primary"><PencilRuler size={15} />Открыть редактор</Link>
                </div>
              ) : tab === 'html' ? (
                <textarea ref={refs.body_html} className={`textarea code tpl-code${err('body_html') ? ' err' : ''}`} aria-label="HTML письма" value={form.body_html}
                  onChange={set('body_html')} onFocus={() => { lastField.current = 'body_html' }} spellCheck={false} />
              ) : (
                <textarea ref={refs.body_text} className="textarea tpl-code" aria-label="Текстовая версия" value={form.body_text}
                  onChange={set('body_text')} onFocus={() => { lastField.current = 'body_text' }}
                  placeholder="Необязательно. Почтовые программы без HTML покажут этот текст, и письмо реже попадает в спам." />
              )}
              <div className={`hint${err('body_html') ? ' err' : ''}`}>{err('body_html') ?? 'Переменная: {{ name }}. Блок только при заполненной переменной: {{#if name}}…{{/if}}'}</div>
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
                  <label htmlFor="slug">Ключ шаблона</label>
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

            {!isNew && (
              <details className="tpl-section tpl-dev">
                <summary><h2>Для разработчика</h2><span className="sub">Как отправить письмо по этому шаблону</span></summary>
                <div className="sub" style={{ margin: '10px 0 8px' }}><span className="mono">POST /api/sender/v1/messages</span> с заголовком <span className="mono">Authorization: Bearer mxs_…</span></div>
                <div className="code-block">
                  <pre className="mono">{apiExample}</pre>
                  <CopyButton text={apiExample} label="Скопировать пример" />
                </div>
              </details>
            )}
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
                  <div><b>{user?.organization ?? 'Отправитель'}</b><span className="sub">{fromLine}</span></div>
                </div>
                <div className="inbox-subject">{render(form.subject, data, false) || <span className="muted">Без темы</span>}</div>
              </div>
              {tab === 'text'
                ? <pre className="inbox-text">{render(form.body_text, data, false) || 'Текстовой версии нет: получатель без HTML увидит пустое письмо.'}</pre>
                : <iframe className="inbox-frame" title="Предпросмотр письма" sandbox="allow-same-origin" srcDoc={previewHtml} />}
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
              <div className="hint">Отправим с {fromLine} с теми же значениями переменных, что в предпросмотре. Письмо появится в журнале.</div>
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
        <Modal title="Удалить шаблон?" onClose={() => setConfirmDelete(false)}>
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
