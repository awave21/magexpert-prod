import { useCallback, useEffect, useMemo, useReducer, useState, type DragEvent, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Editor } from '@tiptap/react'
import {
  ArrowDown, ArrowLeft, ArrowUp, Check, Code2, Columns2, Copy, FileCode2, Footprints, GripVertical, Heading, Image as ImageIcon,
  LayoutTemplate, Minus, Monitor, MoveVertical, Redo2, RectangleHorizontal, Send, Share2, Smartphone, Trash2, Type, Undo2,
} from 'lucide-react'
import { api, ApiError, type Domain, type Message, type Template, type Variables } from '../api'
import { useAuth } from '../auth'
import { CopyButton, Modal, useToast } from '../components/ui'
import {
  BLOCK_LABELS, ROOT, normalizeDesign, cloneBlock, countBlocks, createBlock, emptyDesign, findBlock, insertBlock, locate, moveBlockTo,
  removeBlock, shiftBlock, starterDesign, updateBlock, type Block, type BlockType, type Container, type Design, type Settings,
} from '../editor/model'
import { designToHtml, htmlToText } from '../editor/render'
import { FormatToolbar, RichText, highlightVars } from '../editor/RichText'
import { BlockInspector, StylesPanel, UploadButton } from '../editor/Inspector'

const TILES: { type: BlockType; icon: ReactNode }[] = [
  { type: 'heading', icon: <Heading size={20} /> },
  { type: 'text', icon: <Type size={20} /> },
  { type: 'image', icon: <ImageIcon size={20} /> },
  { type: 'button', icon: <RectangleHorizontal size={20} /> },
  { type: 'columns', icon: <Columns2 size={20} /> },
  { type: 'divider', icon: <Minus size={20} /> },
  { type: 'spacer', icon: <MoveVertical size={20} /> },
  { type: 'social', icon: <Share2 size={20} /> },
  { type: 'footer', icon: <Footprints size={20} /> },
  { type: 'html', icon: <FileCode2 size={20} /> },
]
const ICON: Record<BlockType, ReactNode> = Object.fromEntries(TILES.map((t) => [t.type, t.icon])) as Record<BlockType, ReactNode>

// ---------- история изменений ----------

type History = { past: Design[]; present: Design; future: Design[]; key: string | null; at: number }
type HistoryAction =
  | { type: 'reset'; design: Design }
  | { type: 'set'; design: Design; key?: string }
  | { type: 'undo' }
  | { type: 'redo' }

function historyReducer(h: History, a: HistoryAction): History {
  switch (a.type) {
    case 'reset': return { past: [], present: a.design, future: [], key: null, at: 0 }
    case 'set': {
      const now = Date.now()
      // подряд идущие правки одного поля (набор текста) объединяются в один шаг
      if (a.key && a.key === h.key && now - h.at < 1000) return { ...h, present: a.design, at: now }
      return { past: [...h.past.slice(-99), h.present], present: a.design, future: [], key: a.key ?? null, at: now }
    }
    case 'undo': return h.past.length ? { past: h.past.slice(0, -1), present: h.past[h.past.length - 1], future: [h.present, ...h.future], key: null, at: 0 } : h
    case 'redo': return h.future.length ? { past: [...h.past, h.present], present: h.future[0], future: h.future.slice(1), key: null, at: 0 } : h
  }
}

type Drag = { kind: 'new'; type: BlockType } | { kind: 'move'; id: string }

const isTyping = () => {
  const el = document.activeElement as HTMLElement | null
  return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))
}

