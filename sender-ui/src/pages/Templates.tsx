import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Folder as FolderIcon, GripVertical, Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError, type Folder } from '../api'
import { Empty, Modal, PageHead, ago, useToast } from '../components/ui'
import { FolderNav, TEMPLATE_DRAG, useContentData, useFolderFilter, type Filter } from '../components/FolderNav'

export default function Templates() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const filter = useFolderFilter()
  const { q, all, folders } = useContentData()
  const folderName = (id: number | null) => folders.find((f) => f.id === id)?.name
  const current = typeof filter === 'number' ? folders.find((f) => f.id === filter) : undefined

  const list = filter === 'all' ? all : filter === 'none' ? all.filter((t) => !t.folder_id) : all.filter((t) => t.folder_id === filter)

  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [removing, setRemoving] = useState<Folder | null>(null)
  const [dragId, setDragId] = useState<number | null>(null)

  const refresh = () => { qc.invalidateQueries({ queryKey: ['template-folders'] }); qc.invalidateQueries({ queryKey: ['templates'] }) }
  const rename = useMutation({
    mutationFn: (f: { id: number; name: string }) => api<{ data: Folder }>(`/template-folders/${f.id}`, { method: 'PUT', body: { name: f.name } }),
    onSuccess: () => { setEditing(null); toast('Папка переименована'); refresh() },
    onError: (e) => setError(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const remove = useMutation({
    mutationFn: (f: Folder) => api(`/template-folders/${f.id}`, { method: 'DELETE' }),
    onSuccess: () => { setRemoving(null); toast('Папка удалена'); refresh(); nav('/templates') },
  })

  const select = (f: Filter) => nav(f === 'all' ? '/templates' : `/templates?folder=${f}`)
  const newTemplate = () => nav(typeof filter === 'number' ? `/templates/new?folder=${filter}` : '/templates/new')
  const submit = (e: FormEvent) => { e.preventDefault(); if (editing) rename.mutate(editing) }
  const title = current ? current.name : filter === 'none' ? 'Без папки' : 'Контент'

  return (
    <main className="page">
      <PageHead title={title} sub={current ? 'Письма проекта' : 'Письма для рассылок по базам и для отправки из приложения через API'}
        actions={<>
          {current && <button className="btn" onClick={() => { setError(null); setEditing({ id: current.id, name: current.name }) }}><Pencil size={15} />Переименовать</button>}
          {current && <button className="btn danger" onClick={() => setRemoving(current)}><Trash2 size={15} />Удалить папку</button>}
          <button className="btn primary" onClick={newTemplate}><Plus size={16} />Новое письмо</button>
        </>} />

      <FolderNav variant="tabs" />

      {q.isSuccess && list.length === 0 ? (
        <Empty title={filter === 'all' ? 'Писем пока нет' : 'В этой папке пусто'} text="Создайте письмо: оно появится здесь и его можно будет выбрать в рассылке."
          action={<button className="btn primary" onClick={newTemplate}><Plus size={16} />Новое письмо</button>} />
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th style={{ width: 28 }} aria-label="Перетащить" /><th>Название</th><th>Ключ</th><th>Тема письма</th><th>Изменён</th><th /></tr></thead>
            <tbody>
              {list.map((t) => (
                <tr key={t.id} className={`click${dragId === t.id ? ' dragging' : ''}`} onClick={() => nav(`/templates/${t.id}`)}
                  draggable onDragStart={(e) => { setDragId(t.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData(TEMPLATE_DRAG, String(t.id)); e.dataTransfer.setData('text/plain', t.name) }}
                  onDragEnd={() => setDragId(null)}
                  title="Перетащите на папку, чтобы перенести">
                  <td className="drag-handle"><GripVertical size={16} /></td>
                  <td className="tpl-name">
                    <span>{t.name}</span>
                    {filter !== t.folder_id && folderName(t.folder_id) && (
                      <button type="button" className="chip folder-chip" title="Открыть папку" onClick={(e) => { e.stopPropagation(); select(t.folder_id as number) }}>
                        <FolderIcon size={12} />{folderName(t.folder_id)}
                      </button>
                    )}
                  </td>
                  <td><span className="chip mono">{t.slug}</span></td>
                  <td className="muted clip" title={t.subject}>{t.subject}</td>
                  <td className="sub nowrap">{ago(t.updated_at)}</td>
                  <td className="r"><ChevronRight size={16} className="muted" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <Modal title="Переименовать папку" onClose={() => setEditing(null)}>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="folder-name">Название проекта</label>
              <input id="folder-name" className={`input${error ? ' err' : ''}`} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} placeholder="Сложный пациент" autoFocus />
              {error && <div className="hint err">{error}</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setEditing(null)}>Отмена</button>
              <button className="btn primary" disabled={rename.isPending || !editing.name.trim()}>{rename.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
            </div>
          </form>
        </Modal>
      )}

      {removing && (
        <Modal title="Удалить папку?" onClose={() => setRemoving(null)}>
          <p style={{ color: 'var(--ink-2)' }}>Папка «{removing.name}» будет удалена. Письма из неё не удаляются, а переходят в «Без папки».</p>
          <div className="row" style={{ justifyContent: 'flex-end' }}>
            <button className="btn" onClick={() => setRemoving(null)}>Отмена</button>
            <button className="btn danger" onClick={() => remove.mutate(removing)}>Удалить</button>
          </div>
        </Modal>
      )}
    </main>
  )
}
