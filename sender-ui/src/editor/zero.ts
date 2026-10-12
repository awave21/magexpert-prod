// Зеро-блок: свободная композиция из слоёв (фигуры, текст, фото, графика) с наложением и анимацией.
// Почта не умеет накладывать элементы друг на друга, поэтому при сохранении письма блок
// собирается в одну картинку: PNG или GIF с анимацией (zero-render.ts). В редакторе и в превью
// макетов слои показываются живой разметкой, её собирают функции этого файла.

export type ZeroAnim = 'none' | 'float' | 'sway' | 'wobble' | 'pulse' | 'breathe' | 'spin' | 'blink' | 'slideUp' | 'slideL' | 'pop' | 'flip' | 'scan' | 'draw'
export type ZeroBlend = 'normal' | 'multiply' | 'screen' | 'overlay' | 'soft-light' | 'difference' | 'color-dodge' | 'luminosity'
export type ZeroAlign = 'left' | 'center' | 'right'

type LayerBase = {
  id: string; name: string; x: number; y: number; w: number; h: number; rot: number; op: number
  blend: ZeroBlend; anim: ZeroAnim; delay: number; hidden: boolean; locked: boolean
}
// fill2 пустой — сплошная заливка; иначе градиент от fill к fill2 (радиальный — эллипс по размеру фигуры)
export type RectLayer = LayerBase & {
  kind: 'rect'; fill: string; fill2: string; grad: 'linear' | 'radial'; angle: number; radius: number
  stroke: string; strokeW: number; dashed: boolean; shadow: string; shadowX: number; shadowY: number; shadowBlur: number
}
export type TextLayer = LayerBase & {
  kind: 'text'; text: string; font: string; size: number; weight: number; color: string; align: ZeroAlign
  lh: number; ls: number; upper: boolean; italic: boolean; strike: boolean
}
export type ArtLayer = LayerBase & { kind: 'art'; art: string; c1: string; c2: string; c3: string; radius: number }
export type ImageLayer = LayerBase & { kind: 'image'; src: string; fit: 'cover' | 'contain'; radius: number }
export type ZeroLayer = RectLayer | TextLayer | ArtLayer | ImageLayer
export type ZeroKind = ZeroLayer['kind']

// ширина холста, в которой рисуются слои; в письме блок масштабируется под ширину содержимого
export const ZERO_W = 600
// длина цикла анимации: периоды всех анимаций делят её нацело, поэтому GIF зацикливается без рывка
export const ZERO_LOOP = 6

export const ZERO_ANIMS: Record<ZeroAnim, { label: string; period: number }> = {
  none: { label: 'Без анимации', period: 0 },
  float: { label: 'Парение', period: 6 },
  sway: { label: 'Покачивание', period: 6 },
  wobble: { label: 'Стикер', period: 3 },
  pulse: { label: 'Пульс', period: 3 },
  breathe: { label: 'Дыхание', period: 6 },
  spin: { label: 'Вращение', period: 6 },
  blink: { label: 'Мигание', period: 1.5 },
  slideUp: { label: 'Выезд снизу', period: 6 },
  slideL: { label: 'Выезд слева', period: 6 },
  pop: { label: 'Появление', period: 6 },
  flip: { label: 'Перелистывание', period: 2 },
  scan: { label: 'Сканирование', period: 3 },
  draw: { label: 'Прорисовка линии', period: 6 },
}

export const ZERO_BLENDS: Record<ZeroBlend, string> = {
  normal: 'Обычный', multiply: 'Умножение', screen: 'Экран', overlay: 'Перекрытие', 'soft-light': 'Мягкий свет',
  difference: 'Разница', 'color-dodge': 'Осветление', luminosity: 'Яркость',
}

// шрифты зеро-блока: блок уходит картинкой, поэтому можно не только почтовые шрифты
export const ZERO_FONTS: Record<string, { label: string; css: string }> = {
  unb: { label: 'Unbounded', css: "'Unbounded', 'Arial Black', sans-serif" },
  man: { label: 'Manrope', css: "'Manrope', Arial, sans-serif" },
  play: { label: 'Playfair Display', css: "'Playfair Display', Georgia, serif" },
  rubik: { label: 'Rubik Mono One', css: "'Rubik Mono One', 'Arial Black', sans-serif" },
  cav: { label: 'Caveat', css: "'Caveat', 'Comic Sans MS', cursive" },
  mono: { label: 'JetBrains Mono', css: "'JetBrains Mono', 'Courier New', monospace" },
  arial: { label: 'Arial', css: 'Arial, Helvetica, sans-serif' },
  georgia: { label: 'Georgia', css: 'Georgia, Times, serif' },
}
const FONTS_URL = 'https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=JetBrains+Mono:wght@400;600&family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,800;1,600&family=Rubik+Mono+One&family=Unbounded:wght@500;700;800&display=swap'
export const fontCss = (key: string) => (ZERO_FONTS[key] ?? ZERO_FONTS.man).css