export default function BlockEditor() {
  const { id } = useParams()
  const qc = useQueryClient()
  const toast = useToast()
  const { user } = useAuth()

  const q = useQuery({ queryKey: ['template', id], queryFn: () => api<{ data: Template }>(`/templates/${id}`) })
  const vq = useQuery({ queryKey: ['variables'], queryFn: () => api<Variables>('/variables') })
  const dq = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const template = q.data?.data

  const [h, dispatch] = useReducer(historyReducer, { past: [], present: emptyDesign(), future: [], key: null, at: 0 })
  const design = h.present
  const [ready, setReady] = useState(false)
  const [savedJson, setSavedJson] = useState('')
  const [selected, setSelected] = useState<string | null>(null)
  const [tab, setTab] = useState<'blocks' | 'styles'>('blocks')
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  const [drag, setDrag] = useState<Drag | null>(null)
  const [dropAt, setDropAt] = useState<string | null>(null)
  const [editor, setEditor] = useState<Editor | null>(null)
  const [htmlOpen, setHtmlOpen] = useState(false)
  const [testOpen, setTestOpen] = useState(false)
  const [testTo, setTestTo] = useState('')

  // первая загрузка: блоки из шаблона или выбор, с чего начать
  useEffect(() => {
    if (!template || ready) return
    if (template.editor === 'blocks' && template.design) {
      const d = normalizeDesign(template.design)
      dispatch({ type: 'reset', design: d })
      setSavedJson(JSON.stringify(d))
      setReady(true)
    }
  }, [template, ready])

  const start = (kind: 'starter' | 'empty' | 'wrap') => {
    let d = kind === 'starter' ? starterDesign() : emptyDesign()
    if (kind === 'wrap' && template) {
      const html = createBlock('html')
      d = { ...d, blocks: [{ ...html, html: template.body_html } as Block] }
    }
    dispatch({ type: 'reset', design: d })
    setSavedJson('')
    setReady(true)
  }

  const setDesign = useCallback((d: Design, key?: string) => dispatch({ type: 'set', design: d, key }), [])
  const setBlocks = useCallback((blocks: Block[], key?: string) => setDesign({ ...design, blocks }, key), [design, setDesign])
  const patchBlock = useCallback((bid: string, patch: Partial<Block>, key?: string) => setBlocks(updateBlock(design.blocks, bid, patch), key ?? `${bid}:${Object.keys(patch).join(',')}`), [design.blocks, setBlocks])
  const patchSettings = (p: Partial<Settings>) => setDesign({ ...design, settings: { ...design.settings, ...p } }, `settings:${Object.keys(p).join(',')}`)

  const selectedBlock = selected ? findBlock(design.blocks, selected) : null
  const dirty = ready && JSON.stringify(design) !== savedJson

  const variables = useMemo(() => [
    ...(vq.data?.base ?? []).map((v) => ({ key: v.key, label: v.label })),
    ...(vq.data?.custom ?? []).map((v) => ({ key: v.key, label: v.label })),
  ], [vq.data])

  // ---------- действия с блоками ----------

  const addBlock = (type: BlockType, at?: { container: Container; index: number }) => {
    const block = createBlock(type)
    let target = at
    if (!target) {
      const loc = selected ? locate(design.blocks, selected) : null
      target = loc ? { container: loc.container, index: loc.index + 1 } : { container: ROOT, index: design.blocks.length }
      if (type === 'columns' && target.container.parent !== null) {
        const parentLoc = locate(design.blocks, target.container.parent)
        target = { container: ROOT, index: (parentLoc?.index ?? design.blocks.length - 1) + 1 }
      }
    }
    setBlocks(insertBlock(design.blocks, target.container, target.index, block))
    setSelected(block.id)
  }
  const removeSelected = useCallback(() => {
    if (!selected) return
    setBlocks(removeBlock(design.blocks, selected))
    setSelected(null)
  }, [selected, design.blocks, setBlocks])
  const duplicate = (bid: string) => {
    const loc = locate(design.blocks, bid)
    const b = findBlock(design.blocks, bid)
    if (!loc || !b) return
    const copy = cloneBlock(b)
    setBlocks(insertBlock(design.blocks, loc.container, loc.index + 1, copy))
    setSelected(copy.id)
  }

  // ---------- перетаскивание ----------

  const zoneKey = (c: Container, i: number) => `${c.parent ?? 'root'}:${c.col}:${i}`
  const onZoneDrop = (c: Container, i: number) => {
    if (!drag) return
    if (drag.kind === 'new') addBlock(drag.type, { container: c, index: i })
    else setBlocks(moveBlockTo(design.blocks, drag.id, c, i))
    setDrag(null)
    setDropAt(null)
  }
  const canDropIn = (c: Container) => {
    if (!drag) return false
    if (c.parent === null) return true
    if (drag.kind === 'new') return drag.type !== 'columns'
    const b = findBlock(design.blocks, drag.id)
    return !!b && b.type !== 'columns'
  }

  // ---------- сохранение ----------

  const html = useMemo(() => designToHtml(design, template?.subject ?? ''), [design, template?.subject])

  const save = useMutation({
    mutationFn: () => {
      if (!template) throw new Error('Шаблон не загружен')
      return api<{ data: Template }>(`/templates/${id}`, {
        method: 'PUT',
        body: {
          slug: template.slug, name: template.name, subject: template.subject, folder_id: template.folder_id,
          body_html: html, body_text: htmlToText(html) || null, editor: 'blocks', design,
        },
      })
    },
    onSuccess: (r) => {
      setSavedJson(JSON.stringify(design))
      qc.setQueryData(['template', id], r)
      qc.invalidateQueries({ queryKey: ['templates'] })
      toast('Письмо сохранено')
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : (e as Error).message, true),
  })
  const sendTest = useMutation({
    mutationFn: async () => {
      if (dirty || template?.editor !== 'blocks') await save.mutateAsync()
      const data: Record<string, string> = {}
      for (const v of vq.data?.base ?? []) data[v.key] = v.sample
      for (const v of vq.data?.custom ?? []) if (v.default_value) data[v.key] = v.default_value
      return api<{ data: Message }>(`/templates/${id}/test`, { method: 'POST', body: { to: testTo, data } })
    },
    onSuccess: (r) => {
      setTestOpen(false)
      toast(r.data.status === 'blocked' ? `Не отправлено: ${r.data.error}` : `Тест поставлен в очередь на ${r.data.to}`, r.data.status === 'blocked')
    },
    onError: (e) => toast(e instanceof ApiError ? (e.errors.to?.[0] ?? e.message) : 'Не удалось отправить', true),
  })

  // ---------- клавиши ----------

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.metaKey || e.ctrlKey
      if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); if (ready && !save.isPending) save.mutate(); return }
      if (isTyping()) return
      if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); dispatch({ type: e.shiftKey ? 'redo' : 'undo' }); return }
      if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); dispatch({ type: 'redo' }); return }
      if ((e.key === 'Delete' || e.key === 'Backspace') && selected) { e.preventDefault(); removeSelected() }
      if (e.key === 'Escape') setSelected(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [ready, save, selected, removeSelected])

  useEffect(() => {
    if (!dirty) return
    const onLeave = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', onLeave)
    return () => window.removeEventListener('beforeunload', onLeave)
  }, [dirty])

  const onEditorReady = useCallback((e: Editor | null) => setEditor(e), [])

  if (q.isError) return <div className="be-center"><div className="callout err"><div><b>Шаблон не найден</b><span><Link to="/templates">К списку шаблонов</Link></span></div></div></div>
  if (!template) return <div className="be-center muted">Загрузка…</div>

  // ---------- выбор, с чего начать (шаблон пока в коде) ----------

  if (!ready) {
    return (
      <div className="be-start">
        <Link to={`/templates/${id}`} className="back"><ArrowLeft size={15} />{template.name}</Link>
        <h1>Собрать письмо из блоков</h1>
        <p className="muted">Сейчас письмо «{template.name}» написано кодом. Выберите, с чего начать. Пока вы не сохраните, письмо в шаблоне не изменится.</p>
        <div className="be-start-grid">
          <button type="button" onClick={() => start('starter')}><LayoutTemplate size={22} /><b>Заготовка</b><span>Заголовок, текст, кнопка и подвал. Останется заменить слова.</span></button>
          <button type="button" onClick={() => start('wrap')}><Code2 size={22} /><b>Текущее письмо одним блоком</b><span>Код письма станет блоком «Свой HTML». Вокруг можно добавлять новые блоки.</span></button>
          <button type="button" onClick={() => start('empty')}><Type size={22} /><b>Пустое письмо</b><span>Начать с чистого листа.</span></button>
        </div>
      </div>
    )
  }

  const s = design.settings
  const verified = dq.data?.data.find((d) => d.status === 'verified')
  const total = countBlocks(design.blocks)
  const position = (() => {
    if (!selected) return ''
    const loc = locate(design.blocks, selected)
    if (!loc) return ''
    return loc.container.parent === null ? `Блок ${loc.index + 1} из ${design.blocks.length}` : `В колонке ${loc.container.col + 1}`
  })()
  const textBlock = selectedBlock && (selectedBlock.type === 'text' || selectedBlock.type === 'heading' || selectedBlock.type === 'footer') ? selectedBlock : null

  // ---------- отрисовка блоков на холсте ----------

  const Zone = ({ c, i, last }: { c: Container; i: number; last?: boolean }) => {
    const k = zoneKey(c, i)
    if (!drag || !canDropIn(c)) return last ? null : <div className="be-zone idle" />
    const label = drag.kind === 'new' ? `Отпустите, чтобы добавить: ${BLOCK_LABELS[drag.type].toLowerCase()}` : 'Отпустите, чтобы переставить'
    return (
      <div className={`be-zone${dropAt === k ? ' on' : ''}${last ? ' last' : ''}`}
        onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = drag.kind === 'new' ? 'copy' : 'move'; if (dropAt !== k) setDropAt(k) }}
        onDragLeave={() => setDropAt((d) => (d === k ? null : d))}
        onDrop={(e) => { e.preventDefault(); onZoneDrop(c, i) }}>
        {dropAt === k && <span>{label}</span>}
      </div>
    )
  }

  const renderList = (blocks: Block[], c: Container, width: number): ReactNode => (
    <>
      {blocks.map((b, i) => (
        <div key={b.id}>
          <Zone c={c} i={i} />
          {renderBlock(b, width, c.parent === null)}
        </div>
      ))}
      <Zone c={c} i={blocks.length} last />
    </>
  )

  const renderBlock = (b: Block, width: number, top = true): ReactNode => {
    const isSel = selected === b.id
    const hiddenHere = (device === 'mobile' && b.show === 'desktop') || (device === 'desktop' && b.show === 'mobile')
    // на телефоне боковые отступы письма 20 px, как в собранном HTML
    const side = (v: number) => (device === 'mobile' && top ? 20 : v)
    const pad = `${b.padding.t}px ${side(b.padding.r)}px ${b.padding.b}px ${side(b.padding.l)}px`
    const inner = (() => {
      switch (b.type) {
        case 'heading':
        case 'text':
        case 'footer': {
          const isHeading = b.type === 'heading'
          const isFooter = b.type === 'footer'
          const size = isFooter ? 12 : (b.size ?? (isHeading ? s.headingSize : s.fontSize))
          const style: React.CSSProperties = {
            fontFamily: isHeading && s.headingFont !== 'inherit' ? s.headingFont : s.font,
            fontSize: size, lineHeight: isHeading ? 1.25 : isFooter ? 1.55 : s.lineHeight,
            fontWeight: isHeading ? s.headingWeight : 400,
            color: b.type === 'footer' ? '#636976' : (b.color || s.textColor),
            textAlign: b.align, ['--link' as string]: s.linkColor, ['--pm' as string]: `${isHeading || isFooter ? 6 : Math.round(size * 0.75)}px`,
          }
          return isSel
            ? <RichText key={b.id} html={b.html} className="be-rt" style={style} onReady={onEditorReady} onChange={(v) => patchBlock(b.id, { html: v } as Partial<Block>, `${b.id}:html`)} />
            : <div className="be-rt" style={style} dangerouslySetInnerHTML={{ __html: highlightVars(b.html) }} />
        }
        case 'image':
          return b.src
            ? <div style={{ textAlign: b.align }}><img src={b.src} alt={b.alt} style={{ display: 'inline-block', width: b.full ? '100%' : Math.min(b.width, width), maxWidth: '100%', borderRadius: b.radius, verticalAlign: 'top' }} /></div>
            : (
              <div className="be-img-empty">
                <ImageIcon size={22} />
                <span>Картинка ещё не выбрана</span>
                <UploadButton className="btn sm primary" onError={(m) => toast(m, true)} onUploaded={(r) => patchBlock(b.id, { src: r.url, name: r.name, meta: r.meta, alt: r.name.replace(/\.[a-z0-9]+$/i, '') } as Partial<Block>)}>Загрузить</UploadButton>
              </div>
            )
        case 'button': {
          const radius = b.radius ?? s.buttonRadius
          return (
            <div style={{ textAlign: b.full ? 'center' : b.align }}>
              <span style={{ display: b.full ? 'block' : 'inline-block', padding: '14px 28px', borderRadius: radius, background: b.fill ?? s.buttonBg, color: b.color, fontFamily: s.font, fontWeight: 600, fontSize: 15, lineHeight: 1.2, textAlign: 'center' }}>{b.text || 'Кнопка'}</span>
            </div>
          )
        }
        case 'divider': return <div style={{ height: b.thickness, background: b.color }} />
        case 'spacer': return <div className="be-spacer" style={{ height: b.height }}><span>{b.height} px</span></div>
        case 'social':
          return (
            <div style={{ textAlign: b.align }}>
              {b.links.map((l, i) => <span key={i} className="be-social">{l.label}</span>)}
            </div>
          )
        case 'html': return <div className="be-html" dangerouslySetInnerHTML={{ __html: b.html }} />
        case 'columns': {
          const [l, r] = b.ratio === '33-67' ? [1, 2] : b.ratio === '67-33' ? [2, 1] : [1, 1]
          const inner = width - b.padding.l - b.padding.r - b.gap
          return (
            <div className={`be-cols${device === 'mobile' ? ' stack' : ''}`} style={{ gap: b.gap }}>
              {([0, 1] as const).map((k) => (
                <div key={k} className="be-col" style={{ flex: k === 0 ? l : r }}>
                  {b.columns[k].length === 0 && !drag && <div className="be-col-empty">Перетащите блок сюда</div>}
                  {renderList(b.columns[k], { parent: b.id, col: k }, Math.round(inner * (k === 0 ? l : r) / (l + r)))}
                </div>
              ))}
            </div>
          )
        }
      }
    })()

    return (
      <div
        className={`be-blk${isSel ? ' sel' : ''}${hiddenHere ? ' hidden-here' : ''}${drag?.kind === 'move' && drag.id === b.id ? ' dragging' : ''}`}
        style={{ padding: pad, background: b.bg || undefined }}
        onClick={(e) => { e.stopPropagation(); if (!isSel) setSelected(b.id) }}
      >
        {isSel && (
          <>
            <div className="be-tag">{ICON[b.type]}{BLOCK_LABELS[b.type]}</div>
            <div className="be-acts" onClick={(e) => e.stopPropagation()}>
              <button type="button" title="Выше" aria-label="Переместить выше" onClick={() => setBlocks(shiftBlock(design.blocks, b.id, -1))}><ArrowUp size={15} /></button>
              <button type="button" title="Ниже" aria-label="Переместить ниже" onClick={() => setBlocks(shiftBlock(design.blocks, b.id, 1))}><ArrowDown size={15} /></button>
              <button type="button" title="Копировать" aria-label="Копировать блок" onClick={() => duplicate(b.id)}><Copy size={14} /></button>
              <button type="button" title="Удалить" aria-label="Удалить блок" className="del" onClick={removeSelected}><Trash2 size={14} /></button>
            </div>
          </>
        )}
        <span className="be-grip" draggable title="Перетащите, чтобы переставить"
          onDragStart={(e: DragEvent) => { e.stopPropagation(); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', b.id); setDrag({ kind: 'move', id: b.id }) }}
          onDragEnd={() => { setDrag(null); setDropAt(null) }}><GripVertical size={14} /></span>
        {hiddenHere && <span className="be-hidden-note">{b.show === 'desktop' ? 'Только на компьютере' : 'Только на телефоне'}</span>}
        {inner}
      </div>
    )
  }

  const mailWidth = device === 'mobile' ? 375 : s.width

  return (
    <div className="be">
      {/* верхняя панель */}
      <header className="be-top">
        <Link to={`/templates/${id}`} className="btn ghost be-back"><ArrowLeft size={16} />Шаблон</Link>
        <span className="be-sep" />
        <div className="be-title">
          <b>{template.name}</b>
          <span><span className="mono">{template.slug}</span> · {dirty ? <span className="be-dirty"><i />Не сохранено</span> : savedJson ? <span className="be-saved"><Check size={12} strokeWidth={2.6} />Сохранено</span> : <span className="be-dirty"><i />Ещё не сохранено</span>}</span>
        </div>
        <span style={{ flex: 1 }} />
        <button type="button" className="btn icon sm ghost" title="Отменить (Cmd+Z)" aria-label="Отменить" disabled={!h.past.length} onClick={() => dispatch({ type: 'undo' })}><Undo2 size={17} /></button>
        <button type="button" className="btn icon sm ghost" title="Повторить (Shift+Cmd+Z)" aria-label="Повторить" disabled={!h.future.length} onClick={() => dispatch({ type: 'redo' })}><Redo2 size={17} /></button>
        <span className="be-sep" />
        <div className="seg" role="radiogroup" aria-label="Устройство">
          <button type="button" role="radio" aria-checked={device === 'desktop'} className={device === 'desktop' ? 'on' : ''} title="Компьютер" onClick={() => setDevice('desktop')}><Monitor size={15} /></button>
          <button type="button" role="radio" aria-checked={device === 'mobile'} className={device === 'mobile' ? 'on' : ''} title="Телефон" onClick={() => setDevice('mobile')}><Smartphone size={15} /></button>
        </div>
        <span className="be-sep" />
        <button type="button" className="btn ghost" onClick={() => setHtmlOpen(true)}><Code2 size={15} />HTML</button>
        <button type="button" className="btn" onClick={() => { setTestTo(user?.email ?? ''); setTestOpen(true) }}><Send size={15} />Отправить тест</button>
        <button type="button" className="btn primary" disabled={save.isPending || (!dirty && !!savedJson)} onClick={() => save.mutate()} title="Cmd+S">{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
      </header>

      <div className="be-body">
        {/* левая панель */}
        <aside className="be-left">
          <div className="seg be-tabs" role="tablist">
            <button type="button" role="tab" aria-selected={tab === 'blocks'} className={tab === 'blocks' ? 'on' : ''} onClick={() => setTab('blocks')}>Блоки</button>
            <button type="button" role="tab" aria-selected={tab === 'styles'} className={tab === 'styles' ? 'on' : ''} onClick={() => setTab('styles')}>Стили письма</button>
          </div>
          {tab === 'blocks' ? (
            <>
              <div className="be-tiles">
                {TILES.map((t) => (
                  <button type="button" key={t.type} className={`be-tile${drag?.kind === 'new' && drag.type === t.type ? ' dragging' : ''}`} draggable
                    onDragStart={(e) => { e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', t.type); setDrag({ kind: 'new', type: t.type }) }}
                    onDragEnd={() => { setDrag(null); setDropAt(null) }}
                    onClick={() => addBlock(t.type)}>
                    {t.icon}{BLOCK_LABELS[t.type]}
                  </button>
                ))}
              </div>
              <div className="hint">Перетащите блок в письмо или нажмите, чтобы добавить его под выбранным.</div>
              <div className="be-ov" style={{ marginTop: 6 }}>Переменные</div>
              <div className="be-vars">
                {variables.map((v) => (
                  <button type="button" key={v.key} className="chip mono" title={v.label} disabled={!editor}
                    onMouseDown={(e) => e.preventDefault()} onClick={() => editor?.chain().focus().insertContent(`{{ ${v.key} }}`).run()}>{v.key}</button>
                ))}
              </div>
              <div className="hint">{editor ? 'Нажмите, чтобы вставить туда, где курсор.' : 'Выберите текстовый блок, чтобы вставить переменную.'}</div>
            </>
          ) : (
            <StylesPanel settings={s} onChange={patchSettings} />
          )}
        </aside>

        {/* холст */}
        <main className="be-canvas" style={{ background: s.outerBg }} onClick={() => setSelected(null)}>
          {textBlock && editor && (
            <div className="be-fmt-wrap" onClick={(e) => e.stopPropagation()}>
              <FormatToolbar editor={editor} variables={variables}
                align={textBlock.align} onAlign={(a) => patchBlock(textBlock.id, { align: a } as Partial<Block>)} />
            </div>
          )}
          <div className={`be-mail${device === 'mobile' ? ' mobile' : ''}`} style={{ width: mailWidth, background: s.bodyBg, borderRadius: s.radius, fontFamily: s.font, color: s.textColor }}>
            {design.blocks.length === 0 && !drag ? (
              <div className="be-empty">
                <LayoutTemplate size={26} />
                <b>Письмо пустое</b>
                <span>Перетащите сюда блок слева или нажмите на него.</span>
              </div>
            ) : renderList(design.blocks, ROOT, mailWidth)}
          </div>
          <div className="be-canvas-note">{total} {total % 10 === 1 && total % 100 !== 11 ? 'блок' : total % 10 >= 2 && total % 10 <= 4 && (total % 100 < 10 || total % 100 >= 20) ? 'блока' : 'блоков'} · {device === 'mobile' ? 'телефон, 375 px' : `ширина ${s.width} px`}</div>
        </main>

        {/* правая панель */}
        <aside className="be-right" key={selected ?? 'none'}>
          {selectedBlock ? (
            <BlockInspector block={selectedBlock} position={position} settings={s}
              varHint="Можно вставить переменную, например {{ event_url }}: ссылка станет своей для каждого получателя."
              onChange={(p) => patchBlock(selectedBlock.id, p)} onRemove={removeSelected} onError={(m) => toast(m, true)} />
          ) : (
            <div className="be-inspector">
              <div className="be-rh"><b>Структура письма</b></div>
              <div className="hint">Выберите блок в письме или здесь, чтобы настроить его. Delete удаляет выбранный блок, Esc снимает выбор.</div>
              <div className="be-tree">
                {design.blocks.length === 0 && <div className="hint">Блоков пока нет.</div>}
                {design.blocks.map((b) => (
                  <div key={b.id}>
                    <button type="button" className="be-tree-item" onClick={() => setSelected(b.id)}>{ICON[b.type]}<span>{BLOCK_LABELS[b.type]}</span>{b.show !== 'all' && <span className="sub">{b.show === 'desktop' ? 'ПК' : 'тел.'}</span>}</button>
                    {b.type === 'columns' && b.columns.map((col, k) => col.map((x) => (
                      <button type="button" key={x.id} className="be-tree-item nested" onClick={() => setSelected(x.id)}>{ICON[x.type]}<span>{BLOCK_LABELS[x.type]}</span><span className="sub">кол. {k + 1}</span></button>
                    )))}
                  </div>
                ))}
              </div>
              <div className="be-grp">
                <div className="be-ov">Входящие</div>
                <div className="field">
                  <label className="be-lab" htmlFor="preheader">Прехедер</label>
                  <input id="preheader" className="input sm" value={s.preheader} onChange={(e) => patchSettings({ preheader: e.target.value })} placeholder="Строка после темы в списке писем" />
                  <div className="hint">Если пусто, почта возьмёт начало текста письма.</div>
                </div>
              </div>
            </div>
          )}
        </aside>
      </div>

      {htmlOpen && (
        <Modal title="HTML письма" onClose={() => setHtmlOpen(false)}>
          <p className="muted" style={{ margin: 0 }}>Этот код уходит получателю. Он собирается из блоков автоматически, менять его здесь не нужно.</p>
          <div className="code-block" style={{ maxHeight: 360, overflow: 'auto' }}><pre className="mono">{html}</pre><CopyButton text={html} label="Скопировать HTML" /></div>
          <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn" onClick={() => setHtmlOpen(false)}>Закрыть</button></div>
        </Modal>
      )}

      {testOpen && (
        <Modal title="Тестовое письмо" onClose={() => setTestOpen(false)}>
          <form className="stack" onSubmit={(e) => { e.preventDefault(); sendTest.mutate() }}>
            <div className="field">
              <label htmlFor="test-to">Кому</label>
              <input id="test-to" type="email" autoFocus className="input mono" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
              <div className="hint">{verified ? `Отправим с noreply@${verified.domain}. ` : ''}Переменные подставятся примерами из раздела «Переменные». Письмо появится в журнале.</div>
            </div>
            {(dirty || !savedJson) && <div className="callout warn"><div><b>Сначала сохраним письмо</b><span>Тест уходит из сохранённой версии шаблона.</span></div></div>}
            {!verified && <div className="callout err"><div><b>Нет подтверждённого домена</b><span>Подтвердите домен в разделе «Домены».</span></div></div>}
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setTestOpen(false)}>Отмена</button>
              <button className="btn primary" disabled={!testTo.trim() || sendTest.isPending || !verified}><Send size={15} />{dirty || !savedJson ? 'Сохранить и отправить' : 'Отправить'}</button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}

