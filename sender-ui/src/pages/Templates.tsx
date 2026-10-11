import { useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Folder as FolderIcon, FolderPlus, GripVertical, Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError, type Folder, type Template } from '../api'
import { Empty, Modal, PageHead, ago, useToast } from '../components/ui'

// фильтр: 'all' — все шаблоны, 'none' — без папки, число — id папки
type Filter = 'all' | 'none' | number

export default function Templates() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const raw = params.get('folder')
  const filter: Filter = raw === null ? 'all' : raw === 'none' ? 'none' : Number(raw)

  const q = useQuery({ queryKey: ['templates'], queryFn: () => api<{ data: Template[] }>('/templates') })
  const fq = useQuery({ queryKey: ['template-folders'], queryFn: () => api<{ data: Folder[] }>('/template-folders') })
  const all = q.data?.data ?? []
  const folders = fq.data?.data ?? []
  const folderName = (id: number | null) => folders.find((f) => f.id === id)?.name
  const current = typeof filter === 'number' ? folders.find((f) => f.id === filter) : undefined

  const list = filter === 'all' ? all : filter === 'none' ? all.filter((t) => !t.folder_id) : all.filter((t) => t.folder_id === filter)

  const [editing, setEditing] = useState<{ id?: number; name: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [removing, setRemoving] = useState<Folder | null>(null)

  const refresh = () => { qc.invalidateQueries({ queryKey: ['template-folders'] }); qc.invalidateQueries({ queryKey: ['templates'] }) }
  const save = useMutation({
    mutationFn: (f: { id?: number; name: string }) => api<{ data: Folder }>(f.id ? `/template-folders/${f.id}` : '/template-folders', { method: f.id ? 'PUT' : 'POST', body: { name: f.name } }),
    onSuccess: (r, f) => { setEditing(null); toast(f.id ? 'Папка переименована' : 'Папка создана'); refresh(); if (!f.id) setParams({ folder: String(r.data.id) }) },
    onError: (e) => setError(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const remove = useMutation({
    mutationFn: (f: Folder) => api(`/template-folders/${f.id}`, { method: 'DELETE' }),
    onSuccess: () => { setRemoving(null); toast('Папка удалена'); refresh(); setParams({}) },
  })

  // перетаскивание: шаблон из таблицы бросают на папку слева
  const [dragId, setDragId] = useState<number | null>(null)
  const [dropTarget, setDropTarget] = useState<Filter | null>(null)
  const move = useMutation({
    mutationFn: ({ id, folderId }: { id: number; folderId: number | null }) =>
      api<{ data: Template }>(`/templates/${id}/folder`, { method: 'PATCH', body: { folder_id: folderId } }),
    onMutate: async ({ id, folderId }) => {
      await qc.cancelQueries({ queryKey: ['templates'] })
      const prev = qc.getQueryData<{ data: Template[] }>(['templates'])
      qc.setQueryData<{ data: Template[] }>(['templates'], (old) => old && { data: old.data.map((t) => (t.id === id ? { ...t, folder_id: folderId } : t)) })
      return { prev }
    },
    onError: (_e, _v, ctx) => { if (ctx?.prev) qc.setQueryData(['templates'], ctx.prev); toast('Не удалось перенести письмо', true) },
    onSuccess: (_r, { folderId }) => toast(folderId ? `Перенесён в «${folderName(folderId)}»` : 'Письмо убрано из папки'),
    onSettled: () => refresh(),
  })
  const canDrop = (f: Filter) => {
    if (dragId === null || f === 'all') return false
    const t = all.find((x) => x.id === dragId)
    return (f === 'none' ? null : f) !== (t?.folder_id ?? null)
  }
  const dropProps = (f: Filter) => ({
    onDragOver: (e: React.DragEvent) => { if (canDrop(f)) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setDropTarget(f) } },
    onDragLeave: () => setDropTarget((d) => (d === f ? null : d)),
    onDrop: (e: React.DragEvent) => {
      e.preventDefault()
      if (dragId !== null && canDrop(f)) move.mutate({ id: dragId, folderId: f === 'none' ? null : (f as number) })
      setDragId(null); setDropTarget(null)
    },
  })

  const select = (f: Filter) => setParams(f === 'all' ? {} : { folder: String(f) })
  const newTemplate = () => nav(typeof filter === 'number' ? `/templates/new?folder=${filter}` : '/templates/new')
  const submit = (e: FormEvent) => { e.preventDefault(); if (editing) save.mutate(editing) }

  const item = (f: Filter, label: string, count: number) => (
    <button type="button" className={`folder-item${filter === f ? ' active' : ''}${dropTarget === f ? ' drop' : ''}${dragId !== null && canDrop(f) ? ' droppable' : ''}`} onClick={() => select(f)} {...dropProps(f)}>
      <FolderIcon size={16} /><span className="grow">{label}</span><span className="sub">{count}</span>
    </button>
  )

  return (
    <main className="page">
      <PageHead title={current ? current.name : 'Контент'} sub={current ? 'Письма проекта' : 'Письма для рассылок по базам и для отправки из приложения через API'}
        actions={<>
          {current && <button className="btn" onClick={() => { setError(null); setEditing({ id: current.id, name: current.name }) }}><Pencil size={15} />Переименовать</button>}
          {current && <button className="btn danger" onClick={() => setRemoving(current)}><Trash2 size={15} />Удалить папку</button>}
          <button className="btn primary" onClick={newTemplate}><Plus size={16} />Новое письмо</button>
        </>} />

      <div className="folders-layout">
        <nav className="folders" aria-label="Папки контента">
          {dragId !== null && <div className="hint" style={{ padding: '0 12px 6px' }}>Отпустите на папке</div>}
          {item('all', 'Весь контент', all.length)}
          {folders.map((f) => <div key={f.id}>{item(f.id, f.name, f.templates_count)}</div>)}
          {item('none', 'Без папки', all.filter((t) => !t.folder_id).length)}
          <button type="button" className="btn sm text" style={{ justifyContent: 'flex-start', marginTop: 6 }} onClick={() => { setError(null); setEditing({ name: '' }) }}><FolderPlus size={15} />Новая папка</button>
        </nav>

        <div style={{ minWidth: 0 }}>
          {q.isSuccess && list.length === 0 ? (
            <Empty title={filter === 'all' ? 'Писем пока нет' : 'В этой папке пусто'} text="Создайте письмо: оно появится здесь и его можно будет выбрать в рассылке."
              action={<button className="btn primary" onClick={newTemplate}><Plus size={16} />Новое письмо</button>} />
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead><tr><th style={{ width: 28 }} aria-label="Перетащить" /><th>Название</th><th>Ключ</th>{filter === 'all' && <th>Папка</th>}<th>Тема письма</th><th>Изменён</th><th /></tr></thead>
                <tbody>
                  {list.map((t) => (
                    <tr key={t.id} className={`click${dragId === t.id ? ' dragging' : ''}`} onClick={() => nav(`/templates/${t.id}`)}
                      draggable onDragStart={(e) => { setDragId(t.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', t.name) }}
                      onDragEnd={() => { setDragId(null); setDropTarget(null) }}
                      title="Перетащите на папку слева, чтобы перенести">
                      <td className="drag-handle"><GripVertical size={16} /></td>
                      <td style={{ fontWeight: 500 }}>{t.name}</td>
                      <td><span className="chip mono">{t.slug}</span></td>
                      {filter === 'all' && <td className="sub">{folderName(t.folder_id) ?? '—'}</td>}
                      <td className="muted" style={{ maxWidth: 360 }}>{t.subject}</td>
                      <td className="sub">{ago(t.updated_at)}</td>
                      <td className="r"><ChevronRight size={16} className="muted" /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {editing && (
        <Modal title={editing.id ? 'Переименовать папку' : 'Новая папка'} onClose={() => setEditing(null)}>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="folder-name">Название проекта</label>
              <input id="folder-name" className={`input${error ? ' err' : ''}`} value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} placeholder="Сложный пациент" autoFocus />
              {error && <div className="hint err">{error}</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setEditing(null)}>Отмена</button>
              <button className="btn primary" disabled={save.isPending || !editing.name.trim()}>{save.isPending ? 'Сохраняем…' : 'Сохранить'}</button>
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
