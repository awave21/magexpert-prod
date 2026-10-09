import { useRef, useState, type ReactNode } from 'react'
import { AlignCenter, AlignLeft, AlignRight, ImageUp, Loader2, Plus, Trash2, X } from 'lucide-react'
import { tokenStore } from '../api'
import { BLOCK_LABELS, FONTS, type Align, type Block, type Padding, type Settings, type Show } from './model'

// ---------- поля ----------

export function Row({ label, children, stack }: { label: string; children: ReactNode; stack?: boolean }) {
  return (
    <div className={stack ? 'be-row stack' : 'be-row'}>
      <span className="be-lab">{label}</span>
      {children}
    </div>
  )
}

export function Group({ title, children }: { title?: string; children: ReactNode }) {
  return (
    <div className="be-grp">
      {title && <div className="be-ov">{title}</div>}
      {children}
    </div>
  )
}

export function Num({ value, onChange, min = 0, max = 999, suffix, label }: { value: number; onChange: (v: number) => void; min?: number; max?: number; suffix?: string; label: string }) {
  return (
    <span className="be-num">
      <input type="number" aria-label={label} value={value} min={min} max={max}
        onChange={(e) => { const v = Number(e.target.value); if (!Number.isNaN(v)) onChange(Math.max(min, Math.min(max, v))) }} />
      {suffix && <span>{suffix}</span>}
    </span>
  )
}

export function ColorField({ value, onChange, label, allowEmpty, emptyLabel = 'Нет' }: { value: string | null; onChange: (v: string | null) => void; label: string; allowEmpty?: boolean; emptyLabel?: string }) {
  const [draft, setDraft] = useState<string | null>(null)
  const hex = value ?? ''
  return (
    <span className="be-color">
      <label className="be-swatch" style={{ background: value || undefined }} data-empty={!value || undefined} title={label}>
        <input type="color" className="sr" aria-label={label} value={/^#[0-9a-f]{6}$/i.test(hex) ? hex : '#ffffff'} onChange={(e) => onChange(e.target.value.toUpperCase())} />
      </label>
      <input className="mono" aria-label={`${label}, код`} value={draft ?? (value ? hex.toUpperCase() : emptyLabel)}
        onFocus={() => setDraft(hex)} onChange={(e) => setDraft(e.target.value)}
        onBlur={() => {
          const v = (draft ?? '').trim()
          if (/^#?[0-9a-f]{6}$/i.test(v)) onChange(('#' + v.replace('#', '')).toUpperCase())
          else if (!v && allowEmpty) onChange(null)
          setDraft(null)
        }}
        onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()} />
      {allowEmpty && value && <button type="button" className="be-x" onClick={() => onChange(null)} aria-label="Сбросить цвет"><X size={12} /></button>}
    </span>
  )
}

export function Seg<T extends string | number>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: ReactNode; title?: string }[]; label: string }) {
  return (
    <div className="seg" role="radiogroup" aria-label={label}>
      {options.map((o) => (
        <button key={String(o.value)} type="button" role="radio" aria-checked={value === o.value} title={o.title} className={value === o.value ? 'on' : ''} onClick={() => onChange(o.value)}>{o.label}</button>
      ))}
    </div>
  )
}

export function Slider({ value, onChange, min, max, label }: { value: number; onChange: (v: number) => void; min: number; max: number; label: string }) {
  return (
    <span className="be-slider">
      <input type="range" min={min} max={max} value={value} aria-label={label} onChange={(e) => onChange(Number(e.target.value))} />
      <span className="mono">{value}</span>
    </span>
  )
}

const AlignSeg = ({ value, onChange }: { value: Align; onChange: (a: Align) => void }) => (
  <Seg label="Выравнивание" value={value} onChange={onChange} options={[
    { value: 'left', label: <AlignLeft size={15} />, title: 'Слева' },
    { value: 'center', label: <AlignCenter size={15} />, title: 'По центру' },
    { value: 'right', label: <AlignRight size={15} />, title: 'Справа' },
  ]} />
)

