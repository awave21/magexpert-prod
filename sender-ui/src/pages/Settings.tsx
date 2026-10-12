import { NavLink, Outlet } from 'react-router-dom'
import { Bot, Building2, ScrollText, UserCog, UserRound } from 'lucide-react'
import { useAuth, usePermissions } from '../auth'
import { PageHead } from '../components/ui'
import './settings.css'

export default function Settings() {
  const { user } = useAuth()
  const { isAdmin, isOwner } = usePermissions()

  const tab = (to: string, icon: React.ReactNode, label: string) => (
    <NavLink to={to} className={({ isActive }) => `nav-sub-item${isActive ? ' active' : ''}`}>
      {icon}<span className="grow">{label}</span>
    </NavLink>
  )

  return (
    <main className="page">
      <PageHead title="Настройки" sub={user?.organization ?? undefined} />
      <div className="settings">
        <nav className="nav-sub settings-nav" aria-label="Разделы настроек">
          {tab('/settings/profile', <UserRound size={16} />, 'Профиль')}
          {tab('/settings/ai', <Bot size={16} />, 'Подключение ИИ')}
          {isAdmin && tab('/settings/team', <UserCog size={16} />, 'Команда')}
          {isAdmin && tab('/settings/audit', <ScrollText size={16} />, 'Журнал действий')}
          {isOwner && tab('/settings/organization', <Building2 size={16} />, 'Организация')}
        </nav>
        <div className="settings-body">
          <Outlet />
        </div>
      </div>
    </main>
  )
}
