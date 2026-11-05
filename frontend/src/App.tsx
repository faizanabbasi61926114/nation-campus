import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import Login from './pages/Login'
import SelectSchool from './pages/SelectSchool'
import Dashboard from './pages/Dashboard'
import Campuses from './pages/Campuses'
import Users from './pages/Users'
import Roles from './pages/Roles'
import Audit from './pages/Audit'
import Settings from './pages/Settings'
import Academics from './pages/Academics'
import Students from './pages/Students'
import Admissions from './pages/Admissions'
import Timetable from './pages/Timetable'
import Attendance from './pages/Attendance'
import Exams from './pages/Exams'
import Fees from './pages/Fees'
import Staff from './pages/Staff'
import Payroll from './pages/Payroll'
import Accounts from './pages/Accounts'
import Announcements from './pages/Announcements'
import Messages from './pages/Messages'
import Reports from './pages/Reports'
import Transport from './pages/Transport'
import FrontDesk from './pages/FrontDesk'
import Library from './pages/Library'
import Hostel from './pages/Hostel'
import Calendar from './pages/Calendar'
import Certificates from './pages/Certificates'
import Discipline from './pages/Discipline'
import MobileShell from './mobile/MobileShell'
import MobileHome from './mobile/ParentHome'
import ChildDetail from './mobile/ChildDetail'
import RollCall from './mobile/RollCall'
import MarksEntry from './mobile/MarksEntry'
import MobileMessages from './mobile/MobileMessages'
import MobileNotices from './mobile/MobileNotices'
import Account from './mobile/Account'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/select-school" element={<SelectSchool />} />
      {/* Phone-first app for families and class teachers; the desktop workspace is untouched. */}
      <Route path="/app" element={<MobileShell />}>
        <Route index element={<MobileHome />} />
        <Route path="child/:id" element={<ChildDetail />} />
        <Route path="attendance" element={<RollCall />} />
        <Route path="marks" element={<MarksEntry />} />
        <Route path="notices" element={<MobileNotices />} />
        <Route path="messages/*" element={<MobileMessages />} />
        <Route path="me" element={<Account />} />
      </Route>
      <Route element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="/campuses" element={<Campuses />} />
        <Route path="/users" element={<Users />} />
        <Route path="/roles" element={<Roles />} />
        <Route path="/audit" element={<Audit />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="/programs" element={<Academics />} />
        <Route path="/students" element={<Students />} />
        <Route path="/admissions" element={<Admissions />} />
        <Route path="/timetable" element={<Timetable />} />
        <Route path="/attendance" element={<Attendance />} />
        <Route path="/exams" element={<Exams />} />
        <Route path="/fees" element={<Fees />} />
        <Route path="/staff" element={<Staff />} />
        <Route path="/hr/leave" element={<Staff initial="leave" />} />
        <Route path="/payroll" element={<Payroll />} />
        <Route path="/accounts" element={<Accounts />} />
        <Route path="/announcements/*" element={<Announcements />} />
        <Route path="/messages/*" element={<Messages />} />
        <Route path="/reports/*" element={<Reports />} />
        <Route path="/transport" element={<Transport />} />
        <Route path="/frontdesk" element={<FrontDesk />} />
        <Route path="/library" element={<Library />} />
        <Route path="/hostel" element={<Hostel />} />
        <Route path="/calendar" element={<Calendar />} />
        <Route path="/certificates" element={<Certificates />} />
        <Route path="/discipline" element={<Discipline />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
