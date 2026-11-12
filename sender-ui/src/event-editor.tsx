import { useEffect, useMemo, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import BlockEditor, { type EmbeddedEditor } from './pages/BlockEditor'
import { ToastProvider } from './components/ui'
import { setImageUploader } from './editor/Inspector'
import { readEventDesign, writeEventContent } from './editor/event-content'
import type { Template } from './api'
import './styles.css'
import './event-editor.css'

const origin = location.origin
const client = new QueryClient()
let save: (() => Promise<void>) | null = null
let latestHtml = ''
let receive: ((html: string) => void) | null = null
const uploads = new Map<string, { resolve: (data: { url: string; name: string; meta: string }) => void; reject: (e: Error) => void }>()
function post(data: object) { parent.postMessage({ channel: 'magexpert-description', ...data }, origin) }
setImageUploader(file => new Promise((resolve, reject) => {
  const id = crypto.randomUUID(); uploads.set(id, { resolve, reject });
  post({ type: 'upload', id, file });
  setTimeout(() => { const pending = uploads.get(id); if (pending) { uploads.delete(id); pending.reject(new Error('Превышено время загрузки изображения')) } }, 120000)
}))
function App() {
  const [initial, setInitial] = useState<string | null>(null)
  receive = setInitial
  useEffect(() => { post({ type: 'ready' }) }, [])
  const embedded = useMemo<EmbeddedEditor | null>(() => initial === null ? null : ({
    template: { id: 0, name: 'Описание мероприятия', slug: '', subject: '', body_html: initial, editor: 'blocks', design: readEventDesign(initial) } as Template,
    onChange: design => { latestHtml = writeEventContent(design); post({ type: 'change', html: latestHtml }) },
    registerSave: callback => { save = callback },
  }), [initial])
  return embedded ? <BlockEditor key={initial} embedded={embedded} /> : <div className="be-center muted">Загрузка редактора…</div>
}
window.addEventListener('message', async event => {
  if (event.source !== parent || event.origin !== origin || event.data?.channel !== 'magexpert-description') return
  const data = event.data
  if (data.type === 'init') { latestHtml = data.html || ''; receive?.(latestHtml) }
  if (data.type === 'upload-result') { const pending = uploads.get(data.id); if (pending) { uploads.delete(data.id); if (data.error) pending.reject(new Error(data.error)); else pending.resolve(data.result) } }
  if (data.type === 'flush') {
    try { if (!save) throw new Error('Редактор ещё загружается'); await save(); post({ type: 'flushed', id: data.id, html: latestHtml }) }
    catch (error) { post({ type: 'flushed', id: data.id, error: (error as Error).message }) }
  }
})
createRoot(document.getElementById('root')!).render(<QueryClientProvider client={client}><MemoryRouter><ToastProvider><App /></ToastProvider></MemoryRouter></QueryClientProvider>)
