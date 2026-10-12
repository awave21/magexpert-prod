import { useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { PageHead } from '../components/ui'
import { VIDEOS, findVideo, posterSrc, videoSrc } from '../help/videos'
import './help.css'

export default function Help() {
  const { hash } = useLocation()
  const navigate = useNavigate()
  // ролик выбирается по адресу /help#slug: на него ведут ссылки «Смотреть видео» из разделов
  const active = findVideo(decodeURIComponent(hash.slice(1))) ?? VIDEOS[0]
  const playerRef = useRef<HTMLDivElement>(null)
  // сам запускается только ролик, выбранный кликом в списке; по ссылке из раздела ждёт нажатия
  const picked = useRef(false)

  const pick = (slug: string) => {
    picked.current = true
    navigate({ hash: slug }, { replace: true })
    const top = playerRef.current?.getBoundingClientRect().top ?? 0
    if (top < 0) playerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  return (
    <main className="page">
      <PageHead title="Видеоинструкции" sub="Короткие ролики по каждому разделу Sender, пояснения в титрах" />
      <div className="help-player" ref={playerRef}>
        <video key={active.slug} src={videoSrc(active.slug)} poster={posterSrc(active.slug)}
          controls playsInline preload="metadata" autoPlay={picked.current} aria-label={active.title} />
        <div className="help-now">
          <h2>{active.title}</h2>
          <p>{active.description}</p>
        </div>
      </div>
      <ul className="help-list" aria-label="Все ролики">
        {VIDEOS.map((v, i) => (
          <li key={v.slug}>
            <button type="button" className={`help-item${v.slug === active.slug ? ' active' : ''}`} onClick={() => pick(v.slug)}
              aria-current={v.slug === active.slug ? 'true' : undefined}>
              <span className="help-thumb">
                <img src={posterSrc(v.slug)} alt="" loading="lazy" />
                <span className="help-time">{v.duration}</span>
              </span>
              <span className="help-item-text">
                <span className="help-num">{String(i + 1).padStart(2, '0')}</span>
                <b>{v.title}</b>
              </span>
            </button>
          </li>
        ))}
      </ul>
    </main>
  )
}
