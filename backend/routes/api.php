<?php

use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisciplineController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\FrontDeskController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\HostelController;
use App\Http\Controllers\HrController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'tenant', 'tenant.bindings'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('perm:dashboard.view');

        Route::get('campuses', [CampusController::class, 'index']);
        Route::post('campuses', [CampusController::class, 'store'])->middleware('perm:campuses.manage');
        Route::put('campuses/{campus}', [CampusController::class, 'update'])->middleware('perm:campuses.manage');

        Route::get('users', [UserController::class, 'index'])->middleware('perm:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('perm:users.manage');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:users.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('perm:users.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('perm:users.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('perm:roles.manage');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('perm:roles.manage');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('perm:roles.manage');

        Route::get('settings', [SettingController::class, 'index'])->middleware('perm:settings.view');
        Route::put('settings', [SettingController::class, 'update'])->middleware('perm:settings.manage,settings.manage_campus');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('perm:audit.view');

        // Academics structure
        Route::get('academic-years', [AcademicStructureController::class, 'years']);
        Route::get('programs', [AcademicStructureController::class, 'programs'])->middleware('perm:academics.view');
        Route::post('programs', [AcademicStructureController::class, 'storeProgram'])->middleware('perm:academics.manage');
        Route::put('programs/{program}', [AcademicStructureController::class, 'updateProgram'])->middleware('perm:academics.manage');
        Route::get('classes', [AcademicStructureController::class, 'classes'])->middleware('perm:academics.view');
        Route::post('classes', [AcademicStructureController::class, 'storeClass'])->middleware('perm:academics.manage');
        Route::put('classes/{class}', [AcademicStructureController::class, 'updateClass'])->middleware('perm:academics.manage');
        Route::get('sections', [AcademicStructureController::class, 'sections'])->middleware('perm:academics.view');
        Route::post('classes/{class}/sections', [AcademicStructureController::class, 'storeSection'])->middleware('perm:academics.manage');
        Route::put('sections/{section}', [AcademicStructureController::class, 'updateSection'])->middleware('perm:academics.manage');
        Route::put('classes/{class}/subjects', [AcademicStructureController::class, 'attachSubjects'])->middleware('perm:academics.manage');
        Route::get('subjects', [AcademicStructureController::class, 'subjects'])->middleware('perm:academics.view');
        Route::post('subjects', [AcademicStructureController::class, 'storeSubject'])->middleware('perm:academics.manage');
        Route::put('subjects/{subject}', [AcademicStructureController::class, 'updateSubject'])->middleware('perm:academics.manage');

        // Timetable
        Route::get('timetable', [TimetableController::class, 'index'])->middleware('perm:academics.view');
        Route::post('timetable', [TimetableController::class, 'store'])->middleware('perm:academics.manage');
        Route::put('timetable/{slot}', [TimetableController::class, 'update'])->middleware('perm:academics.manage');
        Route::delete('timetable/{slot}', [TimetableController::class, 'destroy'])->middleware('perm:academics.manage');

        // Class teacher's own view — sections are found through sections.class_teacher_id,
        // so this answers "which class is mine" without granting the whole academics module.
        Route::get('teacher/my-class', [TeacherController::class, 'myClass'])->middleware('perm:academics.view');

        // Students
        Route::get('students', [StudentController::class, 'index'])->middleware('perm:students.view');
        Route::post('students', [StudentController::class, 'store'])->middleware('perm:students.manage');
        Route::get('students/{student}', [StudentController::class, 'show'])->middleware('perm:students.view');
        Route::put('students/{student}', [StudentController::class, 'update'])->middleware('perm:students.manage');
        Route::post('students/{student}/promote', [StudentController::class, 'promote'])->middleware('perm:students.manage');

        // Admissions
        Route::get('admissions', [AdmissionController::class, 'index'])->middleware('perm:admissions.view');
        Route::post('admissions', [AdmissionController::class, 'store'])->middleware('perm:admissions.manage');
        Route::get('admissions/{admission}', [AdmissionController::class, 'show'])->middleware('perm:admissions.view');
        Route::put('admissions/{admission}', [AdmissionController::class, 'update'])->middleware('perm:admissions.manage');
        Route::post('admissions/{admission}/transition', [AdmissionController::class, 'transition'])->middleware('perm:admissions.manage');
        Route::post('admissions/{admission}/enroll', [AdmissionController::class, 'enroll'])->middleware('perm:admissions.manage');

        // Family portal — bounded by students.guardian_user_id, not by the reader's campus.
        Route::prefix('guardian')->middleware('perm:guardian.view')->group(function () {
            Route::get('wards', [GuardianController::class, 'wards']);
            Route::get('wards/{student}/attendance', [GuardianController::class, 'attendance'])->whereNumber('student');
            Route::get('wards/{student}/fees', [GuardianController::class, 'fees'])->whereNumber('student');
            Route::get('wards/{student}/results', [GuardianController::class, 'results'])->whereNumber('student');
        });

        // Attendance
        Route::get('attendance/roster', [AttendanceController::class, 'roster'])->middleware('perm:attendance.view');
        Route::post('attendance/mark', [AttendanceController::class, 'mark'])->middleware('perm:attendance.mark');
        Route::get('attendance/monthly', [AttendanceController::class, 'monthly'])->middleware('perm:attendance.view');
        Route::get('attendance/history', [AttendanceController::class, 'history'])->middleware('perm:attendance.view');

        // Examinations
        Route::get('exams', [ExamController::class, 'index'])->middleware('perm:exams.view');
        Route::post('exams', [ExamController::class, 'store'])->middleware('perm:exams.schedule');
        Route::get('exams/{exam}', [ExamController::class, 'show'])->middleware('perm:exams.view');
        Route::put('exams/{exam}', [ExamController::class, 'update'])->middleware('perm:exams.schedule');
        Route::get('exams/{exam}/roster', [ExamController::class, 'roster'])->middleware('perm:exams.marks_entry');
        Route::post('exams/{exam}/marks', [ExamController::class, 'enterMarks'])->middleware('perm:exams.marks_entry');
        Route::get('exams/{exam}/report-card', [ExamController::class, 'reportCard'])->middleware('perm:exams.view');
        Route::get('exams/{exam}/merit-list', [ExamController::class, 'meritList'])->middleware('perm:exams.view');
        Route::get('grading-scales', [ExamController::class, 'gradingScales'])->middleware('perm:exams.view');
        Route::put('grading-scales', [ExamController::class, 'putGradingScale'])->middleware('perm:exams.schedule');

        // Fees
        Route::get('fee-structures', [FeeController::class, 'structures'])->middleware('perm:fees.view');
        Route::post('fee-structures', [FeeController::class, 'storeStructure'])->middleware('perm:fees.manage');
        Route::put('fee-structures/{structure}', [FeeController::class, 'updateStructure'])->middleware('perm:fees.manage');
        Route::get('fee-invoices', [FeeController::class, 'invoices'])->middleware('perm:fees.view');
        Route::post('fee-invoices/generate', [FeeController::class, 'generate'])->middleware('perm:fees.manage');
        Route::get('fee-invoices/{invoice}', [FeeController::class, 'showInvoice'])->middleware('perm:fees.view');
        Route::post('fee-invoices/{invoice}/payments', [FeeController::class, 'recordPayment'])->middleware('perm:fees.payments_record');
        Route::post('fee-payments/{payment}/reverse', [FeeController::class, 'reversePayment'])->middleware('perm:fees.payments_record');
        Route::post('fees/apply-late-fees', [FeeController::class, 'applyLateFees'])->middleware('perm:fees.manage');
        Route::get('fees/outstanding', [FeeController::class, 'outstanding'])->middleware('perm:fees.view');
        Route::get('fee-waivers', [FeeController::class, 'waivers'])->middleware('perm:fees.view');
        Route::post('fee-waivers', [FeeController::class, 'requestWaiver'])->middleware('perm:fees.manage');
        Route::post('fee-waivers/{waiver}/decide', [FeeController::class, 'decideWaiver'])->middleware('perm:fees.waivers_approve');

        // HR — departments, designations, staff, leave
        Route::get('hr/summary', [HrController::class, 'summary'])->middleware('perm:hr.view');
        Route::get('departments', [HrController::class, 'departments'])->middleware('perm:staff.view');
        Route::post('departments', [HrController::class, 'storeDepartment'])->middleware('perm:staff.manage');
        Route::put('departments/{department}', [HrController::class, 'updateDepartment'])->middleware('perm:staff.manage');
        Route::get('designations', [HrController::class, 'designations'])->middleware('perm:staff.view');
        Route::post('designations', [HrController::class, 'storeDesignation'])->middleware('perm:staff.manage');
        Route::put('designations/{designation}', [HrController::class, 'updateDesignation'])->middleware('perm:staff.manage');
        Route::get('staff', [HrController::class, 'staff'])->middleware('perm:staff.view');
        Route::post('staff', [HrController::class, 'storeStaff'])->middleware('perm:staff.manage');
        Route::get('staff/{staff}', [HrController::class, 'showStaff'])->middleware('perm:staff.view');
        Route::put('staff/{staff}', [HrController::class, 'updateStaff'])->middleware('perm:staff.manage');
        Route::get('leave-applications', [HrController::class, 'leaves'])->middleware('perm:hr.view');
        Route::post('leave-applications', [HrController::class, 'applyLeave'])->middleware('perm:hr.manage');
        Route::post('leave-applications/{leave}/decide', [HrController::class, 'decideLeave'])->middleware('perm:hr.leave_approve');

        // Payroll
        Route::get('payroll/runs', [PayrollController::class, 'runs'])->middleware('perm:payroll.view');
        Route::post('payroll/runs', [PayrollController::class, 'storeRun'])->middleware('perm:payroll.process');
        Route::get('payroll/staff-options', [PayrollController::class, 'staffOptions'])->middleware('perm:payroll.view');
        Route::get('payroll/runs/{run}/payslips', [PayrollController::class, 'payslips'])->middleware('perm:payroll.view');
        Route::post('payroll/runs/{run}/calculate', [PayrollController::class, 'calculate'])->middleware('perm:payroll.process');
        Route::post('payroll/runs/{run}/transition', [PayrollController::class, 'transition'])->middleware('perm:payroll.process');
        Route::get('payslips/{payslip}', [PayrollController::class, 'showPayslip'])->middleware('perm:payroll.view');

        // Accounts
        Route::get('accounts/summary', [AccountController::class, 'summary'])->middleware('perm:accounts.view');
        Route::get('accounts/chart', [AccountController::class, 'chart'])->middleware('perm:accounts.view');
        Route::post('accounts/chart', [AccountController::class, 'storeAccount'])->middleware('perm:accounts.manage');
        Route::put('accounts/{account}', [AccountController::class, 'updateAccount'])->middleware('perm:accounts.manage');
        Route::get('accounts/{account}/ledger', [AccountController::class, 'accountLedger'])->middleware('perm:accounts.view');
        Route::get('vouchers', [AccountController::class, 'vouchers'])->middleware('perm:accounts.view');
        Route::post('vouchers', [AccountController::class, 'storeVoucher'])->middleware('perm:accounts.manage');
        Route::post('vouchers/{voucher}/reverse', [AccountController::class, 'reverseVoucher'])->middleware('perm:accounts.manage');
        Route::get('reports/trial-balance', [AccountController::class, 'trialBalance'])->middleware('perm:accounts.view');
        Route::get('reports/profit-loss', [AccountController::class, 'profitLoss'])->middleware('perm:accounts.view');
        Route::get('reports/balance-sheet', [AccountController::class, 'balanceSheet'])->middleware('perm:accounts.view');

        // Communication — announcements, direct messages, delivery log
        Route::get('announcements/audience', [CommunicationController::class, 'audience'])->middleware('perm:communication.view');
        Route::get('announcements', [CommunicationController::class, 'index'])->middleware('perm:communication.view');
        Route::post('announcements', [CommunicationController::class, 'store'])->middleware('perm:communication.manage');
        Route::put('announcements/{announcement}', [CommunicationController::class, 'update'])->middleware('perm:communication.manage');
        Route::delete('announcements/{announcement}', [CommunicationController::class, 'destroy'])->middleware('perm:communication.manage');
        Route::post('announcements/{announcement}/publish', [CommunicationController::class, 'publish'])->middleware('perm:communication.manage');
        Route::post('announcements/{announcement}/archive', [CommunicationController::class, 'archive'])->middleware('perm:communication.manage');
        Route::post('announcements/{announcement}/read', [CommunicationController::class, 'readAnnouncement'])->middleware('perm:communication.view');

        Route::get('communication/summary', [CommunicationController::class, 'summary'])->middleware('perm:communication.view');
        Route::get('communication/logs', [CommunicationController::class, 'logs'])->middleware('perm:communication.view');

        // Reading one's own mail is authorized by participation, not by a permission.
        Route::get('messages/contacts', [CommunicationController::class, 'contacts'])->middleware('perm:communication.view');
        Route::get('messages/unread-count', [CommunicationController::class, 'unreadCount']);
        Route::get('messages', [CommunicationController::class, 'messages']);
        Route::post('messages', [CommunicationController::class, 'send'])->middleware('perm:communication.manage');
        Route::get('messages/{message}/thread', [CommunicationController::class, 'thread']);
        Route::post('messages/{message}/reply', [CommunicationController::class, 'reply'])->middleware('perm:communication.reply,communication.manage');
        Route::post('messages/{message}/read', [CommunicationController::class, 'markRead']);

        // Library — catalogue, copies and issue/return with per-day overdue fine
        Route::get('library/summary', [LibraryController::class, 'summary'])->middleware('perm:library.view');
        Route::get('library/books', [LibraryController::class, 'books'])->middleware('perm:library.view');
        Route::post('library/books', [LibraryController::class, 'storeBook'])->middleware('perm:library.manage');
        Route::put('library/books/{book}', [LibraryController::class, 'updateBook'])->middleware('perm:library.manage');
        Route::delete('library/books/{book}', [LibraryController::class, 'destroyBook'])->middleware('perm:library.manage');
        Route::get('library/loans', [LibraryController::class, 'loans'])->middleware('perm:library.view');
        Route::post('library/loans', [LibraryController::class, 'issue'])->middleware('perm:library.manage');
        Route::post('library/loans/{loan}/return', [LibraryController::class, 'returnCopy'])->middleware('perm:library.manage');
        Route::post('library/loans/{loan}/lost', [LibraryController::class, 'markLost'])->middleware('perm:library.manage');

        // Transport — vehicles, routes with ordered stops, student assignments
        Route::get('transport/summary', [TransportController::class, 'summary'])->middleware('perm:transport.view');
        Route::get('transport/vehicles', [TransportController::class, 'vehicles'])->middleware('perm:transport.view');
        Route::post('transport/vehicles', [TransportController::class, 'storeVehicle'])->middleware('perm:transport.manage');
        Route::put('transport/vehicles/{vehicle}', [TransportController::class, 'updateVehicle'])->middleware('perm:transport.manage');
        Route::get('transport/routes', [TransportController::class, 'routes'])->middleware('perm:transport.view');
        Route::post('transport/routes', [TransportController::class, 'storeRoute'])->middleware('perm:transport.manage');
        Route::put('transport/routes/{route}', [TransportController::class, 'updateRoute'])->middleware('perm:transport.manage');
        Route::delete('transport/routes/{route}', [TransportController::class, 'destroyRoute'])->middleware('perm:transport.manage');
        Route::post('transport/routes/{route}/stops', [TransportController::class, 'storeStop'])->middleware('perm:transport.manage');
        Route::put('transport/stops/{stop}', [TransportController::class, 'updateStop'])->middleware('perm:transport.manage');
        Route::delete('transport/stops/{stop}', [TransportController::class, 'destroyStop'])->middleware('perm:transport.manage');
        Route::get('transport/assignments', [TransportController::class, 'assignments'])->middleware('perm:transport.view');
        Route::post('transport/assignments', [TransportController::class, 'assign'])->middleware('perm:transport.manage');
        Route::post('transport/assignments/{assignment}/discontinue', [TransportController::class, 'discontinue'])->middleware('perm:transport.manage');

        // Hostel — blocks, rooms and bed allotments with capacity and gender checks
        Route::get('hostel/summary', [HostelController::class, 'summary'])->middleware('perm:hostel.view');
        Route::get('hostel/blocks', [HostelController::class, 'blocks'])->middleware('perm:hostel.view');
        Route::post('hostel/blocks', [HostelController::class, 'storeBlock'])->middleware('perm:hostel.manage');
        Route::put('hostel/blocks/{block}', [HostelController::class, 'updateBlock'])->middleware('perm:hostel.manage');
        Route::get('hostel/rooms', [HostelController::class, 'rooms'])->middleware('perm:hostel.view');
        Route::post('hostel/rooms', [HostelController::class, 'storeRoom'])->middleware('perm:hostel.manage');
        Route::put('hostel/rooms/{room}', [HostelController::class, 'updateRoom'])->middleware('perm:hostel.manage');
        Route::get('hostel/allotments', [HostelController::class, 'allotments'])->middleware('perm:hostel.view');
        Route::post('hostel/allotments', [HostelController::class, 'allot'])->middleware('perm:hostel.manage');
        Route::post('hostel/allotments/{allotment}/vacate', [HostelController::class, 'vacate'])->middleware('perm:hostel.manage');

        // Front desk — visitor gate log
        Route::get('frontdesk/summary', [FrontDeskController::class, 'summary'])->middleware('perm:frontdesk.view');
        Route::get('frontdesk/visitors', [FrontDeskController::class, 'index'])->middleware('perm:frontdesk.view');
        Route::post('frontdesk/visitors', [FrontDeskController::class, 'store'])->middleware('perm:frontdesk.manage');
        Route::put('frontdesk/visitors/{visitor}', [FrontDeskController::class, 'update'])->middleware('perm:frontdesk.manage');
        Route::post('frontdesk/visitors/{visitor}/checkout', [FrontDeskController::class, 'checkOut'])->middleware('perm:frontdesk.manage');

        // Certificates and documents — request, decide, issue with a serial number
        Route::get('certificates/summary', [CertificateController::class, 'summary'])->middleware('perm:documents.view');
        Route::get('certificates', [CertificateController::class, 'index'])->middleware('perm:documents.view');
        Route::post('certificates', [CertificateController::class, 'store'])->middleware('perm:documents.manage');
        Route::put('certificates/{certificate}', [CertificateController::class, 'update'])->middleware('perm:documents.manage');
        Route::post('certificates/{certificate}/decide', [CertificateController::class, 'decide'])->middleware('perm:documents.manage');
        Route::post('certificates/{certificate}/issue', [CertificateController::class, 'issue'])->middleware('perm:documents.manage');
        Route::get('certificates/{certificate}/print', [CertificateController::class, 'printText'])->middleware('perm:documents.view');

        // School calendar — holidays, closures, exams and PTMs
        Route::get('calendar', [CalendarController::class, 'index'])->middleware('perm:calendar.view');
        Route::get('calendar/summary', [CalendarController::class, 'summary'])->middleware('perm:calendar.view');
        Route::post('calendar', [CalendarController::class, 'store'])->middleware('perm:calendar.manage');
        Route::put('calendar/{event}', [CalendarController::class, 'update'])->middleware('perm:calendar.manage');
        Route::delete('calendar/{event}', [CalendarController::class, 'destroy'])->middleware('perm:calendar.manage');

        // Discipline — incidents, actions and case closure
        Route::get('discipline/summary', [DisciplineController::class, 'summary'])->middleware('perm:discipline.view');
        Route::get('discipline/cases', [DisciplineController::class, 'index'])->middleware('perm:discipline.view');
        Route::post('discipline/cases', [DisciplineController::class, 'store'])->middleware('perm:discipline.manage');
        Route::put('discipline/cases/{case}', [DisciplineController::class, 'update'])->middleware('perm:discipline.manage');
        Route::post('discipline/cases/{case}/close', [DisciplineController::class, 'close'])->middleware('perm:discipline.manage');
    });
});
