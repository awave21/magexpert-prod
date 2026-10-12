// Сборка зеро-блока в картинку на canvas: те же слои, что видны в редакторе, рисуются
// по одному с поворотом, прозрачностью и режимом наложения. Результат загружается на сервер
// как обычная картинка письма (см. prepareZero).
import { uploadImage } from './Inspector'
import type { Block, Design, ZeroBlock } from './model'
import {
  ZERO_W, animAt, artSvg, ensureZeroAssets, fontCss, safeColor, safeSrc, zeroAlt, zeroHashOf, zeroStale, canvasOf,
  type ArtLayer, type ImageLayer, type RectLayer, type TextLayer, type ZeroCanvas, type ZeroLayer,
} from './zero'

type Ctx = CanvasRenderingContext2D & { letterSpacing?: string }

const blendOp = (b: string): GlobalCompositeOperation => (b === 'normal' ? 'source-over' : b) as GlobalCompositeOperation

function loadImage(src: string, cors: boolean): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    if (cors) img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('Не удалось загрузить фото слоя для картинки письма'))
    img.src = src
  })
}

async function svgImage(svg: string): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }))
  try { return await loadImage(url, false) } finally { setTimeout(() => URL.revokeObjectURL(url), 0) }
}

// ---------- текст: те же переносы, что в редакторе ----------

const fontOf = (l: TextLayer) => `${l.italic ? 'italic ' : ''}${l.weight} ${l.size}px ${fontCss(l.font)}`

export function textLines(ctx: Ctx, l: TextLayer): string[] {
  ctx.font = fontOf(l)
  if ('letterSpacing' in ctx) ctx.letterSpacing = `${l.ls}px`
  const source = l.upper ? l.text.toUpperCase() : l.text
  const lines: string[] = []
  for (const para of source.split('\n')) {
    const words = para.split(/(\s+)/)
    let line = ''
    for (const w of words) {
      const next = line + w
      if (line.trim() && ctx.measureText(next.trimEnd()).width > l.w + 0.5) {
        lines.push(line.trimEnd())
        line = w.trimStart()
      } else line = next
    }
    lines.push(line.trimEnd())
  }
  return lines
}
const textHeight = (ctx: Ctx, l: TextLayer) => textLines(ctx, l).length * l.size * l.lh

function drawText(ctx: Ctx, l: TextLayer, w: number, h: number) {
  const lines = textLines(ctx, l)
  const lh = l.size * l.lh
  const m = ctx.measureText('Hg')
  const asc = m.fontBoundingBoxAscent || l.size * 0.8
  const desc = m.fontBoundingBoxDescent || l.size * 0.2
  ctx.fillStyle = safeColor(l.color) || '#000'
  ctx.textAlign = l.align
  ctx.textBaseline = 'alphabetic'
  const x = l.align === 'center' ? w / 2 : l.align === 'right' ? w : 0
  lines.forEach((line, i) => {
    const y = -h / 2 + i * lh + (lh - (asc + desc)) / 2 + asc
    ctx.fillText(line, x - w / 2, y)
    if (l.strike && line) {
      const lw = ctx.measureText(line).width
      const sx = l.align === 'center' ? x - lw / 2 : l.align === 'right' ? x - lw : x
      ctx.fillRect(sx - w / 2, y - asc * 0.32, lw, Math.max(1, l.size / 14))
    }
  })
}

// ---------- фигуры ----------

function roundRect(ctx: Ctx, x: number, y: number, w: number, h: number, r: number) {
  ctx.beginPath()
  ctx.roundRect(x, y, w, h, Math.max(0, Math.min(r, w / 2, h / 2)))
}

function fillStyle(ctx: Ctx, l: { fill: string; fill2: string; grad: 'linear' | 'radial'; angle: number }, w: number, h: number): string | CanvasGradient {
  const a = safeColor(l.fill) || 'transparent', b = safeColor(l.fill2)
  if (!b) return a
  if (l.grad === 'radial') return a
  // линия градиента как в CSS: угол от верха по часовой стрелке, длина покрывает прямоугольник
  const rad = l.angle * Math.PI / 180, dx = Math.sin(rad), dy = -Math.cos(rad)
  const len = Math.abs(w * dx) + Math.abs(h * dy)
  const g = ctx.createLinearGradient(-dx * len / 2, -dy * len / 2, dx * len / 2, dy * len / 2)
  g.addColorStop(0, a)
  g.addColorStop(1, b)
  return g
}

