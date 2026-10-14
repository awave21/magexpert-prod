import { useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Filter, Upload, Users } from 'lucide-react'
import { api, type ContactList, type SavedSegment } from '../api'
import { usePermissions } from '../auth'

const n = (v: number) => v.toLocaleString('ru-RU')

/** Колонка раздела «Подписчики»: базы, сегменты, импорт и экспорт. Сами базы и сегменты здесь не перечисляются: их может быть много. */
export function SubscribersNav({ onNavigate }: { onNavigate?: () => void }) {
  const nav = useNavigate()
  const path = useLocation().pathname.replace(/\/$/, '')
  const q = useQuery({ queryKey: ['lists'], queryFn: () => api<{ data: ContactList[] }>('/lists') })
  const lists = q.data?.data ?? []
  const sq = useQuery({ queryKey: ['segments'], queryFn: () => api<{ data: SavedSegment[] }>('/segments') })
  const segments = sq.data?.data ?? []
  const { canEdit } = usePermissions()
  const go = (to: string) => { nav(to); onNavigate?.() }
  const item = (to: string, icon: React.ReactNode, label: string, count?: number, active = path === to) => (
    <button key={to} type="button" title={label} className={`nav-sub-item${active ? ' active' : ''}`} onClick={() => go(to)}>
      {icon}<span className="grow">{label}</span>{count !== undefined && <span className="count">{n(count)}</span>}
    </button>
  )
  const soon = (icon: React.ReactNode, label: string) => (
    <button type="button" className="nav-sub-item" disabled title="Скоро">{icon}<span className="grow">{label}</span><span className="soon">скоро</span></button>
  )

  return (
    <div className="nav-sub" role="group" aria-label="Подписчики">
      {item('/subscribers', <Users size={15} />, 'Базы', lists.length, path === '/subscribers' || /^\/subscribers\/\d+$/.test(path))}
      {item('/subscribers/segments', <Filter size={15} />, 'Сегменты', segments.length, path.startsWith('/subscribers/segments'))}
      <div className="nav-sub-sep" />
      {canEdit && item('/subscribers/new', <Upload size={15} />, 'Импорт адресов')}
      {soon(<Download size={15} />, 'Экспорт')}
    </div>
  )
}
