import { Link } from 'react-router-dom'
import { PlayCircle } from 'lucide-react'
import { findVideo } from './videos'

// ссылка из шапки раздела на его видеоинструкцию
export function GuideLink({ slug }: { slug: string }) {
  const video = findVideo(slug)
  if (!video) return null
  return (
    <Link to={`/help#${slug}`} className="guide-link" title={video.title}>
      <PlayCircle size={14} />Смотреть видео<span className="guide-link-time">{video.duration}</span>
    </Link>
  )
}