function PaddingFields({ value, onChange }: { value: Padding; onChange: (p: Padding) => void }) {
  const f = (k: keyof Padding, label: string) => (
    <label className="be-pad"><span>{label}</span><Num label={`Отступ ${label.toLowerCase()}`} value={value[k]} max={120} onChange={(v) => onChange({ ...value, [k]: v })} /></label>
  )
  return <div className="be-pads">{f('t', 'Сверху')}{f('b', 'Снизу')}{f('l', 'Слева')}{f('r', 'Справа')}</div>
}

export function TextInput({ value, onChange, label, placeholder, mono, multiline, hint }: { value: string; onChange: (v: string) => void; label: string; placeholder?: string; mono?: boolean; multiline?: boolean; hint?: string }) {
  return (
    <div className="field">
      <label className="be-lab">{label}</label>
      {multiline
        ? <textarea className={`textarea${mono ? ' code' : ''}`} rows={8} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} aria-label={label} spellCheck={!mono} />
        : <input className={`input sm${mono ? ' mono' : ''}`} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} aria-label={label} />}
      {hint && <div className="hint">{hint}</div>}
    </div>
  )
}

// ---------- загрузка картинки ----------

export async function uploadImage(file: File): Promise<{ url: string; name: string; meta: string }> {
  const body = new FormData()
  body.append('file', file)
  const res = await fetch('/api/sender/v1/admin/assets', {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${tokenStore.get() ?? ''}` },
    body,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors?.file?.[0] ?? data.message ?? 'Не удалось загрузить картинку')
  const d = data.data
  const kb = Math.round(d.size / 1024)
  return { url: d.url, name: d.name, meta: [d.width && d.height ? `${d.width}×${d.height}` : '', `${kb} КБ`].filter(Boolean).join(' · ') }
}

export function UploadButton({ onUploaded, onError, children, className = 'btn sm' }: { onUploaded: (r: { url: string; name: string; meta: string }) => void; onError: (m: string) => void; children: ReactNode; className?: string }) {
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  return (
    <>
      <button type="button" className={className} disabled={busy} onClick={() => input.current?.click()}>
        {busy ? <Loader2 size={14} className="spin" /> : <ImageUp size={14} />}{busy ? 'Загружаем…' : children}
      </button>
      <input ref={input} type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden onChange={async (e) => {
        const file = e.target.files?.[0]
        e.target.value = ''
        if (!file) return
        setBusy(true)
        try { onUploaded(await uploadImage(file)) } catch (err) { onError((err as Error).message) } finally { setBusy(false) }
      }} />
    </>
  )
}

// ---------- настройки выбранного блока ----------

type InspectorProps = {
  block: Block
  position: string
  settings: Settings
  onChange: (patch: Partial<Block>) => void
  onRemove: () => void
  onError: (m: string) => void
  varHint: string
}

export function BlockInspector({ block: b, position, settings: s, onChange, onRemove, onError, varHint }: InspectorProps) {
  const set = onChange as (p: Record<string, unknown>) => void
  let body: ReactNode = null

  switch (b.type) {
    case 'heading':
    case 'text':
      body = (
        <Group title="Текст">
          <Row label="Размер"><Num label="Размер шрифта" value={b.size ?? (b.type === 'heading' ? s.headingSize : s.fontSize)} min={10} max={64} suffix="px" onChange={(v) => set({ size: v })} /></Row>
          <Row label="Цвет"><ColorField label="Цвет текста" value={b.color} allowEmpty emptyLabel="Как в письме" onChange={(v) => set({ color: v })} /></Row>
          <Row label="Выравнивание"><AlignSeg value={b.align} onChange={(v) => set({ align: v })} /></Row>
          <div className="hint">Выделите слова в письме, чтобы сделать их жирными, добавить ссылку или переменную.</div>
        </Group>
      )
      break
    case 'footer':
      body = (
        <Group title="Подвал">
          <Row label="Выравнивание"><AlignSeg value={b.align} onChange={(v) => set({ align: v })} /></Row>
          <div className="hint">Укажите реквизиты отправителя и причину, по которой человек получил письмо. Для рекламных рассылок нужна ссылка отписки.</div>
        </Group>
      )
      break
    case 'image':
      body = (
        <>
          <Group title="Файл">
            {b.src ? (
              <div className="be-file">
                <img src={b.src} alt="" />
                <div><b>{b.name || 'Картинка'}</b><span className="sub">{b.meta || 'по ссылке'}</span></div>
              </div>
            ) : <div className="hint">Картинка ещё не выбрана.</div>}
            <div className="row" style={{ gap: 8 }}>
              <UploadButton onError={onError} onUploaded={(r) => set({ src: r.url, name: r.name, meta: r.meta, alt: b.alt || r.name.replace(/\.[a-z0-9]+$/i, '') })}>{b.src ? 'Заменить' : 'Загрузить'}</UploadButton>
              {b.src && <button type="button" className="btn sm text danger-text" onClick={() => set({ src: '', name: '', meta: '' })}>Убрать</button>}
            </div>
            <TextInput label="Или ссылка на картинку" mono value={b.src} placeholder="https://…" onChange={(v) => set({ src: v, name: '', meta: '' })} />
            <div className="hint">PNG, JPG, GIF или WebP до 5 МБ. Картинка хранится на сайте, чтобы получатель её увидел.</div>
          </Group>
          <Group title="Описание и ссылка">
            <TextInput label="Описание картинки" value={b.alt} onChange={(v) => set({ alt: v })} placeholder="Например: обложка вебинара" hint="Видно, если почта не загрузила картинки. Его же читает экранный диктор." />
            <TextInput label="Ссылка при нажатии" mono value={b.href} onChange={(v) => set({ href: v })} placeholder="https://… или {{ event_url }}" hint={varHint} />
          </Group>
          <Group title="Размер и вид">
            <Row label="Ширина"><Seg label="Ширина" value={b.full ? 'full' : 'own'} onChange={(v) => set({ full: v === 'full' })} options={[{ value: 'own', label: 'Своя' }, { value: 'full', label: 'Во всю' }]} /></Row>
            {!b.full && <Row label="Ширина, px"><Num label="Ширина картинки" value={b.width} min={40} max={s.width} onChange={(v) => set({ width: v })} /></Row>}
            <Row label="Скругление"><Slider label="Скругление" value={b.radius} min={0} max={32} onChange={(v) => set({ radius: v })} /></Row>
            <Row label="Выравнивание"><AlignSeg value={b.align} onChange={(v) => set({ align: v })} /></Row>
          </Group>
        </>
      )
      break
    case 'button':
      body = (
        <>
          <Group title="Содержимое">
            <TextInput label="Текст кнопки" value={b.text} onChange={(v) => set({ text: v })} />
            <TextInput label="Ссылка" mono value={b.href} onChange={(v) => set({ href: v })} placeholder="https://… или {{ event_url }}" hint={varHint} />
          </Group>
          <Group title="Вид">
            <Row label="Цвет кнопки"><ColorField label="Цвет кнопки" value={b.fill} allowEmpty emptyLabel="Как в стилях" onChange={(v) => set({ fill: v })} /></Row>
            <Row label="Цвет текста"><ColorField label="Цвет текста кнопки" value={b.color} onChange={(v) => set({ color: v ?? '#FFFFFF' })} /></Row>
            <Row label="Скругление"><Slider label="Скругление" value={b.radius ?? s.buttonRadius} min={0} max={28} onChange={(v) => set({ radius: v })} /></Row>
            <Row label="Ширина"><Seg label="Ширина кнопки" value={b.full ? 'full' : 'auto'} onChange={(v) => set({ full: v === 'full' })} options={[{ value: 'auto', label: 'По тексту' }, { value: 'full', label: 'Во всю' }]} /></Row>
            {!b.full && <Row label="Выравнивание"><AlignSeg value={b.align} onChange={(v) => set({ align: v })} /></Row>}
            <ContrastNote fg={b.color} bg={b.fill ?? s.buttonBg} />
          </Group>
        </>
      )
      break
    case 'divider':
      body = (
        <Group title="Линия">
          <Row label="Цвет"><ColorField label="Цвет линии" value={b.color} onChange={(v) => set({ color: v ?? '#DEE1E8' })} /></Row>
          <Row label="Толщина"><Slider label="Толщина" value={b.thickness} min={1} max={8} onChange={(v) => set({ thickness: v })} /></Row>
        </Group>
      )
      break
    case 'spacer':
      body = (
        <Group title="Отступ">
          <Row label="Высота"><Slider label="Высота" value={b.height} min={4} max={120} onChange={(v) => set({ height: v })} /></Row>
        </Group>
      )
      break
    case 'columns':
      body = (
        <Group title="Колонки">
          <Row label="Пропорции"><Seg label="Пропорции" value={b.ratio} onChange={(v) => set({ ratio: v })} options={[{ value: '50-50', label: '1:1' }, { value: '33-67', label: '1:2' }, { value: '67-33', label: '2:1' }]} /></Row>
          <Row label="Между колонками"><Slider label="Расстояние между колонками" value={b.gap} min={0} max={48} onChange={(v) => set({ gap: v })} /></Row>
          <div className="hint">На телефоне колонки встают друг под другом. Блоки перетаскивайте прямо в колонку.</div>
        </Group>
      )
      break
    case 'social':
      body = (
        <Group title="Ссылки">
          {b.links.map((l, i) => (
            <div key={i} className="be-link-row">
              <input className="input sm" aria-label="Название" value={l.label} onChange={(e) => set({ links: b.links.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })} />
              <input className="input sm mono" aria-label="Адрес" value={l.url} onChange={(e) => set({ links: b.links.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)) })} />
              <button type="button" className="btn icon sm ghost" aria-label="Удалить ссылку" onClick={() => set({ links: b.links.filter((_, j) => j !== i) })}><X size={14} /></button>
            </div>
          ))}
          <button type="button" className="btn sm text" style={{ alignSelf: 'flex-start' }} onClick={() => set({ links: [...b.links, { label: 'Сайт', url: 'https://' }] })}><Plus size={14} />Добавить ссылку</button>
          <Row label="Выравнивание"><AlignSeg value={b.align} onChange={(v) => set({ align: v })} /></Row>
        </Group>
      )
      break
    case 'html':
      body = (
        <Group title="Свой HTML">
          <TextInput label="Код" mono multiline value={b.html} onChange={(v) => set({ html: v })} hint="Вставляется в письмо как есть. Используйте таблицы и встроенные стили: внешние CSS почта не понимает." />
        </Group>
      )
      break
  }

  return (
    <div className="be-inspector">
      <div className="be-rh">
        <b>{BLOCK_LABELS[b.type]}</b>
        <span className="chip">{position}</span>
      </div>
      {body}
      <Group title="Блок">
        <Row label="Фон"><ColorField label="Фон блока" value={b.bg || null} allowEmpty emptyLabel="Прозрачный" onChange={(v) => onChange({ bg: v ?? '' })} /></Row>
        <span className="be-lab">Отступы, px</span>
        <PaddingFields value={b.padding} onChange={(p) => onChange({ padding: p })} />
        <Row label="Показывать">
          <Seg<Show> label="Где показывать" value={b.show} onChange={(v) => onChange({ show: v })} options={[{ value: 'all', label: 'Везде' }, { value: 'desktop', label: 'ПК' }, { value: 'mobile', label: 'Тел.' }]} />
        </Row>
      </Group>
      <button type="button" className="btn sm text danger-text" style={{ alignSelf: 'flex-start' }} onClick={onRemove}><Trash2 size={14} />Удалить блок</button>
    </div>
  )
}

// ---------- контраст ----------

const lum = (hex: string) => {
  const m = hex.replace('#', '').match(/.{2}/g)
  if (!m || m.length < 3) return 1
  const [r, g, b] = m.map((x) => { const c = parseInt(x, 16) / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
export const contrast = (a: string, b: string) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }

function ContrastNote({ fg, bg }: { fg: string; bg: string }) {
  const c = contrast(fg, bg)
  const ok = c >= 4.5
  return (
    <div className={`be-contrast ${ok ? 'ok' : 'bad'}`}>
      <i />{`Контраст ${c.toFixed(1).replace('.', ',')}:1. `}{ok ? 'Читается хорошо.' : 'Текст плохо читается, выберите цвета контрастнее.'}
    </div>
  )
}

// ---------- стили письма ----------

export function StylesPanel({ settings: s, onChange }: { settings: Settings; onChange: (p: Partial<Settings>) => void }) {
  return (
    <div className="be-styles">
      <div className="hint">Применяются ко всем блокам, у которых не задан свой стиль.</div>
      <Group title="Письмо">
        <Row label="Ширина"><Seg label="Ширина письма" value={s.width} onChange={(v) => onChange({ width: v })} options={[{ value: 600, label: '600' }, { value: 640, label: '640' }, { value: 700, label: '700' }]} /></Row>
        <Row label="Фон письма"><ColorField label="Фон письма" value={s.bodyBg} onChange={(v) => onChange({ bodyBg: v ?? '#FFFFFF' })} /></Row>
        <Row label="Фон вокруг"><ColorField label="Фон вокруг письма" value={s.outerBg} onChange={(v) => onChange({ outerBg: v ?? '#EEF1F6' })} /></Row>
        <Row label="Скругление"><Slider label="Скругление письма" value={s.radius} min={0} max={24} onChange={(v) => onChange({ radius: v })} /></Row>
      </Group>
      <Group title="Текст">
        <Row label="Шрифт">
          <select className="select be-select" aria-label="Шрифт" value={s.font} onChange={(e) => onChange({ font: e.target.value })}>
            {FONTS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
          </select>
        </Row>
        <Row label="Размер"><Num label="Размер текста" value={s.fontSize} min={12} max={22} suffix="px" onChange={(v) => onChange({ fontSize: v })} /></Row>
        <Row label="Межстрочный">
          <Seg label="Межстрочный интервал" value={s.lineHeight} onChange={(v) => onChange({ lineHeight: v })} options={[{ value: 1.4, label: '1,4' }, { value: 1.6, label: '1,6' }, { value: 1.8, label: '1,8' }]} />
        </Row>
        <Row label="Цвет текста"><ColorField label="Цвет текста" value={s.textColor} onChange={(v) => onChange({ textColor: v ?? '#1C1F27' })} /></Row>
        <Row label="Цвет ссылок"><ColorField label="Цвет ссылок" value={s.linkColor} onChange={(v) => onChange({ linkColor: v ?? '#3B51D3' })} /></Row>
      </Group>
      <Group title="Заголовки">
        <Row label="Шрифт">
          <select className="select be-select" aria-label="Шрифт заголовков" value={s.headingFont} onChange={(e) => onChange({ headingFont: e.target.value })}>
            <option value="inherit">Как у текста</option>
            {FONTS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
          </select>
        </Row>
        <Row label="Размер"><Num label="Размер заголовка" value={s.headingSize} min={16} max={48} suffix="px" onChange={(v) => onChange({ headingSize: v })} /></Row>
        <Row label="Толщина"><Seg label="Толщина заголовка" value={s.headingWeight} onChange={(v) => onChange({ headingWeight: v })} options={[{ value: 500, label: 'Средний' }, { value: 700, label: 'Жирный' }]} /></Row>
      </Group>
      <Group title="Кнопки">
        <Row label="Цвет"><ColorField label="Цвет кнопок" value={s.buttonBg} onChange={(v) => onChange({ buttonBg: v ?? '#4C68EB' })} /></Row>
        <Row label="Скругление"><Slider label="Скругление кнопок" value={s.buttonRadius} min={0} max={28} onChange={(v) => onChange({ buttonRadius: v })} /></Row>
      </Group>
    </div>
  )
}

export { BLOCK_LABELS }
