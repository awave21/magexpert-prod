import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bell, BookOpen, ChevronLeft, Globe, LayoutDashboard, LogOut, Mail, Menu, Moon, PlayCircle, Plus, ScrollText, Search, ShieldBan, Sun, KeyRound, FileText, Braces, Code2, Send, UserCog, UserRound, Users, X } from 'lucide-react'
import { api, type Domain } from '../api'
import { useAuth, usePermissions } from '../auth'
import { FolderNav, useSubnavHidden } from './FolderNav'
import { SubscribersNav } from './SubscribersNav'

const TITLES: Record<string, string> = {
  '/': 'Обзор', '/campaigns': 'Рассылки', '/subscribers': 'Подписчики', '/messages': 'Журнал', '/domains': 'Домены', '/templates': 'Контент', '/variables': 'Переменные', '/api': 'API', '/api-keys': 'API-ключи', '/blocked': 'Блокировки', '/team': 'Команда', '/profile': 'Профиль', '/settings': 'Настройки', '/help': 'Видеоинструкции',
}

// разделы со второй колонкой
const SUBNAV_SECTIONS = ['/templates', '/subscribers']

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

function useDismiss(open: boolean, ref: React.RefObject<HTMLElement | null>, close: () => void) {
  useEffect(() => {
    if (!open) return
    const onEvent = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === 'Escape' : !ref.current?.contains(e.target as Node)) close()
    }
    document.addEventListener('mousedown', onEvent)
    document.addEventListener('keydown', onEvent)
    return () => { document.removeEventListener('mousedown', onEvent); document.removeEventListener('keydown', onEvent) }
  }, [open, ref, close])
}

