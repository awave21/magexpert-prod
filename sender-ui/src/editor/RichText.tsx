import { useEffect, useReducer, useRef, useState } from 'react'
import { EditorContent, useEditor, type Editor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { Color, TextStyle } from '@tiptap/extension-text-style'
import { AlignCenter, AlignLeft, AlignRight, Bold, Braces, Italic, Link2, List, ListOrdered, RemoveFormatting, Strikethrough, Underline } from 'lucide-react'
import type { Align } from './model'

// ссылки: обычные адреса, почта, телефон и переменные вида {{ event_url }}
const allowedUri = (url: string) => /^(https?:\/\/|mailto:|tel:|\{\{)/i.test(url.trim())

export const highlightVars = (html: string) =>
  html.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, '<span class="be-var">{{ $1 }}</span>')

type Props = {
  html: string
  onChange: (html: string) => void
  onReady: (editor: Editor | null) => void
  className?: string
  style?: React.CSSProperties
}

// Текст блока: редактируемый, пока блок выбран. Снаружи блок показывает статичный HTML.
export function RichText({ html, onChange, onReady, className, style }: Props) {
  const changeRef = useRef(onChange)
  changeRef.current = onChange
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: false, codeBlock: false, blockquote: false, code: false, horizontalRule: false,
        link: { openOnClick: false, autolink: true, defaultProtocol: 'https', isAllowedUri: (url) => allowedUri(url) },
      }),
      TextStyle,
      Color,
    ],
    content: html,
    autofocus: 'end',
    onUpdate: ({ editor: e }) => changeRef.current(e.getHTML()),
  })

  useEffect(() => {
    onReady(editor)
    return () => onReady(null)
  }, [editor, onReady])

  return <EditorContent editor={editor} className={className} style={style} />
}

type ToolbarProps = {
  editor: Editor | null
  align: Align
  onAlign: (a: Align) => void
  variables: { key: string; label: string }[]
}

// Панель форматирования над письмом: действует на текст выбранного блока
export function FormatToolbar({ editor, align, onAlign, variables }: ToolbarProps) {
  const [, rerender] = useReducer((x: number) => x + 1, 0)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkUrl, setLinkUrl] = useState('')
  const [varsOpen, setVarsOpen] = useState(false)

  useEffect(() => {
    if (!editor) return
    editor.on('transaction', rerender)
    return () => { editor.off('transaction', rerender) }
  }, [editor])

  if (!editor) return null
  const chain = () => editor.chain().focus()
  const btn = (label: string, active: boolean, run: () => void, icon: React.ReactNode) => (
    <button type="button" className={`be-fmt-btn${active ? ' on' : ''}`} title={label} aria-label={label} aria-pressed={active}
      onMouseDown={(e) => e.preventDefault()} onClick={run}>{icon}</button>
  )
  const color = (editor.getAttributes('textStyle').color as string | undefined) ?? '#1C1F27'

  const applyLink = () => {
    const url = linkUrl.trim()
    if (!url) chain().extendMarkRange('link').unsetLink().run()
    else if (allowedUri(url)) chain().extendMarkRange('link').setLink({ href: url }).run()
    setLinkOpen(false)
  }

  return (
    <div className="be-fmt" role="toolbar" aria-label="Форматирование текста">
      {btn('Жирный', editor.isActive('bold'), () => chain().toggleBold().run(), <Bold size={15} strokeWidth={2.4} />)}
      {btn('Курсив', editor.isActive('italic'), () => chain().toggleItalic().run(), <Italic size={15} />)}
      {btn('Подчёркнутый', editor.isActive('underline'), () => chain().toggleUnderline().run(), <Underline size={15} />)}
      {btn('Зачёркнутый', editor.isActive('strike'), () => chain().toggleStrike().run(), <Strikethrough size={15} />)}
      <span className="be-fmt-sep" />
      <div className="be-pop-wrap">
        {btn('Ссылка', editor.isActive('link'), () => { setLinkUrl(editor.getAttributes('link').href ?? ''); setLinkOpen((o) => !o); setVarsOpen(false) }, <Link2 size={15} />)}
        {linkOpen && (
          <form className="be-pop" onSubmit={(e) => { e.preventDefault(); applyLink() }}>
            <input autoFocus className="input sm mono" placeholder="https://… или {{ event_url }}" value={linkUrl} onChange={(e) => setLinkUrl(e.target.value)}
              onKeyDown={(e) => e.key === 'Escape' && setLinkOpen(false)} />
            <button className="btn sm primary">Готово</button>
            {editor.isActive('link') && <button type="button" className="btn sm" onClick={() => { chain().extendMarkRange('link').unsetLink().run(); setLinkOpen(false) }}>Убрать</button>}
            {linkUrl && !allowedUri(linkUrl) && <div className="hint err" style={{ width: '100%' }}>Начните с https://, mailto: или переменной</div>}
          </form>
        )}
      </div>
      {btn('Маркированный список', editor.isActive('bulletList'), () => chain().toggleBulletList().run(), <List size={15} />)}
      {btn('Нумерованный список', editor.isActive('orderedList'), () => chain().toggleOrderedList().run(), <ListOrdered size={15} />)}
      <span className="be-fmt-sep" />
      {btn('По левому краю', align === 'left', () => onAlign('left'), <AlignLeft size={15} />)}
      {btn('По центру', align === 'center', () => onAlign('center'), <AlignCenter size={15} />)}
      {btn('По правому краю', align === 'right', () => onAlign('right'), <AlignRight size={15} />)}
      <span className="be-fmt-sep" />
      <label className="be-fmt-btn" title="Цвет текста" onMouseDown={(e) => e.preventDefault()}>
        <span className="be-fmt-swatch" style={{ background: color }} />
        <input type="color" className="sr" value={/^#[0-9a-f]{6}$/i.test(color) ? color : '#1c1f27'} onChange={(e) => chain().setColor(e.target.value).run()} aria-label="Цвет текста" />
      </label>
      {btn('Убрать оформление', false, () => chain().unsetAllMarks().clearNodes().run(), <RemoveFormatting size={15} />)}
      <span className="be-fmt-sep" />
      <div className="be-pop-wrap">
        <button type="button" className={`be-fmt-btn wide${varsOpen ? ' on' : ''}`} onMouseDown={(e) => e.preventDefault()} onClick={() => { setVarsOpen((o) => !o); setLinkOpen(false) }}>
          <Braces size={14} />Переменная
        </button>
        {varsOpen && (
          <div className="be-pop be-pop-list" onMouseDown={(e) => e.preventDefault()}>
            {variables.map((v) => (
              <button type="button" key={v.key} onClick={() => { chain().insertContent(`{{ ${v.key} }}`).run(); setVarsOpen(false) }}>
                <span className="mono">{v.key}</span><span className="sub">{v.label}</span>
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