function drawRect(ctx: Ctx, l: RectLayer, w: number, h: number, scale: number, rot: number) {
  roundRect(ctx, -w / 2, -h / 2, w, h, l.radius)
  if (l.shadow) {
    // смещение тени canvas не поворачивается вместе со слоем, поворачиваем сами
    const r = rot * Math.PI / 180
    ctx.shadowColor = safeColor(l.shadow)
    ctx.shadowOffsetX = (l.shadowX * Math.cos(r) - l.shadowY * Math.sin(r)) * scale
    ctx.shadowOffsetY = (l.shadowX * Math.sin(r) + l.shadowY * Math.cos(r)) * scale
    ctx.shadowBlur = l.shadowBlur * scale
  }
  if (l.fill2 && l.grad === 'radial') {
    // эллипс по размеру фигуры, как radial-gradient(closest-side)
    ctx.save()
    if (l.shadow) { ctx.fillStyle = 'rgba(0,0,0,0)'; ctx.fill() }
    ctx.clip()
    ctx.scale(w / 2, h / 2)
    const g = ctx.createRadialGradient(0, 0, 0, 0, 0, 1)
    g.addColorStop(0, safeColor(l.fill) || 'transparent')
    g.addColorStop(1, safeColor(l.fill2))
    ctx.fillStyle = g
    ctx.fillRect(-1, -1, 2, 2)
    ctx.restore()
  } else {
    ctx.fillStyle = fillStyle(ctx, l, w, h)
    ctx.fill()
  }
  ctx.shadowColor = 'transparent'
  if (l.strokeW && l.stroke) {
    const s = l.strokeW
    roundRect(ctx, -w / 2 + s / 2, -h / 2 + s / 2, w - s, h - s, Math.max(0, l.radius - s / 2))
    ctx.lineWidth = s
    ctx.strokeStyle = safeColor(l.stroke)
    ctx.setLineDash(l.dashed ? [s * 3, s * 2] : [])
    ctx.stroke()
    ctx.setLineDash([])
  }
}

function drawPicture(ctx: Ctx, img: HTMLImageElement, l: ImageLayer, w: number, h: number) {
  ctx.save()
  roundRect(ctx, -w / 2, -h / 2, w, h, l.radius)
  ctx.clip()
  const iw = img.naturalWidth, ih = img.naturalHeight
  const k = l.fit === 'contain' ? Math.min(w / iw, h / ih) : Math.max(w / iw, h / ih)
  ctx.drawImage(img, -iw * k / 2, -ih * k / 2, iw * k, ih * k)
  ctx.restore()
}

// ---------- кадр ----------

async function prepareFonts(layers: ZeroLayer[]) {
  ensureZeroAssets()
  await Promise.all(layers.filter((l): l is TextLayer => l.kind === 'text').map((l) => document.fonts.load(fontOf(l), l.text).catch(() => [])))
  await document.fonts.ready
}

