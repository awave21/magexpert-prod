import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Folder, type Template, type Variables } from '../api'
import { BackLink, Modal, PageHead, useToast } from '../components/ui'

type Form = { slug: string; name: string; subject: string; body_html: string; body_text: string; folder_id: number | null }
const EMPTY: Form = { slug: '', name: '', subject: '', body_html: '<p>Здравствуйте, {{ name }}!</p>\n', body_text: '', folder_id: null }

const varsOf = (f: Form) => {
  const set = new Set<string>()
  for (const src of [f.subject, f.body_html, f.body_text]) {
    for (const m of src.matchAll(/\{\{\s*(?:#if\s+)?([a-zA-Z0-9_.]+)\s*\}\}/g)) set.add(m[1])
  }
  return [...set]
}

const esc = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')

// те же правила, что в TemplateRenderer на сервере: блок {{#if x}} показывается, если x заполнена
const renderHtml = (src: string, data: Record<string, string>) =>
  src
    .replace(/\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}([\s\S]*?)\{\{\/if\}\}/g, (_, k: string, body: string) => (data[k] ? body : ''))
    .replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_, k: string) => esc(data[k] ?? ''))

export default function TemplateEditor() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const isNew = id === 'new'
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState<Form>(() => ({ ...EMPTY, folder_id: Number(params.get('folder')) || null }))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [sample, setSample] = useState<Record<string, string>>({})
  const [preview, setPreview] = useState<{ subject: string; html: string } | null>(null)
  const [confirm, setConfirm] = useState(false)
  const [htmlOpen, setHtmlOpen] = useState(false)
  const codeRef = useRef<HTMLTextAreaElement>(null)

  const fq = useQuery({ queryKey: ['template-folders'], queryFn: () => api<{ data: Folder[] }>('/template-folders') })
  const vq = useQuery({ queryKey: ['variables'], queryFn: () => api<Variables>('/variables') })
  const known = useMemo(() => {
    const m = new Map<string, { label: string; sample: string }>()
    for (const v of vq.data?.base ?? []) m.set(v.key, { label: v.label, sample: v.sample })
    for (const v of vq.data?.custom ?? []) m.set(v.key, { label: v.label, sample: v.default_value ?? '' })
    return m
  }, [vq.data])

  const q = useQuery({ queryKey: ['template', id], queryFn: () => api<{ data: Template }>(`/templates/${id}`), enabled: !isNew })
  useEffect(() => {
    const t = q.data?.data
    if (t) setForm({ slug: t.slug, name: t.name, subject: t.subject, body_html: t.body_html, body_text: t.body_text ?? '', folder_id: t.folder_id ?? null })
  }, [q.data])

  const vars = useMemo(() => varsOf(form), [form])
  const data = useMemo(() => {
    const d: Record<string, string> = {}
    for (const [k, v] of known) d[k] = v.sample
    return { ...d, ...sample }
  }, [known, sample])
  const insertVar = (key: string) => {
    const el = codeRef.current
    const token = `{{ ${key} }}`
    const start = el?.selectionStart ?? form.body_html.length
    const end = el?.selectionEnd ?? start
    setForm((f) => ({ ...f, body_html: f.body_html.slice(0, start) + token + f.body_html.slice(end) }))
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length) })
  }
  const set = (k: keyof Form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const save = useMutation({
    mutationFn: () => api<{ data: Template }>(isNew ? '/templates' : `/templates/${id}`, { method: isNew ? 'POST' : 'PUT', body: { ...form, body_text: form.body_text || null } }),
    onSuccess: (r) => {
      setErrors({})
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

  // предпросмотр: для сохранённого шаблона через API (те же правила подстановки, что при отправке)
  useEffect(() => {
    if (isNew) return
    const t = setTimeout(() => {
      api<{ data: { subject: string; html: string } }>(`/templates/${id}/preview`, { method: 'POST', body: { data } })
        .then((r) => setPreview(r.data)).catch(() => {})
    }, 300)
    return () => clearTimeout(t)
  }, [id, isNew, data, q.data])

  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }
  const err = (k: string) => errors[k]?.[0]
  const dirty = q.data?.data && (q.data.data.body_html !== form.body_html || q.data.data.subject !== form.subject)

  return (
    <main className="page">
      <form onSubmit={submit}>
        <BackLink to={form.folder_id ? `/templates?folder=${form.folder_id}` : '/templates'}>Все шаблоны</BackLink>
        <PageHead title={isNew ? 'Новый шаблон' : form.name || 'Шаблон'} sub={isNew ? 'Ключ шаблона приложение передаёт при отправке письма' : undefined}
          actions={<>
            {!isNew && <button type="button" className="btn danger" onClick={() => setConfirm(true)}>Удалить</button>}
            <button className="btn primary" disabled={save.isPending}>{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
          </>} />

        <div className="split">
          <div className="stack">
            <div className="row" style={{ alignItems: 'flex-start', flexWrap: 'nowrap', gap: 16 }}>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="name">Название</label>
                <input id="name" className={`input${err('name') ? ' err' : ''}`} value={form.name} onChange={set('name')} placeholder="Регистрация на мероприятие" />
                {err('name') && <div className="hint err">{err('name')}</div>}
              </div>
              <div className="field" style={{ flex: 1 }}>
                <label htmlFor="slug">Ключ</label>
                <input id="slug" className={`input mono${err('slug') ? ' err' : ''}`} value={form.slug} onChange={set('slug')} placeholder="event-registration" />
                <div className={`hint${err('slug') ? ' err' : ''}`}>{err('slug') ?? 'Латиница, цифры, дефис'}</div>
              </div>
            </div>
            <div className="field">
              <label htmlFor="folder">Папка (проект)</label>
              <select id="folder" className={`select${err('folder_id') ? ' err' : ''}`} value={form.folder_id ?? ''} onChange={(e) => setForm((f) => ({ ...f, folder_id: e.target.value ? Number(e.target.value) : null }))}>
                <option value="">Без папки</option>
                {(fq.data?.data ?? []).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
              </select>
              {err('folder_id') && <div className="hint err">{err('folder_id')}</div>}
            </div>
            <div className="field">
              <label htmlFor="subject">Тема письма</label>
              <input id="subject" className={`input${err('subject') ? ' err' : ''}`} value={form.subject} onChange={set('subject')} placeholder="Вы зарегистрированы: {{ event }}" />
              {err('subject') && <div className="hint err">{err('subject')}</div>}
            </div>
            <div className="field">
              <label>HTML письма</label>
              <div className={`html-card${err('body_html') ? ' err' : ''}`}>
                <div>
                  <b>{form.body_html.trim() ? `${form.body_html.length.toLocaleString('ru-RU')} символов` : 'Пока пусто'}</b>
                  <div className="sub">Код письма скрыт: откройте редактор, чтобы изменить его.</div>
                </div>
                <button type="button" className="btn" onClick={() => setHtmlOpen(true)}>Открыть редактор</button>
              </div>
              <div className={`hint${err('body_html') ? ' err' : ''}`}>{err('body_html') ?? 'Переменные: {{ name }}. Условный блок: {{#if name}}…{{/if}}'}</div>
            </div>
            <div className="field">
              <label htmlFor="text">Текстовая версия</label>
              <textarea id="text" className="textarea" rows={5} value={form.body_text} onChange={set('body_text')} placeholder="Необязательно. Помогает доставляемости." />
            </div>
          </div>

          <div className="stack" style={{ position: 'sticky', top: 80 }}>
            <div>
              <div className="label" style={{ marginBottom: 8 }}>Как увидит получатель {dirty && <span className="muted">· сохраните, чтобы обновить</span>}</div>
              {isNew ? <div className="hint">Предпросмотр появится после первого сохранения</div> : (
                <>
                  <div className="sub" style={{ marginBottom: 6 }}>Тема: <b style={{ color: 'var(--ink)', fontWeight: 500 }}>{preview?.subject}</b></div>
                  <iframe className="preview-frame" title="Предпросмотр письма" sandbox="allow-same-origin" srcDoc={`<!doctype html><meta charset="utf-8"><style>body{font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#1C1F27;margin:24px}</style>${preview?.html ?? ''}`} />
                </>
              )}
            </div>
          </div>
        </div>
      </form>

      {htmlOpen && (
        <div className="editor" role="dialog" aria-modal="true" aria-label="Редактор HTML письма">
          <div className="editor-bar">
            <div>
              <b>HTML письма</b>
              <span className="muted"> · {form.name || 'шаблон'}</span>
            </div>
            <button type="button" className="btn primary" onClick={() => setHtmlOpen(false)}>Готово</button>
          </div>
          <div className="editor-body">
            <textarea
              ref={codeRef}
              className="textarea code editor-code"
              aria-label="HTML письма"
              autoFocus
              value={form.body_html}
              onChange={set('body_html')}
              onKeyDown={(e) => e.key === 'Escape' && setHtmlOpen(false)}
              spellCheck={false}
            />
            <div className="editor-side">
              <div className="editor-vars">
                <div className="label">Переменные {vars.length > 0 && <span className="muted">· тестовые значения для предпросмотра</span>}</div>
                <div className="sub" style={{ marginTop: 4 }}>Нажмите, чтобы вставить в письмо:</div>
                <div className="var-chips">
                  {[...known.entries()].map(([key, v]) => (
                    <button type="button" key={key} className={`chip mono${vars.includes(key) ? ' accent' : ''}`} title={v.label} onClick={() => insertVar(key)}>{key}</button>
                  ))}
                </div>
                {vars.length === 0 ? <div className="hint">В шаблоне нет переменных</div> : (
                  <div className="vars-grid">
                    {vars.map((v) => (
                      <label key={v} className="var-row">
                        <span className="chip accent mono">{v}</span>
                        <input className="input" style={{ height: 36 }} aria-label={`Значение ${v}`} value={data[v] ?? ''} onChange={(e) => setSample((x) => ({ ...x, [v]: e.target.value }))} />
                      </label>
                    ))}
                  </div>
                )}
              </div>
              <iframe className="editor-preview" title="Предпросмотр HTML" sandbox="allow-same-origin" srcDoc={`<!doctype html><meta charset="utf-8"><style>body{font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#1C1F27;margin:24px}</style>${renderHtml(form.body_html, data)}`} />
            </div>
          </div>
          <div className="editor-foot">Изменения применятся к шаблону после кнопки «Сохранить» на странице шаблона. Значения переменных нужны только для предпросмотра: при отправке их передаёт приложение.</div>
        </div>
      )}

      {confirm && (
        <Modal title="Удалить шаблон?" onClose={() => setConfirm(false)}>
          <p style={{ color: 'var(--ink-2)' }}>Приложение не сможет отправлять письма по ключу <span className="mono">{form.slug}</span>.</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setConfirm(false)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate()}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