// ---------- значения по умолчанию ----------

export const uidZ = () => 'z' + Math.random().toString(36).slice(2, 9)
const BASE = (): LayerBase => ({ id: uidZ(), name: '', x: 0, y: 0, w: 120, h: 120, rot: 0, op: 1, blend: 'normal', anim: 'none', delay: 0, hidden: false, locked: false })

export function createLayer(kind: 'rect', o?: Partial<RectLayer>): RectLayer
export function createLayer(kind: 'text', o?: Partial<TextLayer>): TextLayer
export function createLayer(kind: 'art', o?: Partial<ArtLayer>): ArtLayer
export function createLayer(kind: 'image', o?: Partial<ImageLayer>): ImageLayer
export function createLayer(kind: ZeroKind, o?: Partial<ZeroLayer>): ZeroLayer
export function createLayer(kind: ZeroKind, o: Partial<ZeroLayer> = {}): ZeroLayer {
  switch (kind) {
    case 'rect': return { ...BASE(), kind, fill: '#F78028', fill2: '', grad: 'linear', angle: 180, radius: 0, stroke: '', strokeW: 0, dashed: false, shadow: '', shadowX: 0, shadowY: 0, shadowBlur: 0, ...(o as Partial<RectLayer>) }
    case 'text': return { ...BASE(), kind, w: 280, h: 40, text: 'Текст', font: 'man', size: 24, weight: 700, color: '#111111', align: 'left', lh: 1.15, ls: 0, upper: false, italic: false, strike: false, ...(o as Partial<TextLayer>) }
    case 'art': return { ...BASE(), kind, art: 'sparkle', c1: '#F78028', c2: '#4B69AA', c3: '', radius: 0, ...(o as Partial<ArtLayer>) }
    case 'image': return { ...BASE(), kind, src: '', fit: 'cover', radius: 0, ...(o as Partial<ImageLayer>) }
  }
}

// сервер превращает пустые строки в null: возвращаем значения по умолчанию
export function normalizeLayers(raw: unknown): ZeroLayer[] {
  if (!Array.isArray(raw)) return []
  return raw.filter((l) => l && typeof l === 'object' && ['rect', 'text', 'art', 'image'].includes((l as ZeroLayer).kind)).map((l) => {
    const def = createLayer((l as ZeroLayer).kind) as unknown as Record<string, unknown>
    const out = { ...(l as Record<string, unknown>) }
    for (const [k, v] of Object.entries(def)) if (out[k] === undefined || out[k] === null) out[k] = k === 'id' ? uidZ() : v
    return out as unknown as ZeroLayer
  })
}

export const layerName = (l: ZeroLayer): string => {
  if (l.name) return l.name
  if (l.kind === 'text') return l.text.replace(/\s+/g, ' ').trim().slice(0, 28) || 'Текст'
  if (l.kind === 'rect') return l.radius >= 999 ? 'Круг' : 'Фигура'
  if (l.kind === 'art') return ART[l.art]?.name ?? 'Графика'
  return 'Фото'
}

// текст слоёв, который попадёт в alt картинки
export const zeroAlt = (layers: ZeroLayer[]) =>
  layers.filter((l): l is TextLayer => l.kind === 'text' && !l.hidden).map((l) => l.text.replace(/\s+/g, ' ').trim()).filter(Boolean).join('. ')

// ---------- безопасность значений, которые попадают в style ----------

