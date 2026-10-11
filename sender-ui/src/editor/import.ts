// Преобразование готового HTML письма в блоки редактора.
// Разбираем то, что понимают почтовые программы: заголовки, абзацы, списки, картинки,
// кнопки-ссылки, разделители. Вёрстку таблицами раскрываем, неизвестное оставляем блоком «Свой HTML».
import { DEFAULT_SETTINGS, createBlock, type Block, type ButtonBlock, type Design, type HeadingBlock, type HtmlBlock, type ImageBlock, type TextBlock } from './model'

const INLINE = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'DEL', 'A', 'BR', 'SPAN', 'FONT', 'SUB', 'SUP', 'SMALL', 'CODE'])
const CONTAINERS = new Set(['TABLE', 'TBODY', 'THEAD', 'TFOOT', 'TR', 'TD', 'TH', 'DIV', 'CENTER', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'MAIN', 'BODY', 'FORM'])
const SKIP = new Set(['STYLE', 'SCRIPT', 'HEAD', 'TITLE', 'META', 'LINK'])

// {{#if x}}<p>…</p>{{/if}} вокруг целого элемента превращаем в условие блока
function markConditions(html: string): string {
  const re = /\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}\s*(<(p|h[1-6]|ul|ol|table|div|a|img|hr)\b[^>]*>(?:[\s\S]*?<\/\3>)?)\s*\{\{\/if\}\}/g
  let prev = ''
  let out = html
  while (prev !== out) {
    prev = out
    out = out.replace(re, (_m, cond: string, el: string, tag: string) => el.replace(new RegExp(`^<${tag}`, 'i'), `<${tag} data-cond="${cond}"`))
  }
  return out
}

