// Сборка HTML письма из блоков: таблицы и встроенные стили, чтобы письмо одинаково
// выглядело в Gmail, Mail.ru, Яндекс Почте и Outlook. Переменные {{ x }} остаются как есть,
// их подставляет сервер при отправке.
import type { Block, Design, Padding, Settings } from './model'

const attr = (v: string) => v.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
const text = (v: string) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const padCss = (p: Padding) => `padding:${p.t}px ${p.r}px ${p.b}px ${p.l}px`
const showClass = (b: Block) => (b.show === 'desktop' ? ' hide-mobile' : b.show === 'mobile' ? ' hide-desktop' : '')

// стили для разметки из редактора текста: почта не понимает внешние CSS
export function inlineRichText(html: string, s: Settings, opts: { color: string; size: number; align: string; margin?: number }): string {
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html')
  const root = doc.body.firstElementChild as HTMLElement
  const margin = opts.margin ?? Math.round(opts.size * 0.75)
  root.querySelectorAll('p').forEach((el) => {
    const own = el.getAttribute('style') ?? ''
    el.setAttribute('style', `margin:0 0 ${margin}px;${own}`)
  })
  root.querySelectorAll('ul,ol').forEach((el) => el.setAttribute('style', `margin:0 0 ${margin}px;padding-left:24px`))
  root.querySelectorAll('li').forEach((el) => el.setAttribute('style', 'margin:0 0 4px'))
  root.querySelectorAll('li > p').forEach((el) => el.setAttribute('style', 'margin:0'))
  root.querySelectorAll('a').forEach((el) => {
    el.setAttribute('style', `color:${s.linkColor};text-decoration:underline`)
    el.setAttribute('target', '_blank')
  })
  // у последнего абзаца нет нижнего отступа
  const last = root.lastElementChild as HTMLElement | null
  if (last && /^(P|UL|OL)$/.test(last.tagName)) last.style.marginBottom = '0'
  return root.innerHTML
}

function cellTop(b: Block, inner: string, extra = '', top = true): string {
  const bg = b.bg ? `background-color:${b.bg};` : ''
  return `<tr><td class="${top ? 'px' : ''}${showClass(b)}" style="${padCss(b.padding)};${bg}${extra}">${inner}</td></tr>`
}

