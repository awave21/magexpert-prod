import type { Role } from '../api'

export const ROLES: Record<Role, { label: string; text: string }> = {
  owner: { label: 'Владелец', text: 'Всё, что администратор, плюс название организации и передача владения' },
  admin: { label: 'Администратор', text: 'Всё, включая домены, адреса отправителей, API-ключи, блокировки и команду' },
  editor: { label: 'Редактор', text: 'Письма, базы и рассылки: может запускать и отменять' },
  designer: { label: 'Дизайнер', text: 'Создаёт и правит письма в «Контенте», остальное только смотрит' },
  viewer: { label: 'Просмотр', text: 'Видит всё, ничего не меняет' },
}

// роли, которые можно выдать в команде: владельцем становятся только передачей владения
export const ASSIGNABLE: Role[] = ['admin', 'editor', 'designer', 'viewer']

export const MATRIX_ROLES: Role[] = ['owner', 'admin', 'editor', 'designer', 'viewer']

export const MATRIX: { what: string; roles: Role[] }[] = [
  { what: 'Видеть рассылки, базы, журнал и статистику', roles: ['owner', 'admin', 'editor', 'designer', 'viewer'] },
  { what: 'Создавать и править письма в «Контенте»', roles: ['owner', 'admin', 'editor', 'designer'] },
  { what: 'Базы: создание, загрузка, удаление, подписчики', roles: ['owner', 'admin', 'editor'] },
  { what: 'Рассылки: создание, запуск, пауза, отмена', roles: ['owner', 'admin', 'editor'] },
  { what: 'Домены, адреса отправителей, API-ключи, вебхуки, блокировки', roles: ['owner', 'admin'] },
  { what: 'Приглашать людей, менять роли, отключать доступ', roles: ['owner', 'admin'] },
  { what: 'Название организации и передача владения', roles: ['owner'] },
]
