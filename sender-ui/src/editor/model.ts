// Модель письма из блоков. Хранится в шаблоне как design, из неё собирается HTML (render.ts).

export type Align = 'left' | 'center' | 'right'
export type Show = 'all' | 'desktop' | 'mobile'
export type Padding = { t: number; r: number; b: number; l: number }

// cond: блок попадает в письмо, только если переменная заполнена (пусто — всегда)
// inset: фон блока карточкой с полями письма по бокам, а не на всю ширину
type Base = { id: string; padding: Padding; bg: string; inset: boolean; show: Show; cond: string }

export type HeadingBlock = Base & { type: 'heading'; html: string; align: Align; size: number | null; color: string | null }
export type TextBlock = Base & { type: 'text'; html: string; align: Align; size: number | null; color: string | null }
export type ImageBlock = Base & { type: 'image'; src: string; alt: string; href: string; full: boolean; width: number; align: Align; radius: number; name: string; meta: string }
export type ButtonBlock = Base & { type: 'button'; text: string; href: string; fill: string | null; color: string; radius: number | null; full: boolean; align: Align }
export type DividerBlock = Base & { type: 'divider'; color: string; thickness: number }
export type SpacerBlock = Base & { type: 'spacer'; height: number }
export type ColumnsBlock = Base & { type: 'columns'; ratio: '50-50' | '33-67' | '67-33'; gap: number; columns: [Block[], Block[]] }
export type SocialBlock = Base & { type: 'social'; align: Align; links: { label: string; url: string }[] }
export type FooterBlock = Base & { type: 'footer'; html: string; align: Align }
export type HtmlBlock = Base & { type: 'html'; html: string }

export type Block = HeadingBlock | TextBlock | ImageBlock | ButtonBlock | DividerBlock | SpacerBlock | ColumnsBlock | SocialBlock | FooterBlock | HtmlBlock
export type BlockType = Block['type']

export type Settings = {
  width: number
  bodyBg: string
  outerBg: string
  radius: number
  font: string
  fontSize: number
  lineHeight: number
  textColor: string
  linkColor: string
  headingFont: string
  headingSize: number
  headingWeight: number
  buttonBg: string
  buttonRadius: number
  preheader: string
}

export type Design = { version: 1; settings: Settings; blocks: Block[] }