function renderBlock(b: Block, s: Settings, width: number, top = true): string {
  const cell = (bb: Block, inner: string, extra = '') => cellTop(bb, inner, extra, top)
  const contentWidth = Math.max(0, width - b.padding.l - b.padding.r)
  switch (b.type) {
    case 'heading': {
      const size = b.size ?? s.headingSize
      const font = s.headingFont === 'inherit' ? s.font : s.headingFont
      const inner = inlineRichText(b.html, s, { color: b.color ?? s.textColor, size, align: b.align, margin: 6 })
      return cell(b, `<div style="margin:0;font-family:${font};font-size:${size}px;line-height:1.25;font-weight:${s.headingWeight};color:${b.color ?? s.textColor};text-align:${b.align}">${inner}</div>`)
    }
    case 'text': {
      const size = b.size ?? s.fontSize
      const inner = inlineRichText(b.html, s, { color: b.color ?? s.textColor, size, align: b.align })
      return cell(b, `<div style="font-family:${s.font};font-size:${size}px;line-height:${s.lineHeight};color:${b.color ?? s.textColor};text-align:${b.align}">${inner}</div>`)
    }
    case 'footer': {
      const inner = inlineRichText(b.html, s, { color: '#636976', size: 12, align: b.align, margin: 6 })
      return cell(b, `<div style="font-family:${s.font};font-size:12px;line-height:1.55;color:#636976;text-align:${b.align}">${inner}</div>`)
    }
    case 'image': {
      if (!b.src) return ''
      const w = b.full ? contentWidth : Math.min(b.width, contentWidth)
      const img = `<img src="${attr(b.src)}" alt="${attr(b.alt)}" width="${w}" style="display:block;width:100%;max-width:${w}px;height:auto;border:0;outline:none;text-decoration:none;border-radius:${b.radius}px">`
      const linked = b.href ? `<a href="${attr(b.href)}" target="_blank" style="text-decoration:none">${img}</a>` : img
      return cell(b, `<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="${b.align}" style="margin:${b.align === 'center' ? '0 auto' : '0'}"><tr><td>${linked}</td></tr></table>`)
    }
    case 'button': {
      const bg = b.fill ?? s.buttonBg
      const radius = b.radius ?? s.buttonRadius
      const a = `<a href="${attr(b.href)}" target="_blank" style="display:${b.full ? 'block' : 'inline-block'};padding:14px 28px;font-family:${s.font};font-size:15px;font-weight:600;line-height:1.2;color:${b.color};text-decoration:none;text-align:center;border-radius:${radius}px">${text(b.text)}</a>`
      return cell(b, `<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="${b.align}" ${b.full ? 'width="100%"' : ''} style="margin:${b.align === 'center' ? '0 auto' : '0'}"><tr><td align="center" bgcolor="${bg}" style="background-color:${bg};border-radius:${radius}px">${a}</td></tr></table>`)
    }
    case 'divider':
      return cell(b, `<div style="height:${b.thickness}px;line-height:${b.thickness}px;font-size:0;background-color:${b.color}">&nbsp;</div>`)
    case 'spacer':
      return cell(b, `<div style="height:${b.height}px;line-height:${b.height}px;font-size:0">&nbsp;</div>`)
    case 'social': {
      const links = b.links.filter((l) => l.url).map((l) => `<a href="${attr(l.url)}" target="_blank" style="display:inline-block;margin:0 4px 6px;padding:7px 14px;border-radius:999px;background-color:#EDF0F6;color:${s.textColor};font-family:${s.font};font-size:13px;font-weight:600;text-decoration:none">${text(l.label)}</a>`).join('')
      return cell(b, links, `text-align:${b.align}`)
    }
    case 'html':
      return cell(b, b.html)
    case 'columns': {
      const [l, r] = b.ratio === '33-67' ? [33, 67] : b.ratio === '67-33' ? [67, 33] : [50, 50]
      const lw = Math.round((contentWidth - b.gap) * l / 100)
      const rw = contentWidth - b.gap - lw
      const col = (blocks: Block[], w: number, pct: number, padStyle: string) =>
        `<td class="col" width="${pct}%" valign="top" style="width:${w}px;${padStyle}"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${blocks.map((x) => renderBlock(x, s, w, false)).join('')}</table></td>`
      const inner = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${col(b.columns[0], lw, l, `padding-right:${b.gap / 2}px`)}${col(b.columns[1], rw, r, `padding-left:${b.gap / 2}px`)}</tr></table>`
      return cell(b, inner)
    }
  }
}

export function designToHtml(d: Design, title = ''): string {
  const s = d.settings
  const rows = d.blocks.map((b) => renderBlock(b, s, s.width)).join('\n')
  return `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>${text(title)}</title>
<style>
body{margin:0;padding:0;-webkit-text-size-adjust:100%}
table{border-collapse:collapse}
img{-ms-interpolation-mode:bicubic}
.hide-desktop{display:none;max-height:0;overflow:hidden;mso-hide:all}
@media only screen and (max-width:${s.width + 20}px){
  .container{width:100%!important;max-width:100%!important}
  .col{display:block!important;width:100%!important;padding-left:0!important;padding-right:0!important}
  .px{padding-left:20px!important;padding-right:20px!important}
  .hide-mobile{display:none!important}
  .hide-desktop{display:table-cell!important;max-height:none!important;overflow:visible!important}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:${s.outerBg}">
${s.preheader ? `<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all">${text(s.preheader)}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>` : ''}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${s.outerBg}" style="background-color:${s.outerBg}">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" class="container" width="${s.width}" cellpadding="0" cellspacing="0" border="0" style="width:${s.width}px;max-width:${s.width}px;background-color:${s.bodyBg};border-radius:${s.radius}px;overflow:hidden">
${rows}
</table>
</td></tr>
</table>
</body>
</html>`
}

export function htmlToText(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html')
  doc.querySelectorAll('style,title,head').forEach((e) => e.remove())
  doc.querySelectorAll('div[style*="display:none"]').forEach((e) => e.remove())
  doc.querySelectorAll('a').forEach((a) => {
    const href = a.getAttribute('href')
    if (href && href !== a.textContent?.trim()) a.append(` (${href})`)
  })
  doc.querySelectorAll('br').forEach((e) => e.replaceWith('\n'))
  doc.querySelectorAll('p,div,h1,h2,h3,li,tr').forEach((e) => e.append('\n'))
  doc.querySelectorAll('li').forEach((e) => e.prepend('• '))
  return (doc.body.textContent ?? '').replace(/ /g, ' ').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()
}
