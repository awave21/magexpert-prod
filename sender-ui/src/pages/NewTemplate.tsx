import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Template } from '../api'

const stamp = () => new Date().toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })

// Новое письмо сразу создаётся черновиком: у него есть ID и алиас draft-ID, дальше его собирают на странице письма.
export default function NewTemplate() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const started = useRef(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (started.current) return
    started.current = true
    const name = `Новый шаблон ${stamp()}`
    api<{ data: Template }>('/templates', {
      method: 'POST',
      body: { name, subject: name, body_html: '<p>Здравствуйте, {{ name }}!</p>\n<p>Текст письма.</p>\n', folder_id: Number(params.get('folder')) || null },
    })
      .then((r) => {
        qc.invalidateQueries({ queryKey: ['templates'] })
        qc.invalidateQueries({ queryKey: ['template-folders'] })
        nav(`/templates/${r.data.id}`, { replace: true })
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : 'Не удалось создать письмо'))
  }, [nav, params, qc])

  return <main className="page muted">{error ? <div className="callout err"><div><b>{error}</b></div></div> : 'Создаём письмо…'}</main>
}
