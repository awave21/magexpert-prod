import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth'
import { Layout } from './components/Layout'
import Login from './pages/Login'
import Register from './pages/Register'
import Overview from './pages/Overview'
import Domains from './pages/Domains'
import DomainDetail from './pages/DomainDetail'
import Templates from './pages/Templates'
import TemplateEditor from './pages/TemplateEditor'
import Messages from './pages/Messages'
import ApiKeys from './pages/ApiKeys'
import Blocked from './pages/Blocked'
import VariablesPage from './pages/Variables'
import ApiDocs from './pages/ApiDocs'
// редактор блоков тяжёлый (TipTap), грузится только когда его открывают
const BlockEditor = lazy(() => import('./pages/BlockEditor'))

export default function App() {
  const { user, loading } = useAuth()
  if (loading) return null
  if (!user) {
    return (
      <Routes>
        <Route path="/register" element={<Register />} />
        <Route path="*" element={<Login />} />
      </Routes>
    )
  }
  return (
    <Routes>
      <Route path="templates/:id/blocks" element={<Suspense fallback={<div className="be-center muted">Загрузка редактора…</div>}><BlockEditor /></Suspense>} />
      <Route element={<Layout />}>
        <Route index element={<Overview />} />
        <Route path="messages" element={<Messages />} />
        <Route path="domains" element={<Domains />} />
        <Route path="domains/:id" element={<DomainDetail />} />
        <Route path="templates" element={<Templates />} />
        <Route path="templates/:id" element={<TemplateEditor />} />
        <Route path="variables" element={<VariablesPage />} />
        <Route path="api" element={<ApiDocs />} />
        <Route path="api-keys" element={<ApiKeys />} />
        <Route path="blocked" element={<Blocked />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
