import { useEffect, useState, useSyncExternalStore, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Folder as FolderIcon, FolderPlus, Inbox, Layers } from 'lucide-react'
import { api, ApiError, type Folder, type Template } from '../api'
import { Modal, useToast } from './ui'
import { usePermissions } from '../auth'

// фильтр контента: 'all' — все письма, 'none' — без папки, число — id папки
export type Filter = 'all' | 'none' | number

// тип данных при перетаскивании письма из таблицы на папку
export const TEMPLATE_DRAG = 'application/x-sender-template'

// колонку папок можно скрыть; выбор запоминается в браузере
const SUBNAV_KEY = 'sender.subnav'
const subnavListeners = new Set<() => void>()
const readSubnavHidden = () => { try { return localStorage.getItem(SUBNAV_KEY) === 'hidden' } catch { return false } }
let subnavHidden = readSubnavHidden()

export function useSubnavHidden(): [boolean, (hidden: boolean) => void] {
  const hidden = useSyncExternalStore((cb) => { subnavListeners.add(cb); return () => { subnavListeners.delete(cb) } }, () => subnavHidden)
  const set = (h: boolean) => {
    subnavHidden = h
    try { localStorage.setItem(SUBNAV_KEY, h ? 'hidden' : 'shown') } catch { /* необязательно */ }
    subnavListeners.forEach((cb) => cb())
  }
  return [hidden, set]
}

export function useFolderFilter(): Filter {
  const raw = new URLSearchParams(useLocation().search).get('folder')
  return raw === null ? 'all' : raw === 'none' ? 'none' : Number(raw)
}

export function useContentData() {
  const q = useQuery({ queryKey: ['templates'], queryFn: () => api<{ data: Template[] }>('/templates') })
  const fq = useQuery({ queryKey: ['template-folders'], queryFn: () => api<{ data: Folder[] }>('/template-folders') })
  return { q, all: q.data?.data ?? [], folders: fq.data?.data ?? [] }
}

/**
 * Папки контента. На компьютере — колонка рядом с меню (variant="menu"),
 * на телефоне — строка чипсов над таблицей (variant="tabs").
 * На любую папку можно бросить письмо из таблицы.
 */
export function FolderNav({ variant, onNavigate }: { variant: 'menu' | 'tabs'; onNavigate?: () => void }) {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { canEditContent } = usePermissions()
  const loc = useLocation()
  const onList = loc.pathname.replace(/\/$/, '') === '/templates'
  const filter = useFolderFilter()
  const { all, folders } = useContentData()

  const refresh = () => { qc.invalidateQueries({ queryKey: ['template-folders'] }); qc.invalidateQueries({ queryKey: ['templates'] }) }
  const go = (f: Filter) => { nav(f === 'all' ? '/templates' : `/templates?folder=${f}`); onNavigate?.() }

  // перетаскивание: пока письмо тянут, папки подсвечены
  const [dragging, setDragging] = useState(false)
  const [dropTarget, setDropTarget] = useState<Filter | null>(null)
  useEffect(() => {
    const start = (e: DragEvent) => { if (e.dataTransfer?.types.includes(TEMPLATE_DRAG)) setDragging(true) }
    const end = () => { setDragging(false); setDropTarget(null) }
    document.addEventListener('dragstart', start)
    document.addEventListener('dragend', end)
    document.addEventListener('drop', end)
    return () => { document.removeEventListener('dragstart', start); document.removeEventListener('dragend', end); document.removeEventListener('drop', end) }
  }, [])

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
    onSuccess: (_r, { folderId }) => toast(folderId ? `Перенесён в «${folders.find((f) => f.id === folderId)?.name}»` : 'Письмо убрано из папки'),
    onSettled: () => refresh(),
  })
  const dropProps = (f: Filter) => f === 'all' || !canEditContent ? {} : {
    onDragOver: (e: React.DragEvent) => { if (e.dataTransfer.types.includes(TEMPLATE_DRAG)) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setDropTarget(f) } },
    onDragLeave: () => setDropTarget((d) => (d === f ? null : d)),
    onDrop: (e: React.DragEvent) => {
      e.preventDefault()
      const id = Number(e.dataTransfer.getData(TEMPLATE_DRAG))
      const folderId = f === 'none' ? null : (f as number)
      const t = all.find((x) => x.id === id)
      if (t && (t.folder_id ?? null) !== folderId) move.mutate({ id, folderId })
      setDragging(false); setDropTarget(null)
    },
  }

  const [creating, setCreating] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const create = useMutation({
    mutationFn: (name: string) => api<{ data: Folder }>('/template-folders', { method: 'POST', body: { name } }),
    onSuccess: (r) => { setCreating(null); toast('Папка создана'); refresh(); go(r.data.id) },
    onError: (e) => setError(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'Ошибка'),
  })
  const submit = (e: FormEvent) => { e.preventDefault(); if (creating !== null) create.mutate(creating) }

  const entries: { f: Filter; label: string; count: number; icon: React.ReactNode }[] = [
    { f: 'all', label: 'Весь контент', count: all.length, icon: <Layers size={15} /> },
    ...folders.map((x) => ({ f: x.id as Filter, label: x.name, count: all.filter((t) => t.folder_id === x.id).length, icon: <FolderIcon size={15} /> })),
    { f: 'none', label: 'Без папки', count: all.filter((t) => !t.folder_id).length, icon: <Inbox size={15} /> },
  ]

  return (
    <>
      <div className={variant === 'menu' ? 'nav-sub' : 'folder-tabs'} role="group" aria-label="Папки контента">
        {entries.map(({ f, label, count, icon }) => (
          <button key={String(f)} type="button" title={label} {...dropProps(f)} onClick={() => go(f)}
            className={`${variant === 'menu' ? 'nav-sub-item' : 'folder-tab'}${onList && filter === f ? ' active' : ''}${dragging && f !== 'all' ? ' droppable' : ''}${dropTarget === f ? ' drop' : ''}`}>
            {icon}<span className="grow">{label}</span><span className="count">{count}</span>
          </button>
        ))}
        {canEditContent && (
          <button type="button" className={variant === 'menu' ? 'nav-sub-item add' : 'folder-tab add'} onClick={() => { setError(null); setCreating('') }}
            title="Новая папка" aria-label="Новая папка">
            <FolderPlus size={15} />{variant === 'menu' && <span className="grow">Новая папка</span>}
          </button>
        )}
      </div>

      {canEditContent && creating !== null && (
        <Modal title="Новая папка" onClose={() => setCreating(null)}>
          <form className="stack" onSubmit={submit} noValidate>
            <div className="field">
              <label htmlFor="new-folder-name">Название проекта</label>
              <input id="new-folder-name" className={`input${error ? ' err' : ''}`} value={creating} onChange={(e) => setCreating(e.target.value)} placeholder="Мероприятия" autoFocus />
              {error && <div className="hint err">{error}</div>}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn" onClick={() => setCreating(null)}>Отмена</button>
              <button className="btn primary" disabled={create.isPending || !creating.trim()}>{create.isPending ? 'Сохраняем…' : 'Создать'}</button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}
