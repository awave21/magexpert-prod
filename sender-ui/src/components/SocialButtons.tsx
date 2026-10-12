import { useEffect, useState } from 'react'
import { api } from '../api'

// Вход через Яндекс ID и VK ID (через него же — Одноклассники и Mail.ru): обычные ссылки, браузер уходит на сайт провайдера и возвращается в Sender.
// Провайдер без ключей на сервере не показывается.
const base = () => (window.location.pathname.startsWith('/sender') ? '/sender/' : '/')

/** Открыта ли самостоятельная регистрация организаций: null — ещё не знаем. */
export function useRegistrationOpen(): boolean | null {
  const [open, setOpen] = useState<boolean | null>(null)

  useEffect(() => {
    api<{ registration?: boolean }>('/oauth/providers').then((r) => setOpen(Boolean(r.registration))).catch(() => setOpen(false))
  }, [])

  return open
}

export function SocialButtons({ divider }: { divider: string }) {
  const [providers, setProviders] = useState<string[]>([])

  useEffect(() => {
    api<{ data: string[] }>('/oauth/providers').then((r) => setProviders(r.data)).catch(() => setProviders([]))
  }, [])

  if (!providers.length) return null

  return (
    <>
    <div className="divider">{divider}</div>
    <div className="social-btns" style={providers.length === 1 && providers[0] === 'yandex' ? { gridTemplateColumns: '1fr' } : undefined}>
      {providers.includes('yandex') && (
        <a className="btn lg" href={`${base()}oauth/yandex/redirect`}>
          <span className="social-ic" style={{ background: '#FC3F1D', borderRadius: '50%' }}>Я</span>Яндекс ID
        </a>
      )}
      {providers.includes('vkid') && (
        <>
        <a className="btn lg" href={`${base()}oauth/vkid/redirect?via=vkid`}>
          <span className="social-ic" style={{ background: '#0077FF', borderRadius: 6, fontSize: 10 }}>VK</span>ВКонтакте
        </a>
        <a className="btn lg" href={`${base()}oauth/vkid/redirect?via=ok_ru`}>
          <span className="social-ic" style={{ background: '#EE8208', borderRadius: 6, fontSize: 10 }}>ОК</span>Одноклассники
        </a>
        <a className="btn lg" href={`${base()}oauth/vkid/redirect?via=mail_ru`}>
          <span className="social-ic" style={{ background: '#005FF9', borderRadius: 6 }}>@</span>Mail.ru
        </a>
        </>
      )}
    </div>
    </>
  )
}