// разметка текста: оставляем только то, что умеет редактор текста
function cleanInline(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return (node.textContent ?? '').replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]!)
  if (node.nodeType !== Node.ELEMENT_NODE) return ''
  const el = node as HTMLElement
  const inner = [...el.childNodes].map(cleanInline).join('')
  const style = el.getAttribute('style') ?? ''
  const weight = /font-weight\s*:\s*(bold|[6-9]00)/i.test(style)
  const italic = /font-style\s*:\s*italic/i.test(style)
  const color = style.match(/(?:^|;)\s*color\s*:\s*(#[0-9a-f]{3,6}|rgb\([^)]+\))/i)?.[1]
  switch (el.tagName) {
    case 'B': case 'STRONG': return `<strong>${inner}</strong>`
    case 'I': case 'EM': return `<em>${inner}</em>`
    case 'U': return `<u>${inner}</u>`
    case 'S': case 'STRIKE': case 'DEL': return `<s>${inner}</s>`
    case 'BR': return '<br>'
    case 'A': {
      const href = el.getAttribute('href') ?? ''
      return href ? `<a href="${href.replace(/"/g, '&quot;')}">${inner}</a>` : inner
    }
    case 'LI': return `<li><p>${inner}</p></li>`
    case 'UL': case 'OL': return `<${el.tagName.toLowerCase()}>${[...el.children].map(cleanInline).join('')}</${el.tagName.toLowerCase()}>`
    default: {
      let r = inner
      if (weight) r = `<strong>${r}</strong>`
      if (italic) r = `<em>${r}</em>`
      if (color && !/^#?(000|000000|1c1f27)$/i.test(color)) r = `<span style="color: ${color}">${r}</span>`
      return r
    }
  }
}

const hasText = (el: Element) => (el.textContent ?? '').trim().length > 0

// ссылка, свёрстанная как кнопка: фон, рамка или отступы, либо одна ссылка в ячейке с фоном
function asButton(el: HTMLElement): ButtonBlock | null {
  const a = el.tagName === 'A' ? el : el.querySelector('a')
  if (!a || !hasText(a)) return null
  const aStyle = a.getAttribute('style') ?? ''
  const cell = a.closest('td')
  const cellBg = cell?.getAttribute('bgcolor') || cell?.getAttribute('style')?.match(/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})/i)?.[1]
  const ownBg = aStyle.match(/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})/i)?.[1]
  const looksLike = !!ownBg || /padding\s*:/.test(aStyle) && /display\s*:\s*(inline-)?block/i.test(aStyle) || (cellBg && cell && cell.textContent?.trim() === a.textContent?.trim()) || /\bbtn|button\b/i.test(a.className)
  if (!looksLike) return null
  const b = createBlock('button') as ButtonBlock
  b.text = (a.textContent ?? '').trim()
  b.href = a.getAttribute('href') ?? ''
  const bg = ownBg ?? cellBg
  if (bg) b.fill = bg.toUpperCase()
  const color = aStyle.match(/(?:^|;)\s*color\s*:\s*(#[0-9a-f]{3,6})/i)?.[1]
  if (color) b.color = color.toUpperCase()
  const align = (el.closest('[align]')?.getAttribute('align') ?? '').toLowerCase()
  b.align = align === 'left' || align === 'right' ? align : 'center'
  return b
}

function asImage(img: HTMLImageElement): ImageBlock {
  const b = createBlock('image') as ImageBlock
  b.src = img.getAttribute('src') ?? ''
  b.alt = img.getAttribute('alt') ?? ''
  b.href = img.closest('a')?.getAttribute('href') ?? ''
  const w = Number(img.getAttribute('width')) || Number((img.getAttribute('style') ?? '').match(/(?:^|;)\s*width\s*:\s*(\d+)px/)?.[1])
  if (w && w < 520) { b.full = false; b.width = w }
  const align = (img.closest('[align]')?.getAttribute('align') ?? '').toLowerCase()
  b.align = align === 'left' || align === 'right' ? align : 'center'
  return b
}

export function htmlToDesign(html: string): { design: Design; converted: number; raw: number } {
  const doc = new DOMParser().parseFromString(markConditions(html), 'text/html')
  doc.querySelectorAll('div[style*="display:none"], div[style*="display: none"]').forEach((e) => e.remove())

  const blocks: Block[] = []
  let raw = 0
  let buffer = ''
  let bufferCond = ''

  const flush = () => {
    const html = buffer.trim()
    if (html && html.replace(/<[^>]+>/g, '').trim()) {
      const t = createBlock('text') as TextBlock
      t.html = html
      t.cond = bufferCond
      blocks.push(t)
    }
    buffer = ''
    bufferCond = ''
  }
  const push = (b: Block, cond = '') => { flush(); b.cond = cond; blocks.push(b) }

  const walk = (node: Node, cond: string) => {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = (node.textContent ?? '').trim()
      if (text) { if (bufferCond !== cond) flush(); bufferCond = cond; buffer += `<p>${cleanInline(node)}</p>` }
      return
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return
    const el = node as HTMLElement
    const tag = el.tagName
    if (SKIP.has(tag)) return
    const ownCond = el.getAttribute('data-cond') ?? cond

    if (/^H[1-6]$/.test(tag)) {
      if (!hasText(el)) return
      const h = createBlock('heading') as HeadingBlock
      h.html = `<p>${[...el.childNodes].map(cleanInline).join('')}</p>`
      h.size = tag === 'H1' ? null : tag === 'H2' ? 22 : 18
      const align = (el.getAttribute('align') ?? el.style.textAlign ?? '').toLowerCase()
      if (align === 'center' || align === 'right') h.align = align
      push(h, ownCond)
      return
    }
    if (tag === 'HR') { push(createBlock('divider'), ownCond); return }
    if (tag === 'IMG') { const src = el.getAttribute('src'); if (src) push(asImage(el as HTMLImageElement), ownCond); return }

    // кнопка: ссылка с фоном или ячейка таблицы с единственной ссылкой
    if (tag === 'A' || tag === 'TABLE' || tag === 'TD' || tag === 'P' || tag === 'DIV' || tag === 'CENTER') {
      const onlyLink = el.querySelectorAll('a').length === 1 && !el.querySelector('img') && el.textContent?.trim() === el.querySelector('a')?.textContent?.trim()
      if (tag === 'A' || onlyLink) {
        const b = asButton(el)
        if (b) { push(b, ownCond); return }
      }
    }
    if (tag === 'A' && el.querySelector('img') && !hasText(el)) { const img = el.querySelector('img')!; push(asImage(img), ownCond); return }

    if (tag === 'P' || tag === 'UL' || tag === 'OL' || tag === 'BLOCKQUOTE') {
      // картинки внутри абзаца выносим отдельными блоками
      if (el.querySelector('img') && !hasText(el)) { el.querySelectorAll('img').forEach((img) => push(asImage(img), ownCond)); return }
      if (!hasText(el)) return
      const part = tag === 'P' || tag === 'BLOCKQUOTE' ? `<p>${[...el.childNodes].map(cleanInline).join('')}</p>` : cleanInline(el)
      if (bufferCond !== ownCond) flush()
      bufferCond = ownCond
      buffer += part
      // абзац с условием — всегда отдельный блок
      if (ownCond) flush()
      return
    }
    if (INLINE.has(tag)) {
      if (!hasText(el) && tag !== 'BR') return
      if (bufferCond !== ownCond) flush()
      bufferCond = ownCond
      buffer += `<p>${cleanInline(el)}</p>`
      return
    }
    if (CONTAINERS.has(tag)) {
      // строка таблицы — граница блока: текст из разных строк не склеиваем
      const boundary = tag === 'TR' || tag === 'TD' || tag === 'TH'
      if (boundary) flush()
      el.childNodes.forEach((c) => walk(c, ownCond))
      if (boundary) flush()
      return
    }

    // всё остальное без потерь: блоком «Свой HTML»
    if (!hasText(el) && !el.querySelector('img')) return
    const h = createBlock('html') as HtmlBlock
    h.html = el.outerHTML.replace(/\sdata-cond="[^"]*"/g, '')
    raw += 1
    push(h, ownCond)
  }

  doc.body.childNodes.forEach((n) => walk(n, ''))
  flush()

  return { design: { version: 1, settings: { ...DEFAULT_SETTINGS }, blocks }, converted: blocks.length - raw, raw }
}
