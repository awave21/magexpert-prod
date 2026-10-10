import { useEffect, useState } from 'react'
import { api } from '../api'

// Вход через Яндекс ID и VK ID: обычные ссылки, браузер уходит на сайт провайдера и возвращается в Sender.
// Провайдер без ключей на сервере не показывается.
const base = () => (window.location.pathname.startsWith('/sender') ? '/sender/' : '/')

export function SocialButtons({ divider }: { divider: string }) {
  const [providers, setProviders] = useState<string[]>([])

  useEffect(() => {
    api<{ data: string[] }>('/oauth/providers').then((r) => setProviders(r.data)).catch(() => setProviders([]))
  }, [])

  if (!providers.length) return null

  return (
    <>
    <div className="divider">{divider}</div>
    <div className="social-btns" style={providers.length === 1 ? { gridTemplateColumns: '1fr' } : undefined}>
      {providers.includes('yandex') && (
        <a className="btn lg" href={`${base()}oauth/yandex/redirect`}>
          <span className="social-ic" style={{ background: '#FC3F1D', borderRadius: '50%' }}>Я</span>Яндекс ID
        </a>
      )}
      {providers.includes('vkid') && (
        <a className="btn lg" href={`${base()}oauth/vkid/redirect`}>
          <span className="social-ic" style={{ background: '#0077FF', borderRadius: 6, fontSize: 10 }}>VK</span>ВКонтакте
        </a>
      )}
    </div>
    </>
  )
}
