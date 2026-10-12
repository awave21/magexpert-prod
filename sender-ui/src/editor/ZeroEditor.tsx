// Редактор слоёв зеро-блока: открывается поверх блочного редактора. Слои двигаются, меняют размер
// и поворот прямо на холсте; правки применяются к письму одним шагом по кнопке «Готово».
import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useReducer, useRef, useState, type CSSProperties, type ReactNode, type Ref, type PointerEvent as RPointerEvent } from 'react'
import {
  AlignCenter, AlignLeft, AlignRight, ArrowDown, ArrowUp, Circle, Copy, Eye, EyeOff, ImagePlus, Lock, LockOpen, Mail, Pause, Play,
  Redo2, Sparkles, Square, Trash2, Type, Undo2, X,
} from 'lucide-react'
import { Modal } from '../components/ui'
import { Group, Num, Row, Seg, Slider, uploadImage } from './Inspector'
import type { ZeroBlock } from './model'
import {
  ART, ZERO_ANIMS, ZERO_BLENDS, ZERO_FONTS, ZERO_W, artSvg, canvasBg, canvasOf, createLayer, ensureZeroAssets, layerName, layerStyles, uidZ, zeroAlt,
  type ZeroAlign, type ZeroAnim, type ZeroBlend, type ZeroCanvas, type ZeroLayer,
} from './zero'
import { renderZeroFile } from './zero-render'

// ---------- живое отображение слоёв ----------

const camel = (k: string) => k.replace(/-([a-z])/g, (_, c: string) => c.toUpperCase())
const toStyle = (o: Record<string, string>) => Object.fromEntries(Object.entries(o).map(([k, v]) => [camel(k), v])) as CSSProperties

const LayerView = memo(function LayerView({ l, z }: { l: ZeroLayer; z: number }) {
  const s = layerStyles(l, z)
  // разметку графики пересобираем только при смене рисунка или цвета, иначе анимация сбивается
  const key = l.kind === 'art' ? [l.art, l.c1, l.c2, l.c3].join('|') : l.kind === 'text' ? l.text : l.kind === 'image' ? l.src + l.fit : ''
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const html = useMemo(() => s.html, [key])
  return (
    <div className={`zl${l.kind === 'text' ? ' t' : ''}${l.locked ? ' lk' : ''}`} data-id={l.id} style={toStyle(s.outer)}>
      <div className={s.cls} style={toStyle(s.inner)} dangerouslySetInnerHTML={{ __html: html }} />
    </div>
  )
})

export function ZeroFrame({ z, width, still, frameRef, children }: { z: ZeroCanvas; width: number; still?: boolean; frameRef?: Ref<HTMLDivElement>; children?: ReactNode }) {
  useEffect(ensureZeroAssets, [])
  const k = width / ZERO_W
  return (
    <div className="ze-box" style={{ width, height: Math.round(z.height * k) }}>
      <div ref={frameRef} className={`zf${still ? ' z-still' : ''}`}
        style={{ position: 'absolute', left: 0, top: 0, height: z.height, background: canvasBg(z), transform: `scale(${k})`, transformOrigin: '0 0', ['--k' as string]: k }}>
        {z.layers.map((l, i) => <LayerView key={l.id} l={l} z={i + 1} />)}
        {children}
      </div>
    </div>
  )
}

// ---------- история ----------

type Doc = Pick<ZeroBlock, 'height' | 'canvasBg' | 'canvasBg2' | 'canvasAngle' | 'layers'>
type Hist = { past: Doc[]; present: Doc; future: Doc[]; key: string | null; at: number }
type HistAction = { type: 'set'; doc: Doc; key?: string } | { type: 'undo' } | { type: 'redo' }
function histReducer(h: Hist, a: HistAction): Hist {
  switch (a.type) {
    case 'set': {
      const now = Date.now()
      if (a.key && a.key === h.key && now - h.at < 1000) return { ...h, present: a.doc, at: now }
      return { past: [...h.past.slice(-99), h.present], present: a.doc, future: [], key: a.key ?? null, at: now }
    }
    case 'undo': return h.past.length ? { past: h.past.slice(0, -1), present: h.past[h.past.length - 1], future: [h.present, ...h.future], key: null, at: 0 } : h
    case 'redo': return h.future.length ? { past: [...h.past, h.present], present: h.future[0], future: h.future.slice(1), key: null, at: 0 } : h
  }
}

// ---------- поля ----------