export function Layout() {
  const { user, logout } = useAuth()
  const perms = usePermissions()
  const { isAdmin } = usePermissions()
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
  const [subnavHidden, setSubnavHidden] = useSubnavHidden()
  const loc = useLocation()
  // меню пользователя (правый верхний угол) и справки (левый нижний): закрываются кликом мимо и Esc
  const [userMenu, setUserMenu] = useState(false)
  const userMenuRef = useRef<HTMLDivElement>(null)
  useDismiss(userMenu, userMenuRef, () => setUserMenu(false))
  // на телефоне поиск — иконка, по нажатию раскрывается на всю шапку
  const [searching, setSearching] = useState(false)
  const searchRef = useRef<HTMLInputElement>(null)
  useEffect(() => { if (searching) searchRef.current?.focus() }, [searching])
  const [helpMenu, setHelpMenu] = useState(false)
  const helpRef = useRef<HTMLDivElement>(null)
  useDismiss(helpMenu, helpRef, () => setHelpMenu(false))
  const navigate = useNavigate()
  const domains = useQuery({ queryKey: ['domains'], queryFn: () => api<{ data: Domain[] }>('/domains') })
  const unverified = domains.data?.data.filter((d) => d.status !== 'verified').length ?? 0

  const root = '/' + (loc.pathname.split('/')[1] ?? '')
  const section = TITLES[root] ?? ''
  const sub = loc.pathname.split('/')[2]
  const initial = (user?.name ?? '?').trim().charAt(0).toUpperCase()

  // вторая колонка у «Контента» и «Подписчиков»: закреплена в своём разделе или выезжает при наведении на пункт меню
  const onSection = SUBNAV_SECTIONS.includes(root)
  const docked = onSection && !subnavHidden
  const [peek, setPeek] = useState<string | null>(null)
  const shown = docked ? root : peek
  const peekTimer = useRef<number | undefined>(undefined)
  const peekOn = (section: string) => { window.clearTimeout(peekTimer.current); if (docked && section === root) return; peekTimer.current = window.setTimeout(() => setPeek(section), peek ? 0 : 120) }
  const peekKeep = () => window.clearTimeout(peekTimer.current)
  const peekOff = () => { window.clearTimeout(peekTimer.current); peekTimer.current = window.setTimeout(() => setPeek(null), 220) }
  useEffect(() => { setPeek(null) }, [root, loc.search])
  useEffect(() => () => window.clearTimeout(peekTimer.current), [])
  // с клавиатуры колонка открывается фокусом на пункте и закрывается, когда фокус ушёл и с пункта, и с колонки
  const panelRef = useRef<HTMLDivElement>(null)
  const coarse = () => window.matchMedia?.('(pointer: coarse)').matches ?? false
  const focusOutside = (e: React.FocusEvent) => {
    const next = e.relatedTarget as Node | null
    return !next || !(panelRef.current?.contains(next) || (next instanceof Element && next.closest('[data-subnav-item]')))
  }

  const item = (to: string, icon: React.ReactNode, label: string, badge?: number) => (
    <NavLink to={to} end={to === '/'} className={({ isActive }) => `nav-item${isActive ? ' active' : ''}`}
      onClick={() => { setOpen(false); if (SUBNAV_SECTIONS.includes(to)) { window.clearTimeout(peekTimer.current); setSubnavHidden(false); setPeek(null) } }}
      {...(SUBNAV_SECTIONS.includes(to) ? {
        'data-subnav-item': '',
        onMouseEnter: () => peekOn(to), onMouseLeave: peekOff,
        // на сенсорном экране нажатие сразу открывает раздел и закрепляет колонку, фокус её не выдвигает
        onFocus: () => { if (!coarse()) peekOn(to) },
        onBlur: (e: React.FocusEvent) => { if (focusOutside(e)) peekOff() },
        // стрелка вправо переводит фокус в открытую колонку
        onKeyDown: (e: React.KeyboardEvent) => {
          if (e.key !== 'ArrowRight') return
          const first = panelRef.current?.querySelector<HTMLElement>('a, button')
          if (first && shown) { e.preventDefault(); peekKeep(); first.focus() }
        },
      } : {})}
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
          <button className="btn icon sm ghost close-btn" onClick={() => setOpen(false)} aria-label="Закрыть меню"><X size={18} /></button>
        </div>
        {perms.canEdit && <Link to="/campaigns/new" className="btn primary new-btn" onClick={() => setOpen(false)} title={collapsed ? 'Новая рассылка' : undefined} aria-label="Новая рассылка"><Plus size={16} /><span className="nav-text">Новая рассылка</span></Link>}
        <div className="nav-title">Работа</div>
        {item('/', <LayoutDashboard size={20} />, 'Обзор')}
        {item('/campaigns', <Send size={20} />, 'Рассылки')}
        {item('/subscribers', <Users size={20} />, 'Подписчики')}
        {item('/templates', <FileText size={20} />, 'Контент')}
        {item('/messages', <ScrollText size={20} />, 'Журнал')}
        <div className="nav-title">Настройка</div>
        {item('/domains', <Globe size={20} />, 'Домены', unverified)}
        {item('/variables', <Braces size={20} />, 'Переменные')}
        {item('/api', <Code2 size={20} />, 'API')}
        {item('/api-keys', <KeyRound size={20} />, 'API-ключи')}
        {item('/blocked', <ShieldBan size={20} />, 'Блокировки')}
        <div className="spacer" />
        <div className="menu-wrap help-wrap" ref={helpRef}>
          <button type="button" className={`nav-item help-btn${helpMenu ? ' open' : ''}`} onClick={() => setHelpMenu((v) => !v)}
            aria-haspopup="menu" aria-expanded={helpMenu} title={collapsed ? 'Справка' : undefined} aria-label="Справка">
            <BookOpen size={20} /><span className="grow nav-text">Справка</span>
          </button>
          {helpMenu && (
            <div className="menu help-menu" role="menu">
              <button type="button" role="menuitem" onClick={() => { setHelpMenu(false); setOpen(false); navigate('/domains') }}><Globe size={16} />Как подключить домен</button>
              <button type="button" role="menuitem" onClick={() => { setHelpMenu(false); setOpen(false); navigate('/api') }}><Code2 size={16} />Документация API</button>
              <button type="button" role="menuitem" onClick={() => { setHelpMenu(false); setOpen(false); navigate('/help') }}><PlayCircle size={16} />Видеоинструкции</button>
            </div>
          )}
        </div>
      </aside>
      <button type="button" className="edge-toggle sidebar-toggle" onClick={toggleCollapsed} aria-expanded={!collapsed}
        aria-label={collapsed ? 'Развернуть меню' : 'Свернуть меню'} title={collapsed ? 'Развернуть меню' : 'Свернуть меню'}>
        <ChevronLeft size={14} />
      </button>
      <aside className={`subnav${docked ? ' docked' : ''}${peek && peek !== (docked ? root : null) ? ' peek' : ''}`} aria-label={shown === '/subscribers' ? 'Подписчики' : 'Папки контента'}>
        <div className="subnav-panel" ref={panelRef} onMouseEnter={peek ? peekKeep : undefined} onMouseLeave={peek ? peekOff : undefined} inert={!shown}
          onFocus={peek ? peekKeep : undefined} onBlur={peek ? (e) => { if (focusOutside(e)) peekOff() } : undefined}>
          <div className="subnav-head"><span className="subnav-title">{(peek ?? shown) === '/subscribers' ? 'Подписчики' : 'Папки'}</span></div>
          {(peek ?? shown) === '/templates' && <FolderNav variant="menu" onNavigate={() => setPeek(null)} />}
          {(peek ?? shown) === '/subscribers' && <SubscribersNav onNavigate={() => setPeek(null)} />}
        </div>
        {onSection && (
          <button type="button" className="edge-toggle subnav-toggle" data-subnav-item="" onFocus={peek ? peekKeep : undefined} onBlur={peek ? (e) => { if (focusOutside(e)) peekOff() } : undefined} onMouseEnter={peek ? peekKeep : undefined} onMouseLeave={peek ? peekOff : undefined} onClick={() => { setSubnavHidden(docked); setPeek(null) }}
            title={docked ? 'Скрыть колонку' : 'Закрепить колонку'} aria-label={docked ? 'Скрыть колонку' : 'Закрепить колонку'} aria-expanded={docked}>
            <ChevronLeft size={14} />
          </button>
        )}
      </aside>
      <div className="main">
        <header className={`topbar${searching ? ' searching' : ''}`}>
          <button className="btn icon sm ghost menu-btn" onClick={() => setOpen(true)} aria-label="Открыть меню"><Menu size={16} /></button>
          <nav className="crumbs" aria-label="Путь">
            <span className="crumb-org">{user?.organization}</span><span className="sep crumb-org-sep">/</span>
            <span className="crumb-path">
              <b>{section}</b>
              {sub && root !== '/settings' && <><span className="sep">/</span><b>{decodeURIComponent(sub) === 'new' ? (root === '/subscribers' ? 'Новая база' : 'Новый') : 'Карточка'}</b></>}
            </span>
          </nav>
          <span className="grow" />
          <button className="btn icon sm ghost search-btn" onClick={() => setSearching(true)} aria-label="Поиск по журналу" title="Поиск по журналу"><Search size={16} /></button>
          <div className="search"><Search size={16} /><input className="input" placeholder="Поиск по журналу" aria-label="Поиск по журналу" ref={searchRef}
            onBlur={() => setSearching(false)}
            onKeyDown={(e) => {
              if (e.key === 'Escape') { e.currentTarget.blur() }
              if (e.key === 'Enter') { navigate('/messages?q=' + encodeURIComponent(e.currentTarget.value)); e.currentTarget.blur() }
            }} /></div>
          <button className="btn icon sm ghost" aria-label="Уведомления" title="Уведомления"><Bell size={16} /></button>
          <button className="btn icon sm ghost" onClick={toggle} aria-label="Сменить тему" title="Сменить тему">{theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}</button>
          <div className="menu-wrap" ref={userMenuRef}>
            <button type="button" className="user-btn" onClick={() => setUserMenu((v) => !v)} aria-haspopup="menu" aria-expanded={userMenu} aria-label="Профиль" title={`${user?.name} · ${user?.email}`}>
              <span className="avatar">{initial}</span>
            </button>
            {userMenu && (
              <div className="menu user-menu" role="menu">
                <div className="user-menu-who"><b>{user?.name}</b><span>{user?.email}</span></div>
                <button type="button" role="menuitem" onClick={() => { setUserMenu(false); navigate('/settings/profile') }}><UserRound size={16} />Профиль</button>
                {isAdmin && <button type="button" role="menuitem" onClick={() => { setUserMenu(false); navigate('/settings/team') }}><UserCog size={16} />Команда</button>}
                <button type="button" role="menuitem" className="danger" onClick={logout}><LogOut size={16} />Выйти</button>
              </div>
            )}
          </div>
        </header>
        <Outlet />
      </div>
    </div>
  )
}