export const safeColor = (v: string) => (/^[#a-zA-Z0-9(),.%\s-]{0,64}$/.test(v) && !/url|expression/i.test(v) ? v : '')
export const safeSrc = (v: string) => (/^(https?:)?\/\//i.test(v) || /^\/[^/]/.test(v) || /^data:image\/(png|jpeg|gif|webp);base64,/i.test(v) ? v : '')
const esc = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

// ---------- графика ----------

const rng = (seed: number) => () => (seed = (seed * 16807) % 2147483647) / 2147483647
type ArtDef = { name: string; vb: [number, number]; par: string; d: (a: string, b: string, c: string, u: string, t?: number) => string }
const fx = (n: number) => n.toFixed(1)

export const ART: Record<string, ArtDef> = {
  wave: { name: 'Волна', vb: [600, 60], par: 'none', d: (a) => `<path pathLength="1" vector-effect="non-scaling-stroke" d="M0 30C50 6 100 6 150 30S250 54 300 30 400 6 450 30 550 54 600 30" fill="none" stroke="${a}" stroke-width="3" stroke-linecap="round"/>` },
  network: { name: 'Сеть', vb: [200, 200], par: 'xMidYMid meet', d: (a, b) => { const n = [[100, 100, 18], [40, 62, 12], [160, 52, 14], [164, 150, 11], [62, 160, 13], [100, 26, 8], [18, 120, 7], [190, 106, 6]], ln = [[0, 1], [0, 2], [0, 3], [0, 4], [2, 5], [1, 6], [3, 7]]; return ln.map(([i, j]) => `<line x1="${n[i][0]}" y1="${n[i][1]}" x2="${n[j][0]}" y2="${n[j][1]}" stroke="${a}" stroke-width="4" stroke-linecap="round"/>`).join('') + n.map((p, i) => `<circle cx="${p[0]}" cy="${p[1]}" r="${p[2]}" fill="${i % 2 ? b : a}"/>`).join('') } },
  bubbles: { name: 'Пузыри', vb: [300, 300], par: 'xMidYMid slice', d: (a, b) => [[80, 90, 60], [205, 70, 44], [230, 195, 70], [86, 222, 50], [150, 150, 30], [290, 40, 26], [20, 290, 30]].map(([x, y, r]) => `<circle cx="${x}" cy="${y}" r="${r}" fill="${b}" fill-opacity=".38" stroke="${a}" stroke-width="2"/><circle cx="${fx(x + r * 0.18)}" cy="${fx(y - r * 0.12)}" r="${fx(r * 0.34)}" fill="${a}" fill-opacity=".85"/>`).join('') },
  person: { name: 'Силуэт человека', vb: [300, 360], par: 'xMidYMax meet', d: (a, b, c) => `${c ? `<rect width="300" height="360" fill="${c}"/>` : ''}<path d="M24 360C34 282 88 248 150 244C212 248 266 282 276 360Z" fill="${b}"/><rect x="128" y="188" width="44" height="70" rx="18" fill="${a}"/><ellipse cx="150" cy="146" rx="56" ry="66" fill="${a}"/><path d="M92 150C84 86 128 66 160 72C204 80 216 118 208 156C200 122 176 104 146 106C118 108 100 126 92 150Z" fill="#000" fill-opacity=".38"/><path d="M118 250C130 270 170 270 182 250" fill="none" stroke="#000" stroke-opacity=".12" stroke-width="6" stroke-linecap="round"/>` },
  play: { name: 'Кнопка «Смотреть»', vb: [100, 100], par: 'xMidYMid meet', d: (a, b) => `<circle cx="50" cy="50" r="48" fill="${a}"/><path d="M41 32 70 50 41 68Z" fill="${b || '#FFFFFF'}"/>` },
  bubble: { name: 'Облачко реплики', vb: [200, 150], par: 'xMidYMid meet', d: (a, b) => `<path d="M24 8H176A20 20 0 0 1 196 28V96A20 20 0 0 1 176 116H74L38 144V116H24A20 20 0 0 1 4 96V28A20 20 0 0 1 24 8Z" fill="${a}"/>${b ? `<rect x="30" y="42" width="110" height="10" rx="5" fill="${b}"/><rect x="30" y="66" width="80" height="10" rx="5" fill="${b}" fill-opacity=".6"/>` : ''}` },
  check: { name: 'Галочка', vb: [100, 100], par: 'xMidYMid meet', d: (a, b) => `<circle cx="50" cy="50" r="46" fill="${a}"/><path d="M30 52 44 66 72 36" fill="none" stroke="${b || '#FFFFFF'}" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>` },
  gift: { name: 'Подарок', vb: [120, 120], par: 'xMidYMid meet', d: (a, b) => `<rect x="10" y="44" width="100" height="70" rx="8" fill="${a}"/><rect x="4" y="30" width="112" height="22" rx="6" fill="${a}"/><rect x="52" y="30" width="16" height="84" fill="${b}"/><path d="M60 30C44 4 18 10 26 26 30 32 48 32 60 30ZM60 30C76 4 102 10 94 26 90 32 72 32 60 30Z" fill="none" stroke="${b}" stroke-width="6" stroke-linejoin="round"/>` },
  balloon: { name: 'Шарик', vb: [80, 160], par: 'xMidYMid meet', d: (a) => `<ellipse cx="40" cy="44" rx="34" ry="42" fill="${a}"/><path d="M34 86H46L40 94Z" fill="${a}"/><path d="M40 94C30 112 52 124 38 158" fill="none" stroke="${a}" stroke-width="2"/><ellipse cx="28" cy="30" rx="7" ry="11" fill="#fff" fill-opacity=".35"/>` },
  bars: { name: 'Столбики', vb: [200, 120], par: 'xMidYMax meet', d: (a, b) => [30, 52, 40, 76, 64, 100].map((h, i) => `<rect x="${i * 33 + 4}" y="${120 - h}" width="24" height="${h}" rx="5" fill="${i === 5 ? b : a}"/>`).join('') },
  plus: { name: 'Плюс', vb: [100, 100], par: 'xMidYMid meet', d: (a) => `<rect x="38" y="8" width="24" height="84" rx="12" fill="${a}"/><rect x="8" y="38" width="84" height="24" rx="12" fill="${a}"/>` },
  hall: { name: 'Зал', vb: [300, 220], par: 'xMidYMid slice', d: (a, b, _c, u) => {
    let s = `<defs><linearGradient id="h${u}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0B1030"/><stop offset="1" stop-color="${b}"/></linearGradient><radialGradient id="q${u}" cx=".5" cy=".28" r=".6"><stop offset="0" stop-color="${a}" stop-opacity=".55"/><stop offset="1" stop-color="${a}" stop-opacity="0"/></radialGradient></defs><rect width="300" height="220" fill="url(#h${u})"/><rect width="300" height="220" fill="url(#q${u})"/><rect x="70" y="24" width="160" height="88" rx="3" fill="${a}"/><rect x="82" y="38" width="80" height="8" rx="2" fill="#fff" opacity=".85"/><rect x="82" y="54" width="120" height="5" rx="2" fill="#fff" opacity=".5"/><rect x="82" y="64" width="100" height="5" rx="2" fill="#fff" opacity=".5"/><path d="M90 104 120 84 140 94 170 72 214 104Z" fill="#fff" opacity=".35"/><circle cx="254" cy="96" r="7" fill="#05081A"/><path d="M242 134C242 112 266 112 266 134Z" fill="#05081A"/>`
    for (let r = 0; r < 4; r++) { const y = 150 + r * 22, n = 8 + r, rr = 8 + r * 1.6; for (let i = 0; i < n; i++) { const x = fx((300 / n) * (i + 0.5) + (r % 2 ? 8 : -6)); s += `<ellipse cx="${x}" cy="${fx(y + rr * 1.5)}" rx="${fx(rr * 1.5)}" ry="${fx(rr)}" fill="#04061A"/><circle cx="${x}" cy="${y}" r="${fx(rr)}" fill="#04061A" stroke="${a}" stroke-opacity=".35"/>` } }
    return s
  } },
  heart: { name: 'Сердце', vb: [100, 90], par: 'xMidYMid meet', d: (a) => `<path d="M50 86C20 64 4 48 4 28 4 14 15 4 28 4 38 4 46 10 50 18 54 10 62 4 72 4 85 4 96 14 96 28 96 48 80 64 50 86Z" fill="${a}"/>` },
  sparkle: { name: 'Искра', vb: [100, 100], par: 'xMidYMid meet', d: (a) => `<path d="M50 0C56 40 60 44 100 50 60 56 56 60 50 100 44 60 40 56 0 50 40 44 44 40 50 0Z" fill="${a}"/>` },
  burst: { name: 'Звезда-бейдж', vb: [200, 200], par: 'xMidYMid meet', d: (a) => { const p: string[] = []; for (let i = 0; i < 36; i++) { const r = i % 2 ? 84 : 100, t = i * Math.PI / 18; p.push(fx(100 + r * Math.cos(t)) + ',' + fx(100 + r * Math.sin(t))) } return `<polygon points="${p.join(' ')}" fill="${a}"/>` } },
  ring: { name: 'Пунктирное кольцо', vb: [200, 200], par: 'xMidYMid meet', d: (a) => `<circle cx="100" cy="100" r="94" fill="none" stroke="${a}" stroke-width="3" stroke-dasharray="10 8"/><circle cx="100" cy="6" r="6" fill="${a}"/>` },
  stamp: { name: 'Печать', vb: [200, 200], par: 'xMidYMid meet', d: (a) => `<g fill="none" stroke="${a}"><circle cx="100" cy="100" r="92" stroke-width="6"/><circle cx="100" cy="100" r="78" stroke-width="2" stroke-dasharray="4 5"/></g>` },
  arrow: { name: 'Стрелка от руки', vb: [120, 80], par: 'xMidYMid meet', d: (a) => `<path d="M8 12C40 4 86 14 100 58M84 50 101 61 107 42" fill="none" stroke="${a}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>` },
  ticket: { name: 'Билет', vb: [520, 150], par: 'none', d: (a, b, _c, u) => `<defs><mask id="t${u}"><rect width="520" height="150" fill="#fff"/><circle cx="390" cy="0" r="16"/><circle cx="390" cy="150" r="16"/><circle cx="0" cy="75" r="14"/><circle cx="520" cy="75" r="14"/></mask></defs><rect width="520" height="150" rx="14" fill="${a}" mask="url(#t${u})"/><line x1="390" y1="24" x2="390" y2="126" stroke="${b}" stroke-opacity=".35" stroke-width="2" stroke-dasharray="6 6"/>` },
  eq: { name: 'Звуковая волна', vb: [200, 44], par: 'none', d: (a, _b, _c, _u, t) => {
    const r = rng(3); let s = ''
    for (let i = 0; i < 20; i++) {
      const full = Math.round(14 + r() * 30), ph = r()
      // в живом превью столбики двигает CSS, для кадров GIF высоту считаем сами
      const h = t === undefined ? full : Math.max(3, full * (0.25 + 0.75 * (1 - Math.cos(2 * Math.PI * (t / 2 + ph))) / 2))
      s += `<rect${t === undefined ? ' class="eqb"' : ''} x="${i * 10 + 2}" y="${fx(44 - h)}" width="6" height="${fx(h)}" rx="3" fill="${a}"${t === undefined ? ` style="animation-delay:${(-ph * 2).toFixed(2)}s"` : ''}/>`
    }
    return s
  } },
  confetti: { name: 'Конфетти', vb: [600, 540], par: 'xMidYMid slice', d: (a, b, c, _u, t) => {
    const r = rng(7), cols = [a, b, c || '#FFFFFF', '#FFFFFF']; let s = ''
    for (let i = 0; i < 56; i++) {
      const x = Math.round(r() * 600), y0 = Math.round(r() * 540), col = cols[i % 4], ph = r(), rot0 = Math.round(r() * 180)
      // за цикл каждая деталь пролетает холст сверху вниз
      const k = t === undefined ? 0 : ((t / ZERO_LOOP + ph) % 1)
      const y = t === undefined ? y0 : Math.round(-30 + k * 600)
      const rot = t === undefined ? rot0 : Math.round(rot0 + k * 620)
      const live = t === undefined ? ` class="cf" style="--y:${y0}px;animation-delay:${(-ph * ZERO_LOOP).toFixed(2)}s"` : ''
      s += i % 3
        ? `<rect${live} x="${x}" y="${y}" width="7" height="13" rx="1.5" fill="${col}" transform="rotate(${rot} ${x + 3.5} ${y + 6.5})"/>`
        : `<circle${live} cx="${x}" cy="${y}" r="4" fill="${col}"/>`
    }
    return s
  } },
  hills: { name: 'Бумажные холмы', vb: [600, 210], par: 'none', d: (a, b, c) => `<path d="M0 90C120 30 220 120 330 70 430 26 520 60 600 40V210H0Z" fill="${a}"/><path d="M0 140C110 100 200 170 320 130 420 96 500 150 600 110V210H0Z" fill="${b}"/><path d="M0 185C140 160 260 200 380 175 470 158 540 180 600 170V210H0Z" fill="${c || '#FFFFFF'}"/>` },
  grid: { name: 'Миллиметровка', vb: [600, 600], par: 'xMidYMid slice', d: (a, _b, _c, u) => `<defs><pattern id="p${u}" width="24" height="24" patternUnits="userSpaceOnUse"><path d="M24 0H0V24" fill="none" stroke="${a}"/></pattern></defs><rect width="600" height="600" fill="url(#p${u})"/>` },
  halftone: { name: 'Растр', vb: [600, 600], par: 'xMidYMid slice', d: (a, _b, _c, u) => `<defs><pattern id="p${u}" width="14" height="14" patternUnits="userSpaceOnUse"><circle cx="7" cy="7" r="2.4" fill="${a}"/></pattern></defs><rect width="600" height="600" fill="url(#p${u})"/>` },
  blob: { name: 'Капля', vb: [300, 300], par: 'xMidYMid meet', d: (a) => `<path d="M150 20C210 18 270 60 276 130 282 200 230 270 160 276 90 282 26 236 22 160 18 84 90 22 150 20Z" fill="${a}"/>` },
}

// графика, у которой части двигаются сами (без анимации слоя)
export const SELF_ANIMATED = new Set(['confetti', 'eq'])

let artSeq = 0
// SVG графики. t задаётся для кадров GIF: тогда движущиеся части рисуются в положении на этот момент
export function artSvg(l: ArtLayer, opts: { t?: number; width?: number; height?: number } = {}): string {
  const A = ART[l.art] ?? ART.sparkle
  const u = 'a' + (++artSeq).toString(36)
  let inner = A.d(safeColor(l.c1), safeColor(l.c2), safeColor(l.c3), u, opts.t)
  if (opts.t !== undefined && l.anim === 'draw') inner = inner.replace(/pathLength="1"/g, `pathLength="1" stroke-dasharray="1 1" stroke-dashoffset="${drawOffset(opts.t, l.delay).toFixed(3)}"`)
  const size = opts.width ? ` width="${opts.width}" height="${opts.height}"` : ''
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${A.vb[0]} ${A.vb[1]}" preserveAspectRatio="${A.par}"${size} aria-hidden="true">${inner}</svg>`
}

// ---------- анимация ----------

const wave = (p: number) => (1 - Math.cos(2 * Math.PI * p)) / 2
const easeOut = (x: number) => 1 - (1 - x) ** 3
const phase = (t: number, period: number, delay: number) => (((t + delay) % period) + period) % period / period

function drawOffset(t: number, delay: number): number {
  const p = phase(t, ZERO_ANIMS.draw.period, delay)
  if (p < 0.45) return 1 - easeOut(p / 0.45)
  if (p < 0.8) return 0
  return -(p - 0.8) / 0.2
}

// состояние анимации слоя в момент t (секунды): повторяет keyframes из ZERO_CSS
export function animAt(anim: ZeroAnim, t: number, delay: number): { tx: number; ty: number; rot: number; sx: number; sy: number; op: number } {
  const s = { tx: 0, ty: 0, rot: 0, sx: 1, sy: 1, op: 1 }
  if (anim === 'none' || anim === 'draw') return s
  const p = phase(t, ZERO_ANIMS[anim].period, delay)
  switch (anim) {
    case 'float': s.ty = -12 * wave(p); break
    case 'sway': s.rot = -3 + 6 * wave(p); break
    case 'wobble': s.rot = -4 + 8 * wave(p); s.sx = s.sy = 1 + 0.04 * wave(p); break
    case 'pulse': s.sx = s.sy = 1 + 0.07 * wave(p); s.op = 0.85 + 0.15 * wave(p); break
    case 'breathe': s.sx = s.sy = 1 + 0.035 * wave(p); break
    case 'spin': s.rot = 360 * p; break
    case 'blink': s.op = 1 - 0.8 * wave(p); break
    case 'scan': s.ty = 128 * wave(p); break
    case 'slideUp': if (p < 0.1) { const e = easeOut(p / 0.1); s.ty = 34 * (1 - e); s.op = e } break
    case 'slideL': if (p < 0.1) { const e = easeOut(p / 0.1); s.tx = -46 * (1 - e); s.op = e } break
    case 'pop': s.sx = s.sy = p < 0.08 ? 0.7 + 0.36 * easeOut(p / 0.08) : p < 0.13 ? 1.06 - 0.06 * ((p - 0.08) / 0.05) : 1; break
    case 'flip': if (p > 0.78) s.sy = Math.max(0.05, Math.abs(Math.cos((p < 0.88 ? (p - 0.78) / 0.1 : 1 - (p - 0.88) / 0.12) * 86 * Math.PI / 180))); break
  }
  return s
}

export const hasMotion = (layers: ZeroLayer[]) => layers.some((l) => !l.hidden && (l.anim !== 'none' || (l.kind === 'art' && SELF_ANIMATED.has(l.art))))

// CSS живого превью: одинаковый в редакторе и в превью макетов (iframe)
export const ZERO_CSS = `
.zf{position:relative;width:${ZERO_W}px;overflow:hidden}
.zl{position:absolute;transform-origin:50% 50%}
.zi{position:relative;width:100%;height:100%}
.zl.t .zi{height:auto;white-space:pre-line;overflow-wrap:break-word}
.zi svg{display:block;width:100%;height:100%;overflow:visible}
.zi img{display:block;width:100%;height:100%;user-select:none;pointer-events:none}
.a-float{animation:z-float 6s ease-in-out infinite}
.a-sway{animation:z-sway 6s ease-in-out infinite}
.a-wobble{animation:z-wobble 3s ease-in-out infinite}
.a-pulse{animation:z-pulse 3s ease-in-out infinite}
.a-breathe{animation:z-breathe 6s ease-in-out infinite}
.a-spin{animation:z-spin 6s linear infinite}
.a-blink{animation:z-blink 1.5s ease-in-out infinite}
.a-slideUp{animation:z-slideUp 6s linear infinite}
.a-slideL{animation:z-slideL 6s linear infinite}
.a-pop{animation:z-pop 6s linear infinite}
.a-flip{animation:z-flip 2s linear infinite}
.a-scan{animation:z-scan 3s ease-in-out infinite}
.a-draw path{stroke-dasharray:1 1;animation:z-draw 6s linear infinite;animation-delay:inherit}
.cf{transform-box:fill-box;transform-origin:center;animation:z-cf ${ZERO_LOOP}s linear infinite}
.eqb{transform-box:fill-box;transform-origin:50% 100%;animation:z-eq 1s ease-in-out infinite alternate}
@keyframes z-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
@keyframes z-sway{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(3deg)}}
@keyframes z-wobble{0%,100%{transform:rotate(-4deg) scale(1)}50%{transform:rotate(4deg) scale(1.04)}}
@keyframes z-pulse{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.07);opacity:1}}
@keyframes z-breathe{0%,100%{transform:scale(1)}50%{transform:scale(1.035)}}
@keyframes z-spin{to{transform:rotate(360deg)}}
@keyframes z-blink{0%,100%{opacity:1}50%{opacity:.2}}
@keyframes z-slideUp{0%{transform:translateY(34px);opacity:0;animation-timing-function:cubic-bezier(.2,.8,.2,1)}10%,100%{transform:none;opacity:1}}
@keyframes z-slideL{0%{transform:translateX(-46px);opacity:0;animation-timing-function:cubic-bezier(.2,.8,.2,1)}10%,100%{transform:none;opacity:1}}
@keyframes z-pop{0%{transform:scale(.7);animation-timing-function:cubic-bezier(.2,.8,.2,1)}8%{transform:scale(1.06)}13%,100%{transform:scale(1)}}
@keyframes z-flip{0%,78%{transform:perspective(320px) rotateX(0)}88%{transform:perspective(320px) rotateX(-86deg)}100%{transform:perspective(320px) rotateX(0)}}
@keyframes z-scan{0%,100%{transform:translateY(0)}50%{transform:translateY(128px)}}
@keyframes z-draw{0%{stroke-dashoffset:1;animation-timing-function:cubic-bezier(.2,.8,.2,1)}45%,80%{stroke-dashoffset:0}100%{stroke-dashoffset:-1}}
@keyframes z-cf{0%{transform:translateY(calc(-1 * var(--y) - 30px)) rotate(0)}100%{transform:translateY(calc(570px - var(--y))) rotate(620deg)}}
@keyframes z-eq{from{transform:scaleY(.25)}to{transform:scaleY(1)}}
.z-still .zi,.z-still .cf,.z-still .eqb,.z-still .zi path{animation:none!important}
@media (prefers-reduced-motion:reduce){.zi,.cf,.eqb,.zi path{animation:none!important}}
`

// шрифты и CSS зеро-блока подключаются один раз, когда блок впервые появляется на экране
let assetsReady = false
export function ensureZeroAssets() {
  if (assetsReady || typeof document === 'undefined') return
  assetsReady = true
  const link = document.createElement('link')
  link.rel = 'stylesheet'
  link.href = FONTS_URL
  document.head.appendChild(link)
  const style = document.createElement('style')
  style.textContent = ZERO_CSS
  document.head.appendChild(style)
}
export const ZERO_HEAD = `<link rel="stylesheet" href="${FONTS_URL}"><style>${ZERO_CSS}</style>`

// ---------- живая разметка слоёв ----------

export type Fill = { fill: string; fill2: string; grad: 'linear' | 'radial'; angle: number }
export const fillCss = (f: Fill) => {
  const a = safeColor(f.fill), b = safeColor(f.fill2)
  if (!b) return a || 'transparent'
  return f.grad === 'radial' ? `radial-gradient(closest-side, ${a}, ${b})` : `linear-gradient(${f.angle}deg, ${a}, ${b})`
}

// стили слоя: внешний контейнер (положение, поворот, наложение) и внутренний (анимация и вид)
export function layerStyles(l: ZeroLayer, z: number): { outer: Record<string, string>; inner: Record<string, string>; cls: string; html: string } {
  const outer: Record<string, string> = {
    left: `${l.x}px`, top: `${l.y}px`, width: `${l.w}px`, height: l.kind === 'text' ? 'auto' : `${l.h}px`,
    opacity: String(l.op), 'z-index': String(z),
  }
  if (l.rot) outer.transform = `rotate(${l.rot}deg)`
  if (l.blend !== 'normal') outer['mix-blend-mode'] = l.blend
  if (l.hidden) outer.display = 'none'
  const inner: Record<string, string> = {}
  if (l.delay) inner['animation-delay'] = `${-l.delay}s`
  let html = ''
  switch (l.kind) {
    case 'rect':
      inner.background = fillCss(l)
      inner['border-radius'] = `${l.radius}px`
      if (l.strokeW && l.stroke) inner.border = `${l.strokeW}px ${l.dashed ? 'dashed' : 'solid'} ${safeColor(l.stroke)}`
      if (l.shadow) inner['box-shadow'] = `${l.shadowX}px ${l.shadowY}px ${l.shadowBlur}px ${safeColor(l.shadow)}`
      break
    case 'text':
      Object.assign(inner, {
        'font-family': fontCss(l.font), 'font-size': `${l.size}px`, 'font-weight': String(l.weight), color: safeColor(l.color), 'text-align': l.align,
        'line-height': String(l.lh), 'letter-spacing': `${l.ls}px`, 'text-transform': l.upper ? 'uppercase' : 'none',
        'font-style': l.italic ? 'italic' : 'normal', 'text-decoration': l.strike ? 'line-through' : 'none',
      })
      html = esc(l.text)
      break
    case 'art':
      if (l.radius) { inner['border-radius'] = `${l.radius}px`; inner.overflow = 'hidden' }
      html = artSvg(l)
      break
    case 'image':
      inner.overflow = 'hidden'
      if (l.radius) inner['border-radius'] = `${l.radius}px`
      html = safeSrc(l.src) ? `<img alt="" draggable="false" src="${esc(safeSrc(l.src))}" style="object-fit:${l.fit === 'contain' ? 'contain' : 'cover'}">` : ''
      break
  }
  const cls = 'zi' + (l.anim !== 'none' ? ` a-${l.anim}` : '')
  return { outer, inner, cls, html }
}
const css = (o: Record<string, string>) => Object.entries(o).map(([k, v]) => `${k}:${v}`).join(';')

export type ZeroCanvas = { height: number; bg: string; bg2: string; bgAngle: number; layers: ZeroLayer[] }
// поля холста в блоке письма называются иначе: bg у блока — его собственный фон
export const canvasOf = (b: { height: number; canvasBg: string; canvasBg2: string; canvasAngle: number; layers: ZeroLayer[] }): ZeroCanvas =>
  ({ height: b.height, bg: b.canvasBg, bg2: b.canvasBg2, bgAngle: b.canvasAngle, layers: b.layers })
export const canvasBg = (z: ZeroCanvas) => fillCss({ fill: z.bg, fill2: z.bg2, grad: 'linear', angle: z.bgAngle })

// разметка блока для превью макетов и iframe: слои как есть, масштаб под ширину
export function zeroLiveHtml(z: ZeroCanvas, width: number): string {
  const k = width / ZERO_W
  const layers = z.layers.map((l, i) => {
    const s = layerStyles(l, i + 1)
    return `<div class="zl${l.kind === 'text' ? ' t' : ''}" style="${css(s.outer)}"><div class="${s.cls}" style="${css(s.inner)}">${s.html}</div></div>`
  }).join('')
  return `<div style="position:relative;width:${width}px;height:${Math.round(z.height * k)}px;overflow:hidden"><div class="zf" style="position:absolute;left:0;top:0;height:${z.height}px;background:${canvasBg(z)};transform:scale(${k});transform-origin:0 0">${layers}</div></div>`
}

// подпись для сравнения: изменилось ли что-то, что видно на картинке
export function zeroHash(z: ZeroCanvas & { motion: boolean }): string {
  const s = JSON.stringify([z.height, z.bg, z.bg2, z.bgAngle, z.motion, z.layers.map((l) => ({ ...l, locked: false, name: '' }))])
  let h = 5381
  for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0
  return (h >>> 0).toString(36) + s.length.toString(36)
}

type ZeroFields = { height: number; canvasBg: string; canvasBg2: string; canvasAngle: number; layers: ZeroLayer[]; motion: boolean; src: string; rendered: string }
export const zeroHashOf = (b: ZeroFields) => zeroHash({ ...canvasOf(b), motion: b.motion })
// нужно ли собрать картинку заново: слои менялись после последней сборки
export const zeroStale = (b: ZeroFields) => !b.src || b.rendered !== zeroHashOf(b)
