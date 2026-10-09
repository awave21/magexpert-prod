import { Link } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { AuthAside } from './AuthAside'

export default function Register() {
  return (
    <div className="auth">
      <section className="auth-form">
        <div className="auth-card">
          <span className="logo" style={{ width: 40, height: 40, borderRadius: 12 }}><Mail size={20} /></span>
          <div>
            <h1>Создать аккаунт</h1>
            <p style={{ marginTop: 10, fontSize: 15, color: 'var(--ink-2)' }}>Организация создаётся сразу: подключите домен и отправьте первое письмо.</p>
          </div>
          <div className="callout warn">
            <div><b>Регистрация пока закрыта</b><span>Сейчас аккаунты создаёт администратор платформы. Открытая регистрация появится, когда сервис станет доступен другим организациям.</span></div>
          </div>
          <form className="stack" onSubmit={(e) => e.preventDefault()} aria-disabled="true">
            {[
              ['org', 'Название организации', 'text', 'МагЭксперт'],
              ['name', 'Ваше имя', 'text', 'Анна Иванова'],
              ['remail', 'Email', 'email', 'name@company.ru'],
              ['rpass', 'Пароль', 'password', 'Не короче 8 символов'],
            ].map(([id, label, type, ph]) => (
              <div className="field" key={id}>
                <label htmlFor={id}>{label}</label>
                <input id={id} type={type} className="input" placeholder={ph} disabled />
              </div>
            ))}
            <button className="btn primary lg" disabled>Создать аккаунт</button>
          </form>
          <p style={{ color: 'var(--ink-2)' }}>Уже есть аккаунт? <Link to="/" style={{ fontWeight: 500 }}>Войти</Link></p>
        </div>
      </section>
      <AuthAside />
    </div>
  )
}
