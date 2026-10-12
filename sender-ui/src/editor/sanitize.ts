/**
 * Очистка HTML блока или письма перед вставкой в документ редактора.
 * Сохранённый HTML не меняется (его чистит сервер) — это защита страницы админки:
 * убираем скрипты, встраиваемые объекты, обработчики on*, опасные ссылки и выражения в стилях.
 * Переменные {{ name }} в тексте и в href="{{ ... }}" остаются как есть.
 */

const DROP = new Set([
  'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
  'meta', 'link', 'base', 'noscript', 'template', 'portal', 'svg', 'math', 'title', 'head',
])
const URL_ATTRS = new Set(['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster', 'cite', 'longdesc', 'lowsrc', 'dynsrc', 'srcset', 'ping', 'data'])
const SAFE_DATA_IMG = /^data:image\/(png|jpe?g|gif|webp);/i
const BAD_SCHEME = /^(javascript|vbscript|data):/i
const BAD_STYLE = /expression\s*\(|url\s*\(\s*['"]?\s*(javascript|vbscript|data:(?!image\/(png|jpe?g|gif|webp);))|-moz-binding|behavior\s*:/i

// пробелы и управляющие символы браузер игнорирует в схеме: "jav\tascript:" работает
const normalizeUrl = (v: string) => v.replace(/[\u0000- \u007f-\u009f]/g, '').replace(/&#?\w+;?/g, '')

function safeUrl(name: string, value: string, tag: string): boolean {
  const v = normalizeUrl(value)
  if (v.startsWith('{{')) return true
  if (name === 'src' && tag === 'img' && SAFE_DATA_IMG.test(v)) return true
  if (name === 'srcset') return !value.split(',').some((part) => BAD_SCHEME.test(normalizeUrl(part.trim().split(/\s+/)[0] ?? '')))
  return !BAD_SCHEME.test(v)
}

function clean(el: Element) {
  for (const child of Array.from(el.children)) {
    const tag = child.tagName.toLowerCase()
    if (DROP.has(tag)) { child.remove(); continue }
    if (tag === 'style') {
      if (BAD_STYLE.test(child.textContent ?? '') || /@import/i.test(child.textContent ?? '')) child.remove()
      continue
    }
    for (const attr of Array.from(child.attributes)) {
      const name = attr.name.toLowerCase()
      if (name.startsWith('on') || name === 'srcdoc' || name === 'formaction' || name === 'action') { child.removeAttribute(attr.name); continue }
      if (URL_ATTRS.has(name) && !safeUrl(name, attr.value, tag)) { child.removeAttribute(attr.name); continue }
      if (name === 'style' && BAD_STYLE.test(attr.value)) { child.removeAttribute(attr.name); continue }
      if (name === 'http-equiv') child.removeAttribute(attr.name)
    }
    clean(child)
  }
}

export function sanitizeHtml(html: string): string {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(`<!doctype html><html><body>${html}</body></html>`, 'text/html')
  clean(doc.body)
  return doc.body.innerHTML
}