const isColor = (v: string) => typeof CSS !== 'undefined' && CSS.supports('color', v)
function ZColor({ value, onChange, label, allowEmpty }: { value: string; onChange: (v: string) => void; label: string; allowEmpty?: boolean }) {
  const [draft, setDraft] = useState<string | null>(null)
  const hex = /^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'
  return (
    <span className="be-color ze-color">
      <label className="be-swatch" style={{ background: value || undefined }} data-empty={!value || undefined} title={label}>
        <input type="color" className="sr" aria-label={label} value={hex} onChange={(e) => onChange(e.target.value.toUpperCase())} />
      </label>
      <input className="mono" aria-label={`${label}, значение`} value={draft ?? (value || 'Нет')}
        onFocus={() => setDraft(value)} onChange={(e) => setDraft(e.target.value)}
        onBlur={() => {
          const v = (draft ?? '').trim()
          if (v && isColor(v)) onChange(v)
          else if (!v && allowEmpty) onChange('')
          setDraft(null)
        }}
        onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()} />
      {allowEmpty && value && <button type="button" className="be-x" onClick={() => onChange('')} aria-label="Убрать цвет"><X size={12} /></button>}
    </span>
  )
}

const Field = ({ label, children }: { label: string; children: ReactNode }) => <label className="ze-field"><span>{label}</span>{children}</label>
const N = ({ value, onChange, label, min = -2000, max = 2000, step = 1 }: { value: number; onChange: (v: number) => void; label: string; min?: number; max?: number; step?: number }) => (
  <input className="input sm ze-num" type="number" aria-label={label} value={Number.isFinite(value) ? value : 0} min={min} max={max} step={step}
    onChange={(e) => { const v = Number(e.target.value); if (!Number.isNaN(v)) onChange(Math.max(min, Math.min(max, v))) }} />
)

const KIND_ICON: Record<ZeroLayer['kind'], ReactNode> = { text: <Type size={15} />, rect: <Square size={15} />, art: <Sparkles size={15} />, image: <ImagePlus size={15} /> }

// ---------- редактор ----------

type Props = { block: ZeroBlock; onApply: (patch: Doc) => void; onClose: () => void; onError: (m: string) => void }
type Guide = { x?: number; y?: number }

const isTyping = () => {
  const el = document.activeElement as HTMLElement | null
  return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))
}

