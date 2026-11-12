import { normalizeDesign, type Block, type Design } from './model'
import { htmlToDesign } from './import'
import { designToHtml } from './render'

const marker = /<!--magsender-design:([A-Za-z0-9+/=]+)-->/
export function readEventDesign(html: string): Design {
  const document = new DOMParser().parseFromString(html, 'text/html')
  const metadata = document.querySelector<HTMLTemplateElement>('template[data-magsender-design]')
  if (metadata) {
    try { return normalizeDesign(JSON.parse(metadata.content.querySelector('[data-design-json]')?.textContent || '')) } catch { /* Import the visible HTML if metadata is invalid. */ }
    metadata.remove()
    html = document.body.innerHTML
  }
  const saved = html.match(marker)
  if (saved) {
    try { return normalizeDesign(JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(saved[1]), c => c.charCodeAt(0))))) } catch { /* Fall back to importing the visible description. */ }
  }
  const design = htmlToDesign(html).design
  return { ...design, settings: { ...design.settings, width: 600, outerBg: '#EEF1F6', buttonBg: '#f27b63' } }
}
export function writeEventContent(design: Design): string {
  const doc = new DOMParser().parseFromString(designToHtml(design), 'text/html')
  const table = doc.querySelector('.container') as HTMLElement
  if (!table) throw new Error('Не удалось собрать описание')
  table.style.width = '100%'
  let css = doc.querySelector('style')?.textContent || ''
  css = css.replace(/body\{[^}]*\}/g, '').replace(/table\{/g, '.magsender-event-content table{').replace(/img\{/g, '.magsender-event-content img{')
  for (const name of ['container', 'col', 'px', 'hide-mobile', 'hide-desktop']) {
    for (const element of [table, ...Array.from(table.querySelectorAll(`[class~="${name}"]`))]) { if (element.classList.contains(name)) element.classList.replace(name, `mse-${name}`) }
    css = css.replace(new RegExp(`\\.${name}(?=[{,\\s])`, 'g'), `.mse-${name}`)
  }
  const markup = table.outerHTML
  const escape = (value: string) => value.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
  const sources = new Set<string>()
  const collect = (blocks: Block[]) => { for (const block of blocks) { if (block.type === 'zero') for (const layer of block.layers) if (layer.kind === 'image' && layer.src) sources.add(layer.src); if (block.type === 'columns') block.columns.forEach(collect) } }
  collect(design.blocks)
  const images = [...sources].map(src => `<img src="${escape(src)}" alt="">`).join('')
  return `<div class="magsender-event-content"><style>${css}</style>${markup}</div><template data-magsender-design><span data-design-json>${escape(JSON.stringify(design))}</span>${images}</template>`
}