export const FONTS: { label: string; value: string }[] = [
  { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
  { label: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
  { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
  { label: 'Trebuchet', value: "'Trebuchet MS', Arial, sans-serif" },
  { label: 'Georgia', value: 'Georgia, Times, serif' },
  { label: 'Times', value: "'Times New Roman', Times, serif" },
]

export const DEFAULT_SETTINGS: Settings = {
  width: 600,
  bodyBg: '#FFFFFF',
  outerBg: '#EEF1F6',
  radius: 8,
  font: FONTS[0].value,
  fontSize: 16,
  lineHeight: 1.6,
  textColor: '#1C1F27',
  linkColor: '#3B51D3',
  headingFont: 'inherit',
  headingSize: 26,
  headingWeight: 700,
  buttonBg: '#4C68EB',
  buttonRadius: 10,
  preheader: '',
}

export const uid = () => Math.random().toString(36).slice(2, 10)
const pad = (t: number, r: number, b: number, l: number): Padding => ({ t, r, b, l })
const base = (p: Padding = pad(12, 40, 12, 40)) => ({ id: uid(), padding: p, bg: '', inset: false, show: 'all' as Show, cond: '' })

export const BLOCK_LABELS: Record<BlockType, string> = {
  heading: 'Заголовок', text: 'Текст', image: 'Картинка', button: 'Кнопка', columns: 'Колонки',
  divider: 'Разделитель', spacer: 'Отступ', social: 'Соцсети', footer: 'Подвал', html: 'Свой HTML',
}

export function createBlock(type: BlockType): Block {
  switch (type) {
    case 'heading': return { ...base(pad(20, 40, 8, 40)), type, html: '<p>Заголовок письма</p>', align: 'left', size: null, color: null }
    case 'text': return { ...base(), type, html: '<p>Текст письма. Выделите слова, чтобы сделать их жирными или добавить ссылку.</p>', align: 'left', size: null, color: null }
    case 'image': return { ...base(pad(8, 40, 8, 40)), type, src: '', alt: '', href: '', full: true, width: 300, align: 'center', radius: 8, name: '', meta: '' }
    case 'button': return { ...base(pad(12, 40, 20, 40)), type, text: 'Подробнее', href: 'https://', fill: null, color: '#FFFFFF', radius: null, full: false, align: 'center' }
    case 'divider': return { ...base(pad(8, 40, 8, 40)), type, color: '#DEE1E8', thickness: 1 }
    case 'spacer': return { ...base(pad(0, 0, 0, 0)), type, height: 24 }
    case 'columns': return { ...base(), type, ratio: '50-50', gap: 16, columns: [[nested(createBlock('text'))], [nested(createBlock('text'))]] }
    case 'social': return { ...base(), type, align: 'center', links: [{ label: 'ВКонтакте', url: 'https://vk.com/' }, { label: 'Telegram', url: 'https://t.me/' }] }
    case 'footer': return { ...base(pad(16, 40, 28, 40)), type, align: 'left', html: '<p>Вы получили письмо, потому что зарегистрировались на сайте.</p><p>Название организации, город</p>' }
    case 'html': return { ...base(pad(0, 0, 0, 0)), type, html: '<!-- свой HTML -->\n<p style="margin:0">Свой фрагмент</p>' }
  }
}

// у блоков внутри колонки нет своих боковых отступов: их задаёт сама колонка
export function nested(b: Block): Block {
  return { ...b, padding: { ...b.padding, l: 0, r: 0 } }
}

export function starterDesign(): Design {
  const heading = createBlock('heading') as HeadingBlock
  heading.html = '<p>Здравствуйте, {{ first_name }}!</p>'
  const text = createBlock('text') as TextBlock
  text.html = '<p>Это заготовка письма. Нажмите на любой блок, чтобы изменить его, или перетащите новый блок слева.</p>'
  return { version: 1, settings: { ...DEFAULT_SETTINGS }, blocks: [heading, text, createBlock('button'), createBlock('divider'), createBlock('footer')] }
}

export function emptyDesign(): Design {
  return { version: 1, settings: { ...DEFAULT_SETTINGS }, blocks: [] }
}

// копия блока с новыми id (включая вложенные в колонки)
export function cloneBlock(b: Block): Block {
  const c = JSON.parse(JSON.stringify(b)) as Block
  const reid = (x: Block) => {
    x.id = uid()
    if (x.type === 'columns') x.columns.forEach((col) => col.forEach(reid))
  }
  reid(c)
  return c
}

// контейнер: корень письма или ячейка колонок
export type Container = { parent: string | null; col: 0 | 1 }
export const ROOT: Container = { parent: null, col: 0 }

export function listOf(blocks: Block[], c: Container): Block[] | null {
  if (c.parent === null) return blocks
  const p = findBlock(blocks, c.parent)
  return p && p.type === 'columns' ? p.columns[c.col] : null
}

export function findBlock(blocks: Block[], id: string): Block | null {
  for (const b of blocks) {
    if (b.id === id) return b
    if (b.type === 'columns') for (const col of b.columns) { const f = findBlock(col, id); if (f) return f }
  }
  return null
}

export function locate(blocks: Block[], id: string, c: Container = ROOT): { container: Container; index: number } | null {
  const i = blocks.findIndex((b) => b.id === id)
  if (i >= 0) return { container: c, index: i }
  for (const b of blocks) {
    if (b.type === 'columns') {
      for (const k of [0, 1] as const) {
        const f = locate(b.columns[k], id, { parent: b.id, col: k })
        if (f) return f
      }
    }
  }
  return null
}

// все операции возвращают новое дерево (для истории изменений)
const clone = (blocks: Block[]) => JSON.parse(JSON.stringify(blocks)) as Block[]

export function updateBlock(blocks: Block[], id: string, patch: Partial<Block>): Block[] {
  const next = clone(blocks)
  const b = findBlock(next, id)
  if (b) Object.assign(b, patch)
  return next
}

export function removeBlock(blocks: Block[], id: string): Block[] {
  const next = clone(blocks)
  const loc = locate(next, id)
  if (loc) listOf(next, loc.container)?.splice(loc.index, 1)
  return next
}

export function insertBlock(blocks: Block[], c: Container, index: number, block: Block): Block[] {
  const next = clone(blocks)
  const list = listOf(next, c)
  if (!list) return blocks
  // колонки внутрь колонок не вкладываем
  if (c.parent !== null && block.type === 'columns') return blocks
  list.splice(Math.max(0, Math.min(index, list.length)), 0, c.parent !== null ? nested(block) : block)
  return next
}

export function moveBlockTo(blocks: Block[], id: string, c: Container, index: number): Block[] {
  const loc = locate(blocks, id)
  if (!loc) return blocks
  const block = findBlock(blocks, id)!
  if (c.parent === id) return blocks
  let target = index
  if (loc.container.parent === c.parent && loc.container.col === c.col && loc.index < index) target -= 1
  const without = removeBlock(blocks, id)
  return insertBlock(without, c, target, JSON.parse(JSON.stringify(block)))
}

export function shiftBlock(blocks: Block[], id: string, dir: -1 | 1): Block[] {
  const loc = locate(blocks, id)
  if (!loc) return blocks
  const len = listOf(blocks, loc.container)?.length ?? 0
  const to = loc.index + dir
  if (to < 0 || to >= len) return blocks
  return moveBlockTo(blocks, id, loc.container, dir > 0 ? to + 1 : to)
}

export function countBlocks(blocks: Block[]): number {
  return blocks.reduce((n, b) => n + 1 + (b.type === 'columns' ? countBlocks(b.columns[0]) + countBlocks(b.columns[1]) : 0), 0)
}

// Сервер превращает пустые строки в null. Возвращаем значения по умолчанию,
// чтобы поля ввода и сборка HTML всегда получали строки.
export function normalizeDesign(raw: Partial<Design> | null | undefined): Design {
  const settings = { ...DEFAULT_SETTINGS } as Record<string, unknown>
  for (const [k, v] of Object.entries(raw?.settings ?? {})) if (v !== null && v !== undefined) settings[k] = v
  const fix = (b: Block): Block => {
    const def = createBlock(b.type) as unknown as Record<string, unknown>
    const out = { ...b } as unknown as Record<string, unknown>
    for (const [k, v] of Object.entries(def)) {
      if (out[k] === undefined || (out[k] === null && typeof v === 'string')) out[k] = k === 'id' ? uid() : v
    }
    if (b.type === 'columns') out.columns = [(b.columns?.[0] ?? []).map(fix), (b.columns?.[1] ?? []).map(fix)]
    if (b.type === 'social') out.links = (b.links ?? []).map((l) => ({ label: l.label ?? '', url: l.url ?? '' }))
    return out as unknown as Block
  }
  return { version: 1, settings: settings as Settings, blocks: (raw?.blocks ?? []).map(fix) }
}