export function ZeroEditor({ block, onApply, onClose, onError }: Props) {
  const initial: Doc = useMemo(() => ({ height: block.height, canvasBg: block.canvasBg, canvasBg2: block.canvasBg2, canvasAngle: block.canvasAngle, layers: block.layers }), [block])
  const [h, dispatch] = useReducer(histReducer, { past: [], present: initial, future: [], key: null, at: 0 })
  const doc = h.present
  const [sel, setSel] = useState<string | null>(null)
  const [motion, setMotion] = useState(true)
  const [guides, setGuides] = useState<Guide[]>([])
  const [artOpen, setArtOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [shot, setShot] = useState<{ url: string; info: string } | null>(null)
  const [k, setK] = useState(1)
  const [selH, setSelH] = useState(0)
  const stage = useRef<HTMLDivElement>(null)
  const frame = useRef<HTMLDivElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const layer = doc.layers.find((l) => l.id === sel) ?? null
  const changed = doc !== initial
  const set = useCallback((patch: Partial<Doc>, key?: string) => dispatch({ type: 'set', doc: { ...doc, ...patch }, key }), [doc])
  const patchLayer = useCallback((id: string, patch: Partial<ZeroLayer>, key?: string) =>
    set({ layers: doc.layers.map((l) => (l.id === id ? ({ ...l, ...patch } as ZeroLayer) : l)) }, key ?? `${id}:${Object.keys(patch).join(',')}`), [doc.layers, set])

  // масштаб холста под ширину рабочей области
  useEffect(() => {
    const el = stage.current
    if (!el) return
    const ro = new ResizeObserver(() => setK(Math.max(0.3, Math.min(1, (el.clientWidth - 48) / ZERO_W))))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  // высота текстового слоя зависит от переносов: берём её из разметки
  useLayoutEffect(() => {
    if (!layer) return
    const el = frame.current?.querySelector<HTMLElement>(`[data-id="${layer.id}"]`)
    setSelH(layer.kind === 'text' && el ? el.offsetHeight : layer.h)
  }, [layer, doc])

  // ---------- слои ----------

  const add = (l: ZeroLayer) => { set({ layers: [...doc.layers, l] }); setSel(l.id) }
  const center = (w: number, hh: number) => ({ x: Math.round(ZERO_W / 2 - w / 2), y: Math.round(doc.height / 2 - hh / 2) })
  const dark = (() => { const m = /#([0-9a-f]{6})/i.exec(doc.canvasBg); if (!m) return true; const n = parseInt(m[1], 16); return (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255 < 0.55 })()
  const addText = () => add(createLayer('text', { ...center(300, 40), w: 300, text: 'Новый текст', font: 'unb', size: 32, weight: 800, color: dark ? '#FFFFFF' : '#111111' }))
  const addRect = () => add(createLayer('rect', { ...center(200, 120), w: 200, h: 120, fill: '#FF7A1A', radius: 16 }))
  const addCircle = () => add(createLayer('rect', { ...center(160, 160), w: 160, h: 160, fill: '#3D5AFE', radius: 999, name: 'Круг' }))
  const addArt = (key: string) => {
    const a = ART[key], wide = a.par === 'none' || a.par.includes('slice')
    const w = wide ? ZERO_W : 160, hh = Math.round(Math.min(doc.height, (w * a.vb[1]) / a.vb[0]))
    add(createLayer('art', { ...center(w, hh), w, h: hh, art: key, name: a.name, c1: '#FF7A1A', c2: '#3D5AFE' }))
    setArtOpen(false)
  }
  const addPhoto = async (file: File) => {
    setBusy(true)
    try {
      const r = await uploadImage(file)
      const img = new Image()
      await new Promise((res) => { img.onload = res; img.onerror = res; img.src = r.url })
      const w = Math.min(320, img.naturalWidth || 320), hh = Math.round(w * ((img.naturalHeight || 240) / (img.naturalWidth || 320)))
      add(createLayer('image', { ...center(w, hh), w, h: hh, src: r.url, name: r.name.replace(/\.[a-z0-9]+$/i, '').slice(0, 28) }))
    } catch (e) { onError((e as Error).message) } finally { setBusy(false) }
  }
  const move = (dir: 1 | -1) => {
    const i = doc.layers.findIndex((l) => l.id === sel), j = i + dir
    if (i < 0 || j < 0 || j >= doc.layers.length) return
    const next = [...doc.layers];[next[i], next[j]] = [next[j], next[i]]
    set({ layers: next })
  }
  const duplicate = () => {
    if (!layer) return
    const copy = { ...layer, id: uidZ(), x: layer.x + 16, y: layer.y + 16, name: layer.name ? `${layer.name} (копия)` : '' } as ZeroLayer
    const i = doc.layers.findIndex((l) => l.id === layer.id)
    set({ layers: [...doc.layers.slice(0, i + 1), copy, ...doc.layers.slice(i + 1)] })
    setSel(copy.id)
  }
  const remove = () => { if (!layer) return; set({ layers: doc.layers.filter((l) => l.id !== layer.id) }); setSel(null) }

  // ---------- мышь на холсте ----------

  const pick = (x: number, y: number): ZeroLayer | null => {
    for (const el of document.elementsFromPoint(x, y)) {
      const z = (el as HTMLElement).closest?.('.zl') as HTMLElement | null
      if (z && frame.current?.contains(z)) {
        const l = doc.layers.find((q) => q.id === z.dataset.id)
        if (l && !l.locked && !l.hidden) return l
      }
    }
    return null
  }
  const track = (onMove: (e: PointerEvent) => void, onUp: () => void) => {
    const up = () => { window.removeEventListener('pointermove', onMove); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); onUp() }
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
  }
  const onStageDown = (e: RPointerEvent) => {
    if (e.button !== 0) return
    setArtOpen(false)
    const handle = (e.target as HTMLElement).closest<HTMLElement>('[data-h]')
    if (handle && layer) { e.preventDefault(); startHandle(e, handle.dataset.h!, layer); return }
    const l = pick(e.clientX, e.clientY)
    if (!l) { setSel(null); return }
    e.preventDefault()
    setSel(l.id)
    startDrag(e, l)
  }
  const startDrag = (e: RPointerEvent, l: ZeroLayer) => {
    const sx = e.clientX, sy = e.clientY, ox = l.x, oy = l.y, key = `drag:${l.id}:${Date.now()}`
    const lh = l.kind === 'text' ? (frame.current?.querySelector<HTMLElement>(`[data-id="${l.id}"]`)?.offsetHeight ?? l.h) : l.h
    let moved = false
    let latest = doc
    track((ev) => {
      const dx = (ev.clientX - sx) / k, dy = (ev.clientY - sy) / k
      if (!moved && Math.hypot(dx, dy) < 2) return
      moved = true
      let nx = Math.round(ox + dx), ny = Math.round(oy + dy)
      const g: Guide[] = [], th = 6 / k
      if (!ev.altKey) {
        for (const [v, t] of [[nx, 0], [nx + l.w / 2, ZERO_W / 2], [nx + l.w, ZERO_W]]) if (Math.abs(v - t) < th) { nx += Math.round(t - v); g.push({ x: t }); break }
        for (const [v, t] of [[ny, 0], [ny + lh / 2, doc.height / 2], [ny + lh, doc.height]]) if (Math.abs(v - t) < th) { ny += Math.round(t - v); g.push({ y: t }); break }
      }
      latest = { ...latest, layers: latest.layers.map((q) => (q.id === l.id ? ({ ...q, x: nx, y: ny } as ZeroLayer) : q)) }
      dispatch({ type: 'set', doc: latest, key })
      setGuides(g)
    }, () => setGuides([]))
  }
  const startHandle = (e: RPointerEvent, dir: string, l: ZeroLayer) => {
    const sx = e.clientX, sy = e.clientY, o = { x: l.x, y: l.y, w: l.w, h: l.h }, key = `size:${l.id}:${Date.now()}`
    let latest = doc
    const put = (patch: Partial<ZeroLayer>) => {
      latest = { ...latest, layers: latest.layers.map((q) => (q.id === l.id ? ({ ...q, ...patch } as ZeroLayer) : q)) }
      dispatch({ type: 'set', doc: latest, key })
    }
    if (dir === 'rot') {
      const r = frame.current?.querySelector(`[data-id="${l.id}"]`)?.getBoundingClientRect()
      if (!r) return
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2
      track((ev) => {
        let a = Math.atan2(ev.clientY - cy, ev.clientX - cx) * 180 / Math.PI + 90
        if (a > 180) a -= 360
        put({ rot: ev.shiftKey ? Math.round(a / 15) * 15 : Math.round(a) })
      }, () => undefined)
      return
    }
    const rad = -l.rot * Math.PI / 180
    track((ev) => {
      const dx = (ev.clientX - sx) / k, dy = (ev.clientY - sy) / k
      const lx = dx * Math.cos(rad) - dy * Math.sin(rad), ly = dx * Math.sin(rad) + dy * Math.cos(rad)
      let { x, y, w, h: hh } = o
      if (dir.includes('e')) w = o.w + lx
      if (dir.includes('w')) { w = o.w - lx; x = o.x + lx }
      if (dir.includes('s')) hh = o.h + ly
      if (dir.includes('n')) { hh = o.h - ly; y = o.y + ly }
      if (ev.shiftKey && dir.length === 2) { hh = w * (o.h / o.w); if (dir.includes('n')) y = o.y + o.h - hh }
      put({ x: Math.round(x), y: Math.round(y), w: Math.round(Math.max(8, w)), h: Math.round(Math.max(8, hh)) })
    }, () => undefined)
  }

  // ---------- клавиши: перехватываем, чтобы не сработали клавиши блочного редактора под нами ----------

  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined)
  const onKey = (e: KeyboardEvent) => {
    e.stopImmediatePropagation()
    const mod = e.metaKey || e.ctrlKey
    if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); return }
    if (isTyping()) return
    if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); dispatch({ type: e.shiftKey ? 'redo' : 'undo' }); return }
    if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); dispatch({ type: 'redo' }); return }
    if (e.key === 'Escape') { if (artOpen) setArtOpen(false); else setSel(null); return }
    if (!layer) return
    if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicate(); return }
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(); return }
    if (e.key === ']') { move(1); return }
    if (e.key === '[') { move(-1); return }
    const s = e.shiftKey ? 10 : 1
    const d = ({ ArrowLeft: [-s, 0], ArrowRight: [s, 0], ArrowUp: [0, -s], ArrowDown: [0, s] } as Record<string, [number, number]>)[e.key]
    if (d && !layer.locked) { e.preventDefault(); patchLayer(layer.id, { x: layer.x + d[0], y: layer.y + d[1] }, `nudge:${layer.id}`) }
  }
  useLayoutEffect(() => { keys.current = onKey })
  useEffect(() => {
    const listener = (e: KeyboardEvent) => keys.current(e)
    window.addEventListener('keydown', listener, true)
    return () => window.removeEventListener('keydown', listener, true)
  }, [])

  // ---------- как увидит почта ----------

  const preview = async () => {
    setBusy(true)
    try {
      const file = await renderZeroFile(canvasOf(doc), 'preview')
      setShot((old) => { if (old) URL.revokeObjectURL(old.url); return { url: URL.createObjectURL(file), info: `${ZERO_W * 2} × ${doc.height * 2} px · ${file.type === 'image/png' ? 'PNG' : 'JPEG'} · ${Math.round(file.size / 1024)} КБ` } })
    } catch (e) { onError((e as Error).message) } finally { setBusy(false) }
  }
  useEffect(() => () => { if (shot) URL.revokeObjectURL(shot.url) }, [shot])

  const canvas = canvasOf(doc)
  const alt = zeroAlt(doc.layers)

  // ---------- настройки слоя ----------

  const L = layer
  const pl = (patch: Partial<ZeroLayer>) => L && patchLayer(L.id, patch)
  const inspector = !L ? (
    <div className="be-inspector">
      <div className="be-rh"><b>Холст</b><span className="chip">{ZERO_W} × {doc.height}</span></div>
      <Group title="Размер и фон">
        <Row label="Высота, px"><Num label="Высота холста" value={doc.height} min={80} max={1200} onChange={(v) => set({ height: v }, 'height')} /></Row>
        <Row label="Фон"><ZColor label="Фон холста" value={doc.canvasBg} onChange={(v) => set({ canvasBg: v })} /></Row>
        <Row label="Второй цвет"><ZColor label="Второй цвет фона" value={doc.canvasBg2} allowEmpty onChange={(v) => set({ canvasBg2: v })} /></Row>
        {doc.canvasBg2 && <Row label="Угол градиента"><Slider label="Угол градиента" value={doc.canvasAngle} min={0} max={360} onChange={(v) => set({ canvasAngle: v }, 'angle')} /></Row>}
      </Group>
      <Group title="Как уходит в письмо">
        <div className="hint">Почта не умеет накладывать элементы друг на друга, поэтому при сохранении письма слои собираются в одну картинку шириной 600 px в двойном разрешении. В этой картинке можно использовать любые шрифты.</div>
        <div className="hint"><b>Описание картинки:</b> {alt || 'добавьте текстовый слой'}</div>
      </Group>
      <div className="hint">Выберите слой на холсте или в списке. Фото можно перетащить прямо на холст. <span className="kbd">Shift</span> сохраняет пропорции и шаг поворота, <span className="kbd">Alt</span> отключает прилипание, <span className="kbd">[</span> <span className="kbd">]</span> меняют порядок.</div>
    </div>
  ) : (
    <div className="be-inspector">
      <div className="be-rh"><b>{layerName(L)}</b><button type="button" className="btn icon sm ghost" aria-label="Снять выбор" onClick={() => setSel(null)}><X size={15} /></button></div>
      {L.kind === 'text' && (
        <Group title="Текст">
          <textarea className="textarea" rows={3} aria-label="Текст слоя" value={L.text} onChange={(e) => patchLayer(L.id, { text: e.target.value }, `${L.id}:text`)} />
          <div className="ze-grid">
            <Field label="Шрифт">
              <select className="select be-select" value={L.font} onChange={(e) => pl({ font: e.target.value })}>
                {Object.entries(ZERO_FONTS).map(([key, f]) => <option key={key} value={key}>{f.label}</option>)}
              </select>
            </Field>
            <Field label="Начертание">
              <select className="select be-select" value={L.weight} onChange={(e) => pl({ weight: Number(e.target.value) })}>
                {[[400, 'Обычное'], [500, 'Среднее'], [600, 'Полужирное'], [700, 'Жирное'], [800, 'Чёрное']].map(([v, t]) => <option key={v} value={v}>{t}</option>)}
              </select>
            </Field>
            <Field label="Размер"><N label="Размер шрифта" value={L.size} min={6} max={400} onChange={(v) => pl({ size: v })} /></Field>
            <Field label="Интервал"><N label="Межстрочный интервал" value={L.lh} min={0.6} max={3} step={0.05} onChange={(v) => pl({ lh: v })} /></Field>
            <Field label="Разрядка"><N label="Разрядка" value={L.ls} min={-10} max={40} step={0.5} onChange={(v) => pl({ ls: v })} /></Field>
          </div>
          <Row label="Цвет"><ZColor label="Цвет текста" value={L.color} onChange={(v) => pl({ color: v })} /></Row>
          <Row label="Выравнивание">
            <Seg<ZeroAlign> label="Выравнивание" value={L.align} onChange={(v) => pl({ align: v })} options={[
              { value: 'left', label: <AlignLeft size={15} />, title: 'Слева' }, { value: 'center', label: <AlignCenter size={15} />, title: 'По центру' }, { value: 'right', label: <AlignRight size={15} />, title: 'Справа' },
            ]} />
          </Row>
          <div className="ze-checks">
            <label><input type="checkbox" checked={L.upper} onChange={(e) => pl({ upper: e.target.checked })} />Прописные</label>
            <label><input type="checkbox" checked={L.italic} onChange={(e) => pl({ italic: e.target.checked })} />Курсив</label>
            <label><input type="checkbox" checked={L.strike} onChange={(e) => pl({ strike: e.target.checked })} />Зачёркнутый</label>
          </div>
        </Group>
      )}
      {L.kind === 'rect' && (
        <Group title="Заливка">
          <Row label="Цвет"><ZColor label="Цвет заливки" value={L.fill} onChange={(v) => pl({ fill: v })} /></Row>
          <Row label="Второй цвет"><ZColor label="Второй цвет градиента" value={L.fill2} allowEmpty onChange={(v) => pl({ fill2: v })} /></Row>
          {L.fill2 && (
            <>
              <Row label="Градиент"><Seg label="Вид градиента" value={L.grad} onChange={(v) => pl({ grad: v })} options={[{ value: 'linear', label: 'Линейный' }, { value: 'radial', label: 'Круговой' }]} /></Row>
              {L.grad === 'linear' && <Row label="Угол"><Slider label="Угол градиента" value={L.angle} min={0} max={360} onChange={(v) => pl({ angle: v })} /></Row>}
            </>
          )}
          <Row label="Скругление"><Slider label="Скругление" value={Math.min(L.radius, 300)} min={0} max={300} onChange={(v) => pl({ radius: v })} /></Row>
          <button type="button" className="btn sm text" style={{ alignSelf: 'flex-start' }} onClick={() => pl({ radius: 999 })}><Circle size={14} />Сделать кругом</button>
          <Row label="Обводка"><ZColor label="Цвет обводки" value={L.stroke} allowEmpty onChange={(v) => pl({ stroke: v, strokeW: v && !L.strokeW ? 2 : L.strokeW })} /></Row>
          {L.stroke && (
            <>
              <Row label="Толщина"><Slider label="Толщина обводки" value={L.strokeW} min={0} max={16} onChange={(v) => pl({ strokeW: v })} /></Row>
              <Row label="Пунктир"><Seg label="Вид обводки" value={L.dashed ? 'dash' : 'solid'} onChange={(v) => pl({ dashed: v === 'dash' })} options={[{ value: 'solid', label: 'Сплошная' }, { value: 'dash', label: 'Пунктир' }]} /></Row>
            </>
          )}
          <Row label="Тень"><ZColor label="Цвет тени" value={L.shadow} allowEmpty onChange={(v) => pl({ shadow: v, shadowY: v && !L.shadowX && !L.shadowY ? 6 : L.shadowY, shadowX: v && !L.shadowX && !L.shadowY ? 6 : L.shadowX })} /></Row>
          {L.shadow && (
            <div className="ze-grid">
              <Field label="Сдвиг X"><N label="Сдвиг тени по X" value={L.shadowX} min={-60} max={60} onChange={(v) => pl({ shadowX: v })} /></Field>
              <Field label="Сдвиг Y"><N label="Сдвиг тени по Y" value={L.shadowY} min={-60} max={60} onChange={(v) => pl({ shadowY: v })} /></Field>
              <Field label="Размытие"><N label="Размытие тени" value={L.shadowBlur} min={0} max={80} onChange={(v) => pl({ shadowBlur: v })} /></Field>
            </div>
          )}
        </Group>
      )}
      {L.kind === 'art' && (
        <Group title="Графика">
          <Row label="Рисунок">
            <select className="select be-select" aria-label="Рисунок" value={L.art} onChange={(e) => pl({ art: e.target.value, name: '' })}>
              {Object.entries(ART).map(([key, a]) => <option key={key} value={key}>{a.name}</option>)}
            </select>
          </Row>
          <Row label="Цвет 1"><ZColor label="Основной цвет" value={L.c1} onChange={(v) => pl({ c1: v })} /></Row>
          <Row label="Цвет 2"><ZColor label="Второй цвет" value={L.c2} allowEmpty onChange={(v) => pl({ c2: v })} /></Row>
          <Row label="Фон"><ZColor label="Фон рисунка" value={L.c3} allowEmpty onChange={(v) => pl({ c3: v })} /></Row>
          <Row label="Скругление"><Slider label="Скругление" value={Math.min(L.radius, 300)} min={0} max={300} onChange={(v) => pl({ radius: v })} /></Row>
        </Group>
      )}
      {L.kind === 'image' && (
        <Group title="Фото">
          <Row label="Заполнение"><Seg label="Заполнение" value={L.fit} onChange={(v) => pl({ fit: v })} options={[{ value: 'cover', label: 'Обрезать' }, { value: 'contain', label: 'Вписать' }]} /></Row>
          <Row label="Скругление"><Slider label="Скругление" value={Math.min(L.radius, 300)} min={0} max={300} onChange={(v) => pl({ radius: v })} /></Row>
        </Group>
      )}
      <Group title="Положение">
        <div className="ze-grid">
          <Field label="X"><N label="X" value={L.x} onChange={(v) => pl({ x: v })} /></Field>
          <Field label="Y"><N label="Y" value={L.y} onChange={(v) => pl({ y: v })} /></Field>
          <Field label="Ширина"><N label="Ширина" value={L.w} min={8} max={3000} onChange={(v) => pl({ w: v })} /></Field>
          {L.kind !== 'text' && <Field label="Высота"><N label="Высота" value={L.h} min={8} max={3000} onChange={(v) => pl({ h: v })} /></Field>}
          <Field label="Поворот, °"><N label="Поворот" value={L.rot} min={-360} max={360} onChange={(v) => pl({ rot: v })} /></Field>
        </div>
        <Row label="Прозрачность"><Slider label="Прозрачность, %" value={Math.round(L.op * 100)} min={0} max={100} onChange={(v) => pl({ op: v / 100 })} /></Row>
        <Row label="Наложение">
          <select className="select be-select" aria-label="Режим наложения" value={L.blend} onChange={(e) => pl({ blend: e.target.value as ZeroBlend })}>
            {Object.entries(ZERO_BLENDS).map(([key, t]) => <option key={key} value={key}>{t}</option>)}
          </select>
        </Row>
      </Group>
      <Group title="Анимация">
        <Row label="Движение">
          <select className="select be-select" aria-label="Анимация" value={L.anim} onChange={(e) => pl({ anim: e.target.value as ZeroAnim })}>
            {Object.entries(ZERO_ANIMS).map(([key, a]) => <option key={key} value={key}>{a.label}</option>)}
          </select>
        </Row>
        {L.anim !== 'none' && <Row label="Сдвиг по времени, с"><N label="Сдвиг анимации" value={L.delay} min={0} max={6} step={0.1} onChange={(v) => pl({ delay: v })} /></Row>}
        <div className="hint">Анимация видна здесь, в письмо слой пока уходит неподвижным.</div>
      </Group>
      <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
        <button type="button" className="btn sm" onClick={duplicate}><Copy size={14} />Копия</button>
        <button type="button" className="btn sm text danger-text" onClick={remove}><Trash2 size={14} />Удалить слой</button>
      </div>
    </div>
  )

  const handles = L && !L.locked && !L.hidden ? (L.kind === 'text' ? ['e', 'w'] : ['nw', 'ne', 'sw', 'se']) : []

  return (
    <div className="ze" role="dialog" aria-modal="true" aria-label="Редактор зеро-блока">
      <header className="be-top ze-top">
        <div className="be-title"><b>Зеро-блок</b><span>Слои, наложение и анимация · ширина 600 px</span></div>
        <span className="be-sep" />
        <button type="button" className="btn ghost sm" onClick={addText}><Type size={15} />Текст</button>
        <button type="button" className="btn ghost sm" onClick={addRect}><Square size={15} />Фигура</button>
        <button type="button" className="btn ghost sm" onClick={addCircle}><Circle size={15} />Круг</button>
        <button type="button" className="btn ghost sm" disabled={busy} onClick={() => fileInput.current?.click()}><ImagePlus size={15} />Фото</button>
        <div className="be-pop-wrap">
          <button type="button" className="btn ghost sm" aria-expanded={artOpen} onClick={() => setArtOpen((v) => !v)}><Sparkles size={15} />Графика</button>
          {artOpen && (
            <div className="ze-art" role="menu">
              {Object.entries(ART).map(([key, a]) => (
                <button type="button" role="menuitem" key={key} onClick={() => addArt(key)}>
                  <span className="ze-art-pv" dangerouslySetInnerHTML={{ __html: artSvg(createLayer('art', { art: key, c1: '#FF9A4D', c2: '#7FD6FF' })) }} />
                  {a.name}
                </button>
              ))}
            </div>
          )}
        </div>
        <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void addPhoto(f) }} />
        <span className="be-sep" />
        <button type="button" className="btn icon sm ghost" title="Отменить (Cmd+Z)" aria-label="Отменить" disabled={!h.past.length} onClick={() => dispatch({ type: 'undo' })}><Undo2 size={17} /></button>
        <button type="button" className="btn icon sm ghost" title="Повторить (Shift+Cmd+Z)" aria-label="Повторить" disabled={!h.future.length} onClick={() => dispatch({ type: 'redo' })}><Redo2 size={17} /></button>
        <span style={{ flex: 1 }} />
        <button type="button" className="btn ghost sm" onClick={() => setMotion((v) => !v)}>{motion ? <Pause size={15} /> : <Play size={15} />}{motion ? 'Остановить' : 'Анимация'}</button>
        <button type="button" className="btn ghost sm" disabled={busy} onClick={() => void preview()}><Mail size={15} />{busy ? 'Собираем…' : 'Как увидит почта'}</button>
        <button type="button" className="btn sm" onClick={onClose}>Отмена</button>
        <button type="button" className="btn primary sm" onClick={() => { onApply(doc); onClose() }} disabled={!changed}>Готово</button>
      </header>

      <div className="ze-body">
        <aside className="ze-layers">
          <div className="be-ov ze-h">Слои <span>{doc.layers.length}</span></div>
          <div className="ze-list">
            {[...doc.layers].reverse().map((l) => (
              <div key={l.id} className={`ze-row${l.id === sel ? ' on' : ''}${l.hidden ? ' off' : ''}`} onClick={() => setSel(l.id)}>
                <span className="ze-ic">{l.kind === 'rect' && l.radius >= 999 ? <Circle size={15} /> : KIND_ICON[l.kind]}</span>
                <span className="ze-name">{layerName(l)}</span>
                <button type="button" className={l.hidden ? 'act' : ''} aria-label={l.hidden ? 'Показать слой' : 'Скрыть слой'} onClick={(e) => { e.stopPropagation(); patchLayer(l.id, { hidden: !l.hidden }) }}>{l.hidden ? <EyeOff size={14} /> : <Eye size={14} />}</button>
                <button type="button" className={l.locked ? 'act' : ''} aria-label={l.locked ? 'Разблокировать слой' : 'Заблокировать слой'} onClick={(e) => { e.stopPropagation(); patchLayer(l.id, { locked: !l.locked }) }}>{l.locked ? <Lock size={14} /> : <LockOpen size={14} />}</button>
              </div>
            ))}
            {!doc.layers.length && <div className="hint">Слоёв пока нет. Добавьте текст, фигуру, фото или графику кнопками сверху.</div>}
          </div>
          <div className="ze-tools">
            <button type="button" className="btn icon sm" aria-label="Выше" title="Выше (])" disabled={!L} onClick={() => move(1)}><ArrowUp size={15} /></button>
            <button type="button" className="btn icon sm" aria-label="Ниже" title="Ниже ([)" disabled={!L} onClick={() => move(-1)}><ArrowDown size={15} /></button>
            <button type="button" className="btn icon sm" aria-label="Копия" title="Копия (Cmd+D)" disabled={!L} onClick={duplicate}><Copy size={15} /></button>
            <button type="button" className="btn icon sm" aria-label="Удалить слой" title="Удалить (Delete)" disabled={!L} onClick={remove}><Trash2 size={15} /></button>
          </div>
        </aside>

        <main className="ze-stage" ref={stage} onPointerDown={onStageDown}
          onDragOver={(e) => { if ([...e.dataTransfer.items].some((i) => i.kind === 'file')) e.preventDefault() }}
          onDrop={(e) => { const f = [...e.dataTransfer.files].find((x) => x.type.startsWith('image/')); if (f) { e.preventDefault(); void addPhoto(f) } }}>
          <ZeroFrame z={canvas} width={Math.round(ZERO_W * k)} still={!motion} frameRef={frame}>
            {L && !L.hidden && (
              <div className="ze-sel" style={{ left: L.x, top: L.y, width: L.w, height: selH, transform: L.rot ? `rotate(${L.rot}deg)` : undefined }}>
                {handles.map((d) => <span key={d} className={`ze-hd ${d}`} data-h={d} />)}
                {!L.locked && <span className="ze-hd rot" data-h="rot" title="Повернуть" />}
              </div>
            )}
            {guides.map((g, i) => <div key={i} className="ze-guide" style={g.x !== undefined ? { left: g.x, top: 0, bottom: 0 } : { top: g.y, left: 0, right: 0 }} />)}
          </ZeroFrame>
        </main>

        <aside className="ze-insp" key={sel ?? 'canvas'}>{inspector}</aside>
      </div>

      {shot && (
        <Modal title="Так блок попадёт в письмо" wide onClose={() => setShot(null)}>
          <div className="ze-shot"><img src={shot.url} alt={alt} width={ZERO_W} /></div>
          <p className="muted" style={{ margin: 0 }}>{shot.info}. Картинка собирается при сохранении письма и загружается на сервер, в письме у неё будет описание: «{alt || 'без текста'}».</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}><button type="button" className="btn" onClick={() => setShot(null)}>Закрыть</button></div>
        </Modal>
      )}
    </div>
  )
}
