import type { ReactNode, SVGProps } from 'react'

/**
 * Dependency-free icon set. Every glyph is a 24x24 stroke path that inherits
 * `currentColor`, so an icon recolours with the text or link it sits beside.
 */
function Svg({ children, className = 'h-4 w-4', ...rest }: SVGProps<SVGSVGElement> & { children: ReactNode }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={className}
      {...rest}
    >
      {children}
    </svg>
  )
}

type P = SVGProps<SVGSVGElement>

export const IconDashboard = (p: P) => <Svg {...p}><rect x="3" y="3" width="7.5" height="7.5" rx="1.6" /><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6" /><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6" /><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6" /></Svg>
export const IconReport = (p: P) => <Svg {...p}><path d="M4 20h16" /><rect x="5" y="10" width="3.4" height="7" rx="1" /><rect x="10.3" y="6" width="3.4" height="11" rx="1" /><rect x="15.6" y="13" width="3.4" height="4" rx="1" /></Svg>
export const IconAdmissions = (p: P) => <Svg {...p}><circle cx="9" cy="8" r="3.4" /><path d="M3 20c.6-3.5 3-5.4 6-5.4 1.2 0 2.3.3 3.2.8" /><path d="M17.5 15v6M14.5 18h6" /></Svg>
export const IconStudents = (p: P) => <Svg {...p}><circle cx="8.5" cy="8" r="3" /><path d="M2.8 19.5c.5-3.2 2.7-4.9 5.7-4.9s5.2 1.7 5.7 4.9" /><circle cx="17" cy="9.5" r="2.4" /><path d="M16 14.7c2.7.1 4.6 1.5 5.1 4.1" /></Svg>
export const IconAcademics = (p: P) => <Svg {...p}><path d="M12 6.6C10.2 5 7.7 4.6 4 5.2v13c3.7-.6 6.2-.2 8 1.4 1.8-1.6 4.3-2 8-1.4v-13c-3.7-.6-6.2-.2-8 1.4Z" /><path d="M12 6.6v13" /></Svg>
export const IconTimetable = (p: P) => <Svg {...p}><rect x="3.5" y="5" width="17" height="15" rx="2.2" /><path d="M3.5 10h17M9 5v15M15 5v15" /><path d="M8 3v3M16 3v3" /></Svg>
export const IconAttendance = (p: P) => <Svg {...p}><rect x="3.5" y="4.5" width="17" height="16" rx="2.4" /><path d="M8 3v3M16 3v3M3.5 9.5h17" /><path d="m8.7 14.6 2.1 2.1 4.4-4.4" /></Svg>
export const IconExams = (p: P) => <Svg {...p}><path d="M6 3.5h9.5L19 7v13.5H6Z" /><path d="M15 3.8V7.2h3.3" /><path d="M9 12h7M9 15.6h5" /></Svg>
export const IconFees = (p: P) => <Svg {...p}><rect x="2.8" y="6.5" width="18.4" height="12" rx="2.4" /><path d="M2.8 10.5h18.4" /><circle cx="17" cy="14.5" r="1.2" /></Svg>
export const IconAccounts = (p: P) => <Svg {...p}><path d="M5 3.8h12.5a2 2 0 0 1 2 2v14.4H7.2a2.2 2.2 0 0 1-2.2-2.2Z" /><path d="M9.4 3.8v16.4" /><path d="M13 9h2.6M13 12.6h2.6" /></Svg>
export const IconPayroll = (p: P) => <Svg {...p}><rect x="2.8" y="7.5" width="18.4" height="11" rx="2" /><path d="M7 7.5V5.4a1.6 1.6 0 0 1 1.6-1.6h6.8A1.6 1.6 0 0 1 17 5.4v2.1" /><circle cx="12" cy="13" r="2.2" /></Svg>
export const IconStaff = (p: P) => <Svg {...p}><rect x="3.5" y="5" width="17" height="14" rx="2.4" /><circle cx="10" cy="11" r="2.3" /><path d="M6.4 16.6c.5-1.7 1.9-2.6 3.6-2.6s3.1.9 3.6 2.6" /><path d="M15.6 10h3.2M15.6 13.4h2.2" /></Svg>
export const IconLeave = (p: P) => <Svg {...p}><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></Svg>
export const IconAnnouncements = (p: P) => <Svg {...p}><path d="M4 10.5v3.2a1.6 1.6 0 0 0 1.6 1.6h1.2l7.4 3.6V5.3L6.8 8.9H5.6A1.6 1.6 0 0 0 4 10.5Z" /><path d="M17.6 9.2a4 4 0 0 1 0 5.6" /><path d="M7 15.5v3.2a1.5 1.5 0 0 0 2.2 1.3" /></Svg>
export const IconMessages = (p: P) => <Svg {...p}><rect x="3" y="5" width="18" height="14" rx="2.6" /><path d="m4 7.4 8 5.4 8-5.4" /></Svg>
export const IconCampuses = (p: P) => <Svg {...p}><path d="M4 20.5V7.2L11 4l7 3.2v13.3" /><path d="M2.6 20.5h18.8" /><path d="M9 20.5v-4.8h6v4.8" /><path d="M8.4 9.6h1.8M14 9.6h1.8M8.4 12.8h1.8M14 12.8h1.8" /></Svg>
export const IconUsers = (p: P) => <Svg {...p}><circle cx="12" cy="8.5" r="3.4" /><path d="M5 20.5c.8-4 3.6-6 7-6s6.2 2 7 6" /></Svg>
export const IconRoles = (p: P) => <Svg {...p}><path d="M12 3.2 5 6v6c0 4.2 2.8 7.3 7 8.8 4.2-1.5 7-4.6 7-8.8V6Z" /><path d="m9.2 12.2 2 2 3.6-3.8" /></Svg>
export const IconAudit = (p: P) => <Svg {...p}><path d="M6 3.6h12v16.8H6Z" /><path d="M9 8h6M9 11.6h6M9 15.2h3.6" /></Svg>
export const IconSettings = (p: P) => <Svg {...p}><path d="M4 7h10M18 7h2M4 17h2M10 17h10" /><circle cx="16" cy="7" r="2.3" /><circle cx="8" cy="17" r="2.3" /><path d="M4 12h16" opacity=".35" /></Svg>
export const IconSearch = (p: P) => <Svg {...p}><circle cx="11" cy="11" r="6.4" /><path d="m20 20-4.4-4.4" /></Svg>
export const IconPlus = (p: P) => <Svg {...p}><path d="M12 5v14M5 12h14" /></Svg>
export const IconClose = (p: P) => <Svg {...p}><path d="M6 6 18 18M18 6 6 18" /></Svg>
export const IconChevronLeft = (p: P) => <Svg {...p}><path d="m14.5 6-6 6 6 6" /></Svg>
export const IconChevronRight = (p: P) => <Svg {...p}><path d="m9.5 6 6 6-6 6" /></Svg>
export const IconChevronDown = (p: P) => <Svg {...p}><path d="m6 9.5 6 6 6-6" /></Svg>
export const IconDownload = (p: P) => <Svg {...p}><path d="M12 4v11M7.6 10.6 12 15l4.4-4.4" /><path d="M5 19.5h14" /></Svg>
export const IconEdit = (p: P) => <Svg {...p}><path d="M4.5 19.5h4L19 9a2.5 2.5 0 0 0-3.5-3.5L5 16Z" /><path d="m14.5 6.5 3 3" /></Svg>
export const IconTrash = (p: P) => <Svg {...p}><path d="M4.5 7h15M9 7V4.8h6V7" /><path d="M6.5 7l.9 12.5h9.2L17.5 7" /><path d="M10.5 10.5v6M13.5 10.5v6" /></Svg>
export const IconRefresh = (p: P) => <Svg {...p}><path d="M20 12a8 8 0 1 1-2.6-5.9" /><path d="M20 4.5V9h-4.5" /></Svg>
export const IconCheck = (p: P) => <Svg {...p}><path d="m5 13 4.5 4.5L19 6.5" /></Svg>
export const IconClock = (p: P) => <Svg {...p}><circle cx="12" cy="12" r="8.4" /><path d="M12 7.6V12l3.2 2" /></Svg>
export const IconAlert = (p: P) => <Svg {...p}><path d="M12 4.2 21 19.4H3Z" /><path d="M12 10v4.2M12 16.8v.6" /></Svg>
export const IconInfo = (p: P) => <Svg {...p}><circle cx="12" cy="12" r="8.4" /><path d="M12 11v5.4M12 7.8v.6" /></Svg>
export const IconEye = (p: P) => <Svg {...p}><path d="M2.6 12S6 6.4 12 6.4 21.4 12 21.4 12 18 17.6 12 17.6 2.6 12 2.6 12Z" /><circle cx="12" cy="12" r="2.8" /></Svg>
export const IconEyeOff = (p: P) => <Svg {...p}><path d="M4 4.5 20 20" /><path d="M9.6 6.8A9.4 9.4 0 0 1 12 6.4c6 0 9.4 5.6 9.4 5.6a17 17 0 0 1-2.7 3.2" /><path d="M6.4 8.5A16.4 16.4 0 0 0 2.6 12S6 17.6 12 17.6c1 0 1.9-.2 2.7-.5" /><path d="M10.2 10.4a2.8 2.8 0 0 0 3.7 3.8" /></Svg>
export const IconLogout = (p: P) => <Svg {...p}><path d="M15 4.5h3.5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H15" /><path d="M10 8 6 12l4 4M6 12h10" /></Svg>
export const IconMenu = (p: P) => <Svg {...p}><path d="M4 7h16M4 12h16M4 17h16" /></Svg>
export const IconArrowRight = (p: P) => <Svg {...p}><path d="M4.5 12h14M14 7.5 18.5 12 14 16.5" /></Svg>
export const IconInbox = (p: P) => <Svg {...p}><path d="M3.5 13.5 6 5h12l2.5 8.5v5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5Z" /><path d="M3.5 13.5H9a3 3 0 0 0 6 0h5.5" /></Svg>
export const IconFilter = (p: P) => <Svg {...p}><path d="M3.5 6h17l-6.6 7.6v5.3l-3.8-2.3v-3Z" /></Svg>
export const IconSpark = (p: P) => <Svg {...p}><path d="M12 3.5 13.7 9l5.5 1.7L13.7 12.4 12 18l-1.7-5.6L4.8 10.7 10.3 9Z" /><path d="M18.5 16.5l.7 2.3 2.3.7-2.3.7-.7 2.3-.7-2.3-2.3-.7 2.3-.7Z" opacity=".6" /></Svg>
export const IconLibrary = (p: P) => <Svg {...p}><path d="M4 5.6h5.4a1.6 1.6 0 0 1 1.6 1.6v11.2H5.6A1.6 1.6 0 0 1 4 16.8Z" /><path d="M20 5.6h-5.4A1.6 1.6 0 0 0 13 7.2v11.2h5.4A1.6 1.6 0 0 0 20 16.8Z" /><path d="M6.6 8.8h3.2M6.6 12h3.2M14.2 12h3.2" /></Svg>
export const IconTransport = (p: P) => <Svg {...p}><path d="M4 15.5V8.2A2.2 2.2 0 0 1 6.2 6h11.6A2.2 2.2 0 0 1 20 8.2v7.3" /><path d="M3.4 15.5h17.2v2.2a1.4 1.4 0 0 1-1.4 1.4h-1.2a1.4 1.4 0 0 1-1.4-1.4v-.6H7.4v.6a1.4 1.4 0 0 1-1.4 1.4H4.8a1.4 1.4 0 0 1-1.4-1.4Z" /><path d="M4 11.6h16" /><circle cx="7.6" cy="13.6" r=".8" /><circle cx="16.4" cy="13.6" r=".8" /></Svg>
export const IconHostel = (p: P) => <Svg {...p}><path d="M3 19.5v-6.6h18v6.6" /><path d="M3 12.9V8.4A1.4 1.4 0 0 1 4.4 7h9.2a2.4 2.4 0 0 1 2.4 2.4v3.5" /><path d="M21 19.5v-3.2" /><circle cx="7.4" cy="10.4" r="1.5" /></Svg>
export const IconFrontDesk = (p: P) => <Svg {...p}><circle cx="12" cy="7.6" r="3.2" /><path d="M6.4 20.4c.4-3.6 2.7-5.6 5.6-5.6s5.2 2 5.6 5.6" /><path d="M3 20.4h18" opacity=".45" /></Svg>
export const IconGate = (p: P) => <Svg {...p}><path d="M4 20.6V5.4A1.4 1.4 0 0 1 5.4 4h9.2a1.4 1.4 0 0 1 1.4 1.4v16.6" /><path d="M3 20.6h18" /><path d="M19.6 12.3h-5.4" /><path d="m16.4 9.1 3.2 3.2-3.2 3.2" /></Svg>
export const IconCertificate = (p: P) => <Svg {...p}><rect x="4" y="3.8" width="16" height="13" rx="2" /><path d="M7.6 8h8.8M7.6 11.4h5.6" /><circle cx="15.4" cy="16.6" r="2.6" /><path d="m13.6 18.8-.9 3 2.7-1.3 2.7 1.3-.9-3" /></Svg>
export const IconDiscipline = (p: P) => <Svg {...p}><path d="M12 3.4 5.4 6v6.2c0 4.2 2.6 7.3 6.6 8.8 4-1.5 6.6-4.6 6.6-8.8V6Z" /><path d="M9.4 12.2h5.2M12 9.6v5.2" opacity=".55" /><path d="M9.4 12.4 11.2 14l3.6-3.8" /></Svg>
