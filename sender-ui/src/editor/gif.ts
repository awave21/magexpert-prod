// Кодировщик анимированного GIF для зеро-блока: общая палитра на 256 цветов (median cut),
// сжатие LZW и только изменившиеся области кадров, чтобы файл оставался лёгким для почты.

type Frame = { data: Uint8ClampedArray; delay: number }

// ---------- палитра ----------

function buildPalette(frames: Uint8ClampedArray[], samples = 90000): Uint8Array {
  const total = frames.reduce((n, f) => n + f.length / 4, 0)
  const step = Math.max(1, Math.floor(total / samples))
  const pts: number[] = []
  let i = 0
  for (const f of frames) {
    for (let p = 0; p < f.length; p += 4) {
      if (i++ % step === 0) pts.push(f[p], f[p + 1], f[p + 2])
    }
  }
  const all = new Uint8Array(pts)
  type Box = { idx: Uint32Array }
  let boxes: Box[] = [{ idx: Uint32Array.from({ length: all.length / 3 }, (_, k) => k) }]
  const range = (b: Box) => {
    const mn = [255, 255, 255], mx = [0, 0, 0]
    for (const k of b.idx) for (let c = 0; c < 3; c++) { const v = all[k * 3 + c]; if (v < mn[c]) mn[c] = v; if (v > mx[c]) mx[c] = v }
    const r = [mx[0] - mn[0], mx[1] - mn[1], mx[2] - mn[2]]
    const ch = r[0] >= r[1] && r[0] >= r[2] ? 0 : r[1] >= r[2] ? 1 : 2
    return { ch, size: r[ch] }
  }
  while (boxes.length < 256) {
    let best = -1, bestSize = 0, bestCh = 0
    boxes.forEach((b, k) => {
      if (b.idx.length < 2) return
      const r = range(b)
      if (r.size * Math.log(b.idx.length + 1) > bestSize) { bestSize = r.size * Math.log(b.idx.length + 1); best = k; bestCh = r.ch }
    })
    if (best < 0 || bestSize === 0) break
    const b = boxes[best]
    const sorted = Array.from(b.idx).sort((x, y) => all[x * 3 + bestCh] - all[y * 3 + bestCh])
    const mid = sorted.length >> 1
    boxes = [...boxes.slice(0, best), { idx: Uint32Array.from(sorted.slice(0, mid)) }, { idx: Uint32Array.from(sorted.slice(mid)) }, ...boxes.slice(best + 1)]
  }
  const pal = new Uint8Array(768)
  boxes.forEach((b, k) => {
    let r = 0, g = 0, bl = 0
    for (const x of b.idx) { r += all[x * 3]; g += all[x * 3 + 1]; bl += all[x * 3 + 2] }
    const n = Math.max(1, b.idx.length)
    pal[k * 3] = Math.round(r / n); pal[k * 3 + 1] = Math.round(g / n); pal[k * 3 + 2] = Math.round(bl / n)
  })
  return pal
}

function indexer(pal: Uint8Array) {
  const cache = new Int16Array(1 << 18).fill(-1)
  return (r: number, g: number, b: number) => {
    const key = ((r >> 2) << 12) | ((g >> 2) << 6) | (b >> 2)
    const hit = cache[key]
    if (hit >= 0) return hit
    let best = 0, bd = Infinity
    for (let k = 0; k < 256; k++) {
      const dr = pal[k * 3] - r, dg = pal[k * 3 + 1] - g, db = pal[k * 3 + 2] - b
      const d = dr * dr * 2 + dg * dg * 4 + db * db * 3
      if (d < bd) { bd = d; best = k }
    }
    cache[key] = best
    return best
  }
}

// ---------- LZW ----------

