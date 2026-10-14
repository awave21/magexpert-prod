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
import NewTemplate from './pages/NewTemplate'
import Messages from './pages/Messages'
import ApiKeys from './pages/ApiKeys'
import Blocked from './pages/Blocked'
import VariablesPage from './pages/Variables'
import ApiDocs from './pages/ApiDocs'
import Subscribers from './pages/Subscribers'
import ListDetail from './pages/ListDetail'
import NewList from './pages/NewList'
import Segments from './pages/Segments'
import SegmentEditor from './pages/SegmentEditor'
import SegmentDetail from './pages/SegmentDetail'
import Profile from './pages/Profile'
import SettingsAi from './pages/SettingsAi'
import SettingsApiDocs from './pages/SettingsApiDocs'
import Campaigns from './pages/Campaigns'
import CampaignEditor from './pages/CampaignEditor'
import Privacy from './pages/Privacy'
import Team from './pages/Team'
import Settings from './pages/Settings'
import AuditLog from './pages/AuditLog'
import SettingsOrganization from './pages/SettingsOrganization'
import Help from './pages/Help'
import { RequireRole } from './components/SettingsParts'
// редактор блоков тяжёлый (TipTap), грузится только когда его открывают
const BlockEditor = lazy(() => import('./pages/BlockEditor'))

export default function App() {
  const { user, loading } = useAuth()
  if (loading) return null
  if (!user) {
    return (
      <Routes>
        <Route path="/privacy" element={<Privacy />} />
        <Route path="/register" element={<Register />} />
        <Route path="*" element={<Login />} />
      </Routes>
    )
  }
  return (
    <Routes>
      <Route path="privacy" element={<Privacy />} />
      <Route path="templates/:id/blocks" element={<Suspense fallback={<div className="be-center muted">Загрузка редактора…</div>}><BlockEditor /></Suspense>} />
      <Route element={<Layout />}>
        <Route index element={<Overview />} />
        <Route path="campaigns" element={<Campaigns />} />
        <Route path="campaigns/:id" element={<CampaignEditor />} />
        <Route path="subscribers" element={<Subscribers />} />
        <Route path="subscribers/new" element={<NewList />} />
        <Route path="subscribers/:id" element={<ListDetail />} />
        <Route path="subscribers/segments" element={<Segments />} />
        <Route path="subscribers/segments/new" element={<SegmentEditor />} />
        <Route path="subscribers/segments/:id" element={<SegmentDetail />} />
        <Route path="subscribers/segments/:id/edit" element={<SegmentEditor />} />
        <Route path="messages" element={<Messages />} />
        <Route path="domains" element={<Domains />} />
        <Route path="domains/:id" element={<DomainDetail />} />
        <Route path="templates" element={<Templates />} />
        <Route path="templates/new" element={<NewTemplate />} />
        <Route path="templates/:id" element={<TemplateEditor />} />
        <Route path="variables" element={<VariablesPage />} />
        <Route path="api" element={<ApiDocs />} />
        <Route path="api-keys" element={<ApiKeys />} />
        <Route path="blocked" element={<Blocked />} />
        <Route path="help" element={<Help />} />
        <Route path="settings" element={<Settings />}>
          <Route index element={<Navigate to="profile" replace />} />
          <Route path="profile" element={<Profile />} />
          <Route path="ai" element={<SettingsAi />} />
          <Route path="api-docs" element={<SettingsApiDocs />} />
          <Route path="team" element={<RequireRole need="admin"><Team /></RequireRole>} />
          <Route path="audit" element={<RequireRole need="admin"><AuditLog /></RequireRole>} />
          <Route path="organization" element={<RequireRole need="owner"><SettingsOrganization /></RequireRole>} />
          <Route path="*" element={<Navigate to="profile" replace />} />
        </Route>
        {/* старые адреса */}
        <Route path="team" element={<Navigate to="/settings/team" replace />} />
        <Route path="profile" element={<Navigate to="/settings/profile" replace />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
