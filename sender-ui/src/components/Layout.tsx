import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bell, Globe, LayoutDashboard, LogOut, Mail, Menu, Moon, PanelLeftClose, Plus, ScrollText, Search, ShieldBan, Sun, KeyRound, FileText } from 'lucide-react'
import { api, type Domain } from '../api'
import { useAuth } from '../auth'

const TITLES: Record<string, string> = {
  '/': 'Обзор', '/messages': 'Журнал', '/domains': 'Домены', '/templates': 'Шаблоны', '/api-keys': 'API-ключи', '/blocked': 'Блокировки',
}

function useTheme() {
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    try { return (localStorage.getItem('sender.theme') as 'light' | 'dark') ?? 'light' } catch { return 'light' }
  })
  const apply = (t: 'light' | 'dark') => {
    setTheme(t)
    document.documentElement.dataset.theme = t
    try { localStorage.setItem('sender.theme', t) } catch { /* необязательно */ }
  }
  document.documentElement.dataset.theme = theme
  return { theme, toggle: () => apply(theme === 'dark' ? 'light' : 'dark') }
}

export function Layout() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const [open, setOpen] = useState(false)
  const loc = useLocation()
  const navigate = useNavigate()
  const domains = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const unverified = domains.data?.data.filter((d) => d.status !== 'verified').length ?? 0

  const root = '/' + (loc.pathname.split('/')[1] ?? '')
  const section = TITLES[root] ?? ''
  const sub = loc.pathname.split('/')[2]
  const initial = (user?.name ?? '?').trim().charAt(0).toUpperCase()

  const item = (to: string, icon: React.ReactNode, label: string, badge?: number) => (
    <NavLink to={to} end={to === '/'} className={({ isActive }) => `nav-item${isActive ? ' active' : ''}`} onClick={() => setOpen(false)}>
      {icon}<span className="grow">{label}</span>{badge ? <span className="badge">{badge}</span> : null}
    </NavLink>
  )

  return (
    <div className="app">
      <aside className={`sidebar${open ? ' open' : ''}`}>
        <div className="brand">
          <span className="logo"><Mail size={18} /></span>
          <span style={{ flex: 1 }}>Sender</span>
          <button className="btn icon sm text" onClick={() => setOpen(false)} aria-label="Свернуть меню"><PanelLeftClose size={16} /></button>
        </div>
        <Link to="/templates/new" className="btn primary" onClick={() => setOpen(false)}><Plus size={16} />Новый шаблон</Link>
        <div className="nav-title">Работа</div>
        {item('/', <LayoutDashboard size={20} />, 'Обзор')}
        {item('/messages', <ScrollText size={20} />, 'Журнал')}
        <div className="nav-title">Настройка</div>
        {item('/domains', <Globe size={20} />, 'Домены', unverified)}
        {item('/templates', <FileText size={20} />, 'Шаблоны')}
        {item('/api-keys', <KeyRound size={20} />, 'API-ключи')}
        {item('/blocked', <ShieldBan size={20} />, 'Блокировки')}
        <div className="spacer" />
        <div className="help"><b>Нужна помощь?</b>Как подключить домен и отправить первое письмо: раздел «Домены».</div>
        <div className="userrow">
          <span className="avatar">{initial}</span>
          <div className="who"><b>{user?.name}</b><span>{user?.email}</span></div>
          <button className="btn icon sm text" onClick={logout} aria-label="Выйти" title="Выйти"><LogOut size={16} /></button>
        </div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="btn icon sm menu-btn" onClick={() => setOpen(true)} aria-label="Открыть меню"><Menu size={16} /></button>
          <nav className="crumbs" aria-label="Путь">
            <span>{user?.organization}</span><span className="sep">/</span>
            <b>{section}</b>
            {sub && <><span className="sep">/</span><b>{decodeURIComponent(sub) === 'new' ? 'Новый' : 'Карточка'}</b></>}
          </nav>
          <span className="grow" />
          <div className="search"><Search size={16} /><input className="input" placeholder="Поиск по журналу" aria-label="Поиск по журналу"
            onKeyDown={(e) => { if (e.key === 'Enter') navigate('/messages?q=' + encodeURIComponent(e.currentTarget.value)) }} /></div>
          <button className="btn icon sm text" aria-label="Уведомления"><Bell size={16} /></button>
          <button className="btn icon sm text" onClick={toggle} aria-label="Сменить тему">{theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}</button>
        </header>
        <Outlet />
      </div>
    </div>
  )
}