// рисует холст в момент t (секунды); без t — в покое, как в письме без анимации
export async function drawZero(z: ZeroCanvas, scale: number, t?: number, photos = new Map<string, HTMLImageElement>()): Promise<HTMLCanvasElement> {
  await prepareFonts(z.layers)
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(ZERO_W * scale)
  canvas.height = Math.round(z.height * scale)
  const ctx = canvas.getContext('2d') as Ctx
  ctx.scale(scale, scale)

  ctx.save()
  ctx.translate(ZERO_W / 2, z.height / 2)
  ctx.fillStyle = fillStyle(ctx, { fill: z.bg, fill2: z.bg2, grad: 'linear', angle: z.bgAngle }, ZERO_W, z.height)
  ctx.fillRect(-ZERO_W / 2, -z.height / 2, ZERO_W, z.height)
  ctx.restore()

  for (const l of z.layers) {
    if (l.hidden) continue
    const h = l.kind === 'text' ? textHeight(ctx, l) : l.h
    const a = t === undefined ? animAt('none', 0, 0) : animAt(l.anim, t, l.delay)
    const art = l.kind === 'art' ? await svgImage(artSvg(l as ArtLayer, { t, width: Math.ceil(l.w * scale), height: Math.ceil(l.h * scale) })) : null
    let photo: HTMLImageElement | null = null
    if (l.kind === 'image' && safeSrc(l.src)) {
      photo = photos.get(l.src) ?? null
      if (!photo) {
        photo = await loadImage(l.src, !l.src.startsWith('data:'))
        photos.set(l.src, photo)
      }
    }

    ctx.save()
    ctx.translate(l.x + l.w / 2 + a.tx, l.y + h / 2 + a.ty)
    ctx.rotate((l.rot + a.rot) * Math.PI / 180)
    ctx.scale(a.sx, a.sy)
    ctx.globalAlpha = Math.max(0, Math.min(1, l.op * a.op))
    ctx.globalCompositeOperation = blendOp(l.blend)
    if (l.kind === 'rect') drawRect(ctx, l, l.w, h, scale, l.rot + a.rot)
    else if (l.kind === 'text') drawText(ctx, l, l.w, h)
    else if (l.kind === 'art' && art) {
      if (l.radius) { roundRect(ctx, -l.w / 2, -h / 2, l.w, h, l.radius); ctx.clip() }
      ctx.drawImage(art, -l.w / 2, -h / 2, l.w, h)
    } else if (l.kind === 'image' && photo) drawPicture(ctx, photo, l, l.w, h)
    ctx.restore()
  }
  return canvas
}

const toBlob = (c: HTMLCanvasElement, type: string, q?: number) => new Promise<Blob>((resolve, reject) =>
  c.toBlob((b) => (b ? resolve(b) : reject(new Error('Браузер не смог собрать картинку'))), type, q))

// лимит загрузки картинок на сервере
export const UPLOAD_LIMIT = 5 * 1024 * 1024

// картинка для письма: PNG в двойном разрешении, если не влезает в лимит — JPEG
export async function renderZeroFile(z: ZeroCanvas, name: string): Promise<File> {
  let canvas: HTMLCanvasElement
  try {
    canvas = await drawZero(z, 2)
  } catch (e) {
    throw e instanceof Error ? e : new Error('Не удалось собрать картинку')
  }
  let blob: Blob
  try {
    blob = await toBlob(canvas, 'image/png')
  } catch {
    throw new Error('Фото в слоях загружено с другого сайта и не даёт собрать картинку. Загрузите его через кнопку «Фото».')
  }
  if (blob.size > UPLOAD_LIMIT) blob = await toBlob(canvas, 'image/jpeg', 0.9)
  const ext = blob.type === 'image/png' ? 'png' : 'jpg'
  return new File([blob], `${name}.${ext}`, { type: blob.type })
}


// перед сохранением письма: каждый зеро-блок с изменёнными слоями собирается в картинку
// и загружается на сервер. Возвращает тот же design, если собирать нечего.
export async function prepareZero(d: Design, onStep?: (text: string) => void): Promise<Design> {
  const stale = d.blocks.filter((b): b is ZeroBlock => b.type === 'zero' && zeroStale(b))
  if (!stale.length) return d
  const done = new Map<string, Partial<ZeroBlock>>()
  for (const [i, b] of stale.entries()) {
    onStep?.(stale.length > 1 ? `Собираем картинку ${i + 1} из ${stale.length}…` : 'Собираем картинку…')
    const file = await renderZeroFile(canvasOf(b), `zero-${b.id}`)
    const r = await uploadImage(file)
    done.set(b.id, { src: r.url, meta: r.meta, rendered: zeroHashOf(b), alt: b.alt || zeroAlt(b.layers) })
  }
  return { ...d, blocks: d.blocks.map((b) => (done.has(b.id) ? ({ ...b, ...done.get(b.id) } as Block) : b)) }
}
