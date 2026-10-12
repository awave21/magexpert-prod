import type { ReactNode } from 'react'
import { Lock } from 'lucide-react'
import { usePermissions } from '../auth'

export function SettingsHead({ title, sub, actions }: { title: string; sub?: string; actions?: ReactNode }) {
  return (
    <div className="settings-head">
      <div>
        <h2>{title}</h2>
        {sub && <p>{sub}</p>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </div>
  )
}

export function NoAccess({ who }: { who: 'admin' | 'owner' }) {
  return (
    <div className="settings-noaccess">
      <span className="settings-noaccess-ic"><Lock size={18} /></span>
      <b>Нет доступа</b>
      <span>{who === 'owner' ? 'Этот раздел видит только владелец организации.' : 'Этот раздел видят только владелец и администраторы.'}</span>
    </div>
  )
}

// вкладка настроек только для владельца или администраторов: остальным — спокойное «Нет доступа»
export function RequireRole({ need, children }: { need: 'admin' | 'owner'; children: ReactNode }) {
  const { isAdmin, isOwner } = usePermissions()
  const allowed = need === 'owner' ? isOwner : isAdmin
  return allowed ? <>{children}</> : <NoAccess who={need} />
}
