import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bell, Globe, LayoutDashboard, LogOut, Mail, Menu, Moon, PanelLeftClose, PanelLeftOpen, Plus, ScrollText, Search, ShieldBan, Sun, KeyRound, FileText, Braces, Code2 } from 'lucide-react'
import { api, type Domain } from '../api'
import { useAuth } from '../auth'

const TITLES: Record<string, string> = {
  '/': 'Обзор', '/messages': 'Журнал', '/domains': 'Домены', '/templates': 'Шаблоны', '/variables': 'Переменные', '/api': 'API', '/api-keys': 'API-ключи', '/blocked': 'Блокировки',
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
  // свёрнутое меню (только иконки) запоминается в браузере
  const [collapsed, setCollapsed] = useState(() => {
    try { return localStorage.getItem('sender.sidebar') === 'collapsed' } catch { return false }
  })
  const toggleCollapsed = () => {
    setCollapsed((c) => {
      try { localStorage.setItem('sender.sidebar', c ? 'expanded' : 'collapsed') } catch { /* необязательно */ }
      return !c
    })
  }
  const loc = useLocation()
  const navigate = useNavigate()
  const domains = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const unverified = domains.data?.data.filter((d) => d.status !== 'verified').length ?? 0

  const root = '/' + (loc.pathname.split('/')[1] ?? '')
  const section = TITLES[root] ?? ''
  const sub = loc.pathname.split('/')[2]
  const initial = (user?.name ?? '?').trim().charAt(0).toUpperCase()

  const item = (to: string, icon: React.ReactNode, label: string, badge?: number) => (
    <NavLink to={to} end={to === '/'} className={({ isActive }) => `nav-item${isActive ? ' active' : ''}`} onClick={() => setOpen(false)}
      title={collapsed ? label : undefined} aria-label={collapsed ? label : undefined}>
      {icon}<span className="grow nav-text">{label}</span>{badge ? <span className="badge">{badge}</span> : null}
    </NavLink>
  )

  return (
    <div className={`app${collapsed ? ' collapsed' : ''}`}>
      {open && <div className="scrim" onClick={() => setOpen(false)} />}
      <aside className={`sidebar${open ? ' open' : ''}`}>
        <div className="brand">
          <span className="logo"><Mail size={17} /></span>
          <span className="nav-text brand-name">Sender</span>
          <button className="btn icon sm ghost collapse-btn" onClick={toggleCollapsed} aria-label={collapsed ? 'Развернуть меню' : 'Свернуть меню'} title={collapsed ? 'Развернуть меню' : 'Свернуть меню'}>
            {collapsed ? <PanelLeftOpen size={17} /> : <PanelLeftClose size={17} />}
          </button>
          <button className="btn icon sm ghost close-btn" onClick={() => setOpen(false)} aria-label="Закрыть меню"><PanelLeftClose size={17} /></button>
        </div>
        <Link to="/templates/new" className="btn primary new-btn" onClick={() => setOpen(false)} title={collapsed ? 'Новый шаблон' : undefined} aria-label="Новый шаблон"><Plus size={16} /><span className="nav-text">Новый шаблон</span></Link>
        <div className="nav-title">Работа</div>
        {item('/', <LayoutDashboard size={20} />, 'Обзор')}
        {item('/messages', <ScrollText size={20} />, 'Журнал')}
        <div className="nav-title">Настройка</div>
        {item('/domains', <Globe size={20} />, 'Домены', unverified)}
        {item('/templates', <FileText size={20} />, 'Шаблоны')}
        {item('/variables', <Braces size={20} />, 'Переменные')}
        {item('/api', <Code2 size={20} />, 'API')}
        {item('/api-keys', <KeyRound size={20} />, 'API-ключи')}
        {item('/blocked', <ShieldBan size={20} />, 'Блокировки')}
        <div className="spacer" />
        <div className="help"><b>Нужна помощь?</b>Как подключить домен и отправить первое письмо: раздел «Домены».</div>
        <div className="userrow">
          <span className="avatar" title={collapsed ? `${user?.name} · ${user?.email}` : undefined}>{initial}</span>
          <div className="who"><b>{user?.name}</b><span>{user?.email}</span></div>
          <button className="btn icon sm ghost logout" onClick={logout} aria-label="Выйти" title="Выйти"><LogOut size={16} /></button>
        </div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="btn icon sm ghost menu-btn" onClick={() => setOpen(true)} aria-label="Открыть меню"><Menu size={16} /></button>
          <nav className="crumbs" aria-label="Путь">
            <span>{user?.organization}</span><span className="sep">/</span>
            <b>{section}</b>
            {sub && <><span className="sep">/</span><b>{decodeURIComponent(sub) === 'new' ? 'Новый' : 'Карточка'}</b></>}
          </nav>
          <span className="grow" />
          <div className="search"><Search size={16} /><input className="input" placeholder="Поиск по журналу" aria-label="Поиск по журналу"
            onKeyDown={(e) => { if (e.key === 'Enter') navigate('/messages?q=' + encodeURIComponent(e.currentTarget.value)) }} /></div>
          <button className="btn icon sm ghost" aria-label="Уведомления" title="Уведомления"><Bell size={16} /></button>
          <button className="btn icon sm ghost" onClick={toggle} aria-label="Сменить тему" title="Сменить тему">{theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}</button>
        </header>
        <Outlet />
      </div>
    </div>
  )
}
