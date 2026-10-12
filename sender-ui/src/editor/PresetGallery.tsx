// Галерея готовых макетов с живыми превью: письмо собирается тем же кодом, что уходит
// получателю, и показывается уменьшенным в iframe. Скрипты в iframe запрещены; тот же адрес
// разрешён, иначе браузер не загрузит картинки шапок с сервера Sender.
import { useEffect, useMemo, useRef, useState } from 'react'
import { PRESETS, SERVICE_GROUP, type Preset } from './presets'
import { GALLERY_GROUPS } from './gallery'
import { designToHtml } from './render'

const ALL = 'Все'
const GROUPS = [ALL, ...GALLERY_GROUPS, SERVICE_GROUP]
// ширина собранного письма вместе с полями вокруг
const FRAME_W = 640

function Thumb({ preset }: { preset: Preset }) {
  const box = useRef<HTMLDivElement>(null)
  const [k, setK] = useState(0.4)
  const html = useMemo(() => {
    const d = preset.design()
    // в превью без полей вокруг письма и без скроллбара, переменные заменены примером
    return designToHtml(d, preset.title)
      .replace('</style>', 'html{overflow:hidden}body>table>tbody>tr>td{padding:0!important}</style>')
      .replace(/\{\{#if\s+\w+\s*\}\}([\s\S]*?)\{\{\/if\}\}/g, '$1')
      .replace(/\{\{\s*first_name\s*\}\}/g, 'Анна')
  }, [preset])
  const outer = useMemo(() => preset.design().settings.outerBg, [preset])
  useEffect(() => {
    const el = box.current
    if (!el) return
    const ro = new ResizeObserver(() => setK(el.clientWidth / FRAME_W))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  return (
    <div className="tg-thumb" ref={box} style={{ background: outer }}>
      <iframe title={`Превью: ${preset.title}`} srcDoc={html} sandbox="allow-same-origin" tabIndex={-1} aria-hidden="true"
        style={{ width: FRAME_W, height: 1400, transform: `scale(${k})` }} />
    </div>
  )
}

export function PresetGallery({ onPick, action = 'Использовать' }: { onPick: (key: string) => void; action?: string }) {
  const [group, setGroup] = useState(ALL)
  const list = PRESETS.filter((p) => group === ALL || p.group === group)
  const count = (g: string) => (g === ALL ? PRESETS.length : PRESETS.filter((p) => p.group === g).length)
  return (
    <div className="tg">
      <div className="tg-filters" role="radiogroup" aria-label="Тематика макетов">
        {GROUPS.map((g) => (
          <button key={g} type="button" role="radio" aria-checked={group === g} className={group === g ? 'on' : ''} onClick={() => setGroup(g)}>
            {g}<span>{count(g)}</span>
          </button>
        ))}
      </div>
      <div className="tg-grid">
        {list.map((p) => (
          <button type="button" key={p.key} className="tg-card" onClick={() => onPick(p.key)}>
            <Thumb preset={p} />
            <span className="tg-meta">
              <b>{p.title}</b>
              <span>{p.text}</span>
            </span>
            <span className="tg-use">{action}</span>
          </button>
        ))}
      </div>
    </div>
  )
}