function lzw(indices: Uint8Array, out: number[]) {
  const minCode = 8, clear = 256, eoi = 257
  let codeSize = minCode + 1, next = eoi + 1
  let table = new Map<number, number>()
  const bytes: number[] = []
  let cur = 0, bits = 0
  const emit = (code: number) => {
    cur |= code << bits
    bits += codeSize
    while (bits >= 8) { bytes.push(cur & 255); cur >>>= 8; bits -= 8 }
  }
  emit(clear)
  let prefix = indices[0]
  for (let i = 1; i < indices.length; i++) {
    const k = indices[i]
    const key = (prefix << 8) | k
    const found = table.get(key)
    if (found !== undefined) { prefix = found; continue }
    emit(prefix)
    if (next === 4096) {
      emit(clear)
      table = new Map()
      codeSize = minCode + 1
      next = eoi + 1
    } else {
      if (next >= 1 << codeSize) codeSize++
      table.set(key, next++)
    }
    prefix = k
  }
  emit(prefix)
  emit(eoi)
  if (bits > 0) bytes.push(cur & 255)
  out.push(minCode)
  for (let i = 0; i < bytes.length; i += 255) {
    const chunk = bytes.slice(i, i + 255)
    out.push(chunk.length, ...chunk)
  }
  out.push(0)
}

// ---------- файл ----------

const u16 = (out: number[], v: number) => out.push(v & 255, (v >> 8) & 255)

export async function encodeGif(frames: Frame[], width: number, height: number, onProgress?: (p: number) => void): Promise<Blob> {
  const pal = buildPalette(frames.filter((_, i) => i % 6 === 0).map((f) => f.data))
  const toIndex = indexer(pal)
  const out: number[] = []
  for (const c of 'GIF89a') out.push(c.charCodeAt(0))
  u16(out, width); u16(out, height)
  out.push(0xf7, 0, 0)
  for (const v of pal) out.push(v)
  // бесконечный повтор
  out.push(0x21, 0xff, 0x0b, ...[...'NETSCAPE2.0'].map((c) => c.charCodeAt(0)), 0x03, 0x01, 0, 0, 0)

  let prev: Uint8Array | null = null
  const parts: BlobPart[] = []
  for (let f = 0; f < frames.length; f++) {
    const src = frames[f].data
    const idx = new Uint8Array(width * height)
    for (let p = 0, q = 0; q < idx.length; p += 4, q++) idx[q] = toIndex(src[p], src[p + 1], src[p + 2])
    // только прямоугольник, где кадр отличается от предыдущего
    let x0 = 0, y0 = 0, x1 = width - 1, y1 = height - 1
    if (prev) {
      x0 = width; y0 = height; x1 = -1; y1 = -1
      for (let y = 0; y < height; y++) {
        const row = y * width
        for (let x = 0; x < width; x++) {
          if (idx[row + x] !== prev[row + x]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y }
        }
      }
      if (x1 < 0) { x0 = 0; y0 = 0; x1 = 0; y1 = 0 }
    }
    const w = x1 - x0 + 1, h = y1 - y0 + 1
    const sub = new Uint8Array(w * h)
    for (let y = 0; y < h; y++) sub.set(idx.subarray((y0 + y) * width + x0, (y0 + y) * width + x0 + w), y * w)
    // управление кадром: не стирать предыдущий, задержка в сотых секунды
    out.push(0x21, 0xf9, 0x04, 0x04)
    u16(out, frames[f].delay)
    out.push(0, 0)
    out.push(0x2c)
    u16(out, x0); u16(out, y0); u16(out, w); u16(out, h)
    out.push(0)
    lzw(sub, out)
    prev = idx
    // крупный массив чисел периодически сбрасываем в Blob, чтобы не держать всё в памяти
    if (out.length > 4_000_000) { parts.push(new Uint8Array(out)); out.length = 0 }
    onProgress?.((f + 1) / frames.length)
    if (f % 4 === 3) await new Promise((r) => setTimeout(r, 0))
  }
  out.push(0x3b)
  parts.push(new Uint8Array(out))
  return new Blob(parts, { type: 'image/gif' })
}
