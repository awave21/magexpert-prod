// Вход через Яндекс ID и VK ID: обычные ссылки, браузер уходит на сайт провайдера и возвращается в Sender
const base = () => (window.location.pathname.startsWith('/sender') ? '/sender/' : '/')

export function SocialButtons() {
  return (
    <div className="social-btns">
      <a className="btn lg" href={`${base()}oauth/yandex/redirect`}>
        <span className="social-ic" style={{ background: '#FC3F1D', borderRadius: '50%' }}>Я</span>Яндекс ID
      </a>
      <a className="btn lg" href={`${base()}oauth/vkid/redirect`}>
        <span className="social-ic" style={{ background: '#0077FF', borderRadius: 6, fontSize: 10 }}>VK</span>ВКонтакте
      </a>
    </div>
  )
}
