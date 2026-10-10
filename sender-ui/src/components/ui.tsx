import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, Check, Copy, Eye, EyeOff } from 'lucide-react'

const STATUS: Record<string, { label: string; tone: 'ok' | 'warn' | 'err' | 'neutral' }> = {
  delivered: { label: 'Доставлено', tone: 'ok' },
  sent: { label: 'Отправлено', tone: 'ok' },
  bounced: { label: 'Не доставлено', tone: 'err' },
  verified: { label: 'Подтверждён', tone: 'ok' },
  queued: { label: 'В очереди', tone: 'warn' },
  sending: { label: 'Отправляется', tone: 'warn' },
  pending: { label: 'Ожидает', tone: 'warn' },
  failed: { label: 'Ошибка', tone: 'err' },
  blocked: { label: 'Заблокировано', tone: 'neutral' },
}

export function Status({ value }: { value: string }) {
  const s = STATUS[value] ?? { label: value, tone: 'neutral' as const }
  return <span className={`status ${s.tone}`}><i />{s.label}</span>
}
export const statusLabel = (v: string) => STATUS[v]?.label ?? v

export const REASONS: Record<string, string> = {
  bounce: 'Отказ доставки',
  complaint: 'Жалоба',
  unsubscribe: 'Отписка',
  manual: 'Вручную',
}

type Toast = { id: number; text: string; err?: boolean }
const ToastCtx = createContext<(text: string, err?: boolean) => void>(() => {})
export const useToast = () => useContext(ToastCtx)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([])
  const push = useCallback((text: string, err?: boolean) => {
    const id = Date.now() + Math.random()
    setItems((x) => [...x, { id, text, err }])
    setTimeout(() => setItems((x) => x.filter((t) => t.id !== id)), 3500)
  }, [])
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toasts" role="status" aria-live="polite">
        {items.map((t) => <div key={t.id} className={`toast${t.err ? ' err' : ''}`}>{t.text}</div>)}
      </div>
    </ToastCtx.Provider>
  )
}

export function CopyButton({ text, label = 'Скопировать' }: { text: string; label?: string }) {
  const [done, setDone] = useState(false)
  const toast = useToast()
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setDone(true)
      setTimeout(() => setDone(false), 1500)
    } catch {
      toast('Не удалось скопировать, выделите текст вручную', true)
    }
  }
  return (
    <button type="button" className="btn icon sm" onClick={copy} aria-label={label} title={label}>
      {done ? <Check size={15} /> : <Copy size={15} />}
    </button>
  )
}

export function CopyField({ value }: { value: string }) {
  return (
    <div className="copyfield">
      <span>{value}</span>
      <CopyButton text={value} />
    </div>
  )
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="modal-bg" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={title}>
        <h2>{title}</h2>
        {children}
      </div>
    </div>
  )
}

export function BackLink({ to, children }: { to: string; children: ReactNode }) {
  return <Link to={to} className="back"><ArrowLeft size={15} />{children}</Link>
}

export function PageHead({ title, sub, actions }: { title: string; sub?: string; actions?: ReactNode }) {
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {sub && <p>{sub}</p>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </div>
  )
}

export function Empty({ title, text, action }: { title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="empty">
      <b>{title}</b>
      {text && <div>{text}</div>}
      {action && <div style={{ marginTop: 16 }}>{action}</div>}
    </div>
  )
}

export function ago(iso: string | null): string {
  if (!iso) return '—'
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return 'только что'
  if (s < 3600) return `${Math.floor(s / 60)} мин назад`
  if (s < 86400) return `${Math.floor(s / 3600)} ч назад`
  return new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}

export function fmtDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'
}

// поле пароля с кнопкой «Показать пароль»
export function PasswordInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
  const [shown, setShown] = useState(false)
  return (
    <div className="pw">
      <input {...props} type={shown ? 'text' : 'password'} />
      <button type="button" className="pw-eye" onClick={() => setShown((s) => !s)}
        aria-label={shown ? 'Скрыть пароль' : 'Показать пароль'} title={shown ? 'Скрыть пароль' : 'Показать пароль'} aria-pressed={shown}>
        {shown ? <EyeOff size={18} /> : <Eye size={18} />}
      </button>
    </div>
  )
}

// статус рассылки: черновик, идёт отправка (пока есть письма в очереди) или отправлена
export function CampaignStatus({ campaign }: { campaign: { status: string; stats: { queued: number; sent: number; failed: number; blocked: number; bounced?: number } | null } }) {
  const s = campaign.stats
  if (campaign.status === 'draft') return <span className="status neutral"><i />Черновик</span>
  if (campaign.status === 'sending' || (s?.queued ?? 0) > 0) return <span className="status warn"><i />Отправляется</span>
  const missed = (s?.failed ?? 0) + (s?.blocked ?? 0) + (s?.bounced ?? 0)
  if (missed > 0 && (s?.sent ?? 0) === 0) return <span className="status err"><i />Не отправлена</span>
  if (missed > 0) return <span className="status warn"><i />Отправлена с ошибками</span>
  return <span className="status ok"><i />Отправлена</span>
}
