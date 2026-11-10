import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import Login from './pages/Login'
import SelectSchool from './pages/SelectSchool'
import MobileShell from './mobile/MobileShell'
import MobileHome from './mobile/ParentHome'
import ChildDetail from './mobile/ChildDetail'
import RollCall from './mobile/RollCall'
import GateDesk from './mobile/GateDesk'
import StaffRegister from './mobile/StaffRegister'
import MarksEntry from './mobile/MarksEntry'
import MobileMessages from './mobile/MobileMessages'
import MobileNotices from './mobile/MobileNotices'
import MobileMore from './mobile/MobileMore'
import Account from './mobile/Account'
import { MODULE_PAGES, phonePathOf, websitePathOf } from './lib/moduleRoutes'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/select-school" element={<SelectSchool />} />
      {/*
        The phone app carries the whole product: its own fast screens for families and class
        teachers, plus every management module mounted from the shared MODULE_PAGES list.
      */}
      <Route path="/app" element={<MobileShell />}>
        <Route index element={<MobileHome />} />
        <Route path="child/:id" element={<ChildDetail />} />
        <Route path="attendance" element={<RollCall />} />
        <Route path="gate" element={<GateDesk />} />
        <Route path="staff-attendance" element={<StaffRegister />} />
        <Route path="marks" element={<MarksEntry />} />
        <Route path="notices" element={<MobileNotices />} />
        <Route path="messages/*" element={<MobileMessages />} />
        <Route path="more" element={<MobileMore />} />
        {MODULE_PAGES.map((m) => <Route key={m.to} path={phonePathOf(m)} element={m.el} />)}
        <Route path="me" element={<Account />} />
      </Route>
      <Route element={<Layout />}>
        {MODULE_PAGES.map((m) => m.to === '/'
          ? <Route key={m.to} index element={m.el} />
          : <Route key={m.to} path={websitePathOf(m)} element={m.el} />)}
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
