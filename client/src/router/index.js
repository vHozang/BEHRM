import { createRouter, createWebHistory } from 'vue-router';

const routes = [
  {
    path: '/careers',
    name: 'careers',
    component: () => import('../views/CareersLanding.vue'),
    meta: { title: 'Cơ hội nghề nghiệp' }
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('../views/ForgotPassword.vue'),
    meta: { title: 'Quên mật khẩu' }
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('../views/ResetPassword.vue'),
    meta: { title: 'Đặt lại mật khẩu' }
  },
  {
    path: '/',
    component: () => import('../views/Layout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('../views/Dashboard.vue'),
        meta: { title: 'Dashboard', requiresAuth: true, adminOnly: true }
      },
      {
        path: 'employee-portal',
        name: 'employee-portal',
        component: () => import('../views/EmployeePortal.vue'),
        meta: { title: 'Tổng quan Portal', requiresAuth: true, adminOnly: false }
      },
      {
        path: 'employee-contracts',
        name: 'employee-contracts',
        component: () => import('../views/EmployeeContracts.vue'),
        meta: { title: 'Hợp đồng lao động', requiresAuth: true, adminOnly: false }
      },
      {
        path: 'employees',
        name: 'employees',
        component: () => import('../views/Employees.vue'),
        meta: { title: 'Quản lý Nhân viên', adminOnly: true }
      },
      {
        path: 'employees/:id',
        name: 'employee-detail',
        component: () => import('../views/EmployeeDetail.vue'),
        meta: { title: 'Chi tiết Nhân viên', adminOnly: true }
      },
      {
        path: 'departments',
        name: 'departments',
        component: () => import('../views/Departments.vue'),
        meta: { title: 'Quản lý Phòng ban', adminOnly: true }
      },
      {
        path: 'attendance',
        name: 'attendance',
        component: () => import('../views/Attendance.vue'),
        meta: { title: 'Chấm công' }
      },
      {
        path: 'timesheet',
        name: 'timesheet',
        component: () => import('../views/MonthlyTimesheet.vue'),
        meta: { title: 'Bảng công tháng', adminOnly: true }
      },
      {
        path: 'leaves',
        name: 'leaves',
        component: () => import('../views/Leaves.vue'),
        meta: { title: 'Nghỉ phép' }
      },
      {
        path: 'salaries',
        name: 'salaries',
        component: () => import('../views/Salaries.vue'),
        meta: { title: 'Lương' }
      },
      {
        path: 'roles',
        name: 'roles',
        component: () => import('../views/Roles.vue'),
        meta: { title: 'Vai trò & Phân quyền', adminOnly: true }
      },
      {
        path: 'job-titles',
        name: 'job-titles',
        component: () => import('../views/JobTitles.vue'),
        meta: { title: 'Quản lý Chức danh', adminOnly: true }
      },
      {
        path: 'job-families',
        name: 'job-families',
        component: () => import('../views/JobFamilies.vue'),
        meta: { title: 'Quản lý Nhóm chức danh', adminOnly: true }
      },
      {
        path: 'employment-history',
        name: 'employment-history',
        component: () => import('../views/EmploymentHistory.vue'),
        meta: { title: 'Quản lý Lịch sử công tác', adminOnly: true }
      },
      {
        path: 'profile-change-requests',
        name: 'profile-change-requests',
        component: () => import('../views/ProfileChangeRequests.vue'),
        meta: { title: 'Đơn đổi thông tin nhân sự', adminOnly: true }
      },
      {
        path: 'personnel-decisions',
        name: 'personnel-decisions',
        component: () => import('../views/PersonnelDecisions.vue'),
        meta: { title: 'Quyết định nhân sự', adminOnly: true }
      },
      {
        path: 'work-shifts',
        name: 'work-shifts',
        redirect: '/shifts',
        meta: { title: 'Ca làm việc', adminOnly: true }
      },
      {
        path: 'work-schedules',
        name: 'work-schedules',
        component: () => import('../views/WorkSchedules.vue'),
        meta: { title: 'Lịch làm việc' }
      },
      {
        path: 'salary-components',
        name: 'salary-components',
        component: () => import('../views/SalaryComponents.vue'),
        meta: { title: 'Quản lý Thành phần lương', adminOnly: true }
      },
      {
        path: 'piece-rate',
        name: 'piece-rate',
        component: () => import('../views/PieceRate.vue'),
        meta: { title: 'Công khoán sản phẩm', adminOnly: true }
      },
      {
        path: 'recruitment',
        name: 'recruitment',
        component: () => import('../views/Recruitment.vue'),
        meta: { title: 'Ứng viên (Kanban)', adminOnly: true }
      },
      {
        path: 'recruitment-positions',
        name: 'recruitment-positions',
        component: () => import('../views/RecruitmentPositions.vue'),
        meta: { title: 'Quản lý Tin tuyển dụng', adminOnly: true }
      },
      {
        path: 'interviews',
        name: 'interviews',
        component: () => import('../views/Interviews.vue'),
        meta: { title: 'Lịch phỏng vấn & Hẹn', adminOnly: true }
      },
      {
        path: 'contracts',
        name: 'contracts',
        component: () => import('../views/Contracts.vue'),
        meta: { title: 'Hợp đồng lao động', adminOnly: true }
      },
      {
        path: 'assets',
        name: 'assets',
        component: () => import('../views/Assets.vue'),
        meta: { title: 'Danh mục tài sản', adminOnly: true }
      },
      {
        path: 'asset-assignments',
        name: 'asset-assignments',
        component: () => import('../views/AssetAssignments.vue'),
        meta: { title: 'Lịch sử bàn giao tài sản', adminOnly: true }
      },
      {
        path: 'news',
        name: 'news',
        component: () => import('../views/News.vue'),
        meta: { title: 'Tin tức nội bộ' }
      },
      {
        path: 'policies',
        name: 'policies',
        component: () => import('../views/Policies.vue'),
        meta: { title: 'Chính sách công ty' }
      },
      {
        path: 'service-tickets',
        name: 'service-tickets',
        component: () => import('../views/ServiceTickets.vue'),
        meta: { title: 'Yêu cầu hỗ trợ nội bộ' }
      },
      {
        path: 'shift-roster',
        name: 'shift-roster',
        redirect: { path: '/shifts', query: { tab: 'roster' } },
        meta: { title: 'Bảng xếp ca trực quan', adminOnly: true }
      },
      {
        path: 'report-builder',
        name: 'report-builder',
        component: () => import('../views/ReportBuilder.vue'),
        meta: { title: 'Báo cáo tùy chỉnh', adminOnly: true }
      },
      {
        path: 'organization-chart',
        name: 'organization-chart',
        component: () => import('../views/OrganizationChart.vue'),
        meta: { title: 'Sơ đồ tổ chức' }
      },
      {
        path: 'requests',
        name: 'requests',
        component: () => import('../views/Requests.vue'),
        meta: { title: 'Yêu cầu phê duyệt', adminOnly: true }
      },
      {
        path: 'overtime-requests',
        name: 'overtime-requests',
        component: () => import('../views/OvertimeRequests.vue'),
        meta: { title: 'Đăng ký tăng ca', adminOnly: true }
      },
      {
        path: 'shift-swaps',
        name: 'shift-swaps',
        component: () => import('../views/ShiftSwaps.vue'),
        meta: { title: 'Yêu cầu đổi ca', adminOnly: true }
      },
      {
        path: 'shifts',
        name: 'shifts',
        component: () => import('../views/ShiftManagement.vue'),
        meta: { title: 'Ca làm việc & Xếp ca', adminOnly: true }
      },
      {
        path: 'shift-coverage',
        name: 'shift-coverage',
        component: () => import('../views/ShiftCoverage.vue'),
        meta: { title: 'Phủ ca khi vắng', adminOnly: true }
      },
      {
        path: 'attendance-adjustments',
        name: 'attendance-adjustments',
        component: () => import('../views/AttendanceAdjustments.vue'),
        meta: { title: 'Đơn điều chỉnh công', adminOnly: true }
      },
      {
        path: 'holidays',
        name: 'holidays',
        component: () => import('../views/Holidays.vue'),
        meta: { title: 'Lịch nghỉ lễ', adminOnly: true }
      },
      {
        path: 'dependents',
        name: 'dependents',
        component: () => import('../views/Dependents.vue'),
        meta: { title: 'Người phụ thuộc', adminOnly: true }
      },
      {
        path: 'onboarding',
        name: 'onboarding',
        component: () => import('../views/Onboarding.vue'),
        meta: { title: 'Hội nhập & Nghỉ việc', adminOnly: true }
      },
      {
        path: 'legal-entities',
        name: 'legal-entities',
        component: () => import('../views/LegalEntities.vue'),
        meta: { title: 'Chi nhánh / Pháp nhân', adminOnly: true }
      },
      {
        path: 'audit-logs',
        name: 'audit-logs',
        component: () => import('../views/AuditLogs.vue'),
        meta: { title: 'Nhật ký hệ thống', adminOnly: true }
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/Settings.vue'),
        meta: { title: 'Cấu hình nghiệp vụ', adminOnly: true }
      },
      {
        path: 'attendance-devices',
        name: 'attendance-devices',
        component: () => import('../views/AttendanceDevices.vue'),
        meta: { title: 'Máy chấm công', adminOnly: true }
      },
      {
        path: 'platform/tenants',
        name: 'platform-tenants',
        component: () => import('../views/PlatformTenants.vue'),
        meta: { title: 'Quản trị Platform — Tổ chức', adminOnly: true }
      }
    ]
  },
  {
    // Mobile shell (T8, Grab-style) — self-service cho nhân viên, tái dùng
    // service/API hiện có; bottom-tab 5 mục.
    path: '/m',
    component: () => import('../views/mobile/MobileLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'm-home', component: () => import('../views/mobile/MHome.vue'), meta: { title: 'Trang chủ' } },
      { path: 'attendance', name: 'm-attendance', component: () => import('../views/mobile/MAttendance.vue'), meta: { title: 'Chấm công' } },
      { path: 'requests', name: 'm-requests', component: () => import('../views/mobile/MRequests.vue'), meta: { title: 'Đơn từ' } },
      { path: 'salary', name: 'm-salary', component: () => import('../views/mobile/MSalary.vue'), meta: { title: 'Phiếu lương' } },
      { path: 'contracts', name: 'm-contracts', component: () => import('../views/EmployeeContracts.vue'), meta: { title: 'Hợp đồng lao động' } },
      { path: 'me', name: 'm-me', component: () => import('../views/mobile/MProfile.vue'), meta: { title: 'Tôi' } }
    ]
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/Login.vue')
  },
  {
    path: '/employee/info',
    name: 'EmployeeInfo',
    redirect: '/employee-portal'
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

router.beforeEach((to, from, next) => {
  try {
    const token = localStorage.getItem('auth_token');
    const isAuthenticated = !!token;
    const userRole = localStorage.getItem('user_role') || 'employee';
    let earlyAccess = { full: false, modules: [], capabilities: [] };
    try {
      const stored = JSON.parse(localStorage.getItem('access') || '{}');
      earlyAccess = {
        full: stored.full === true,
        modules: Array.isArray(stored.modules) ? stored.modules : [],
        capabilities: Array.isArray(stored.capabilities) ? stored.capabilities : []
      };
    } catch {}
    const isAdmin = userRole === 'admin'
      || earlyAccess.full
      || earlyAccess.modules.length > 0
      || earlyAccess.capabilities.some(capability => ['org_chart.view', 'payslip_issues.view'].includes(capability));

    if (to.meta.requiresAuth && !isAuthenticated) {
      next('/login');
      return;
    }
    
    if (to.path === '/login' && isAuthenticated) {
      next(isAdmin ? (earlyAccess.capabilities.includes('org_chart.view') && !earlyAccess.full && earlyAccess.modules.length === 0 ? '/organization-chart' : '/') : '/employee-portal');
      return;
    }
    
    if (to.meta.adminOnly && !isAdmin) {
      next('/employee-portal');
      return;
    }

    if (to.path.startsWith('/platform/')) {
      const user = JSON.parse(localStorage.getItem('user') || '{}');
      const isSuperAdmin = user?.is_super_admin === true || user?.is_super_admin === 1 || user?.is_super_admin === 't';
      if (!isSuperAdmin) {
        next('/');
        return;
      }
    }

    // Màn hình nhỏ → nhân viên vào portal được chuyển sang bản mobile /m
    // (trừ khi đã chọn "Dùng bản đầy đủ"). Admin giữ desktop shell.
    if (['/employee-portal', '/employee-contracts'].includes(to.path) && !isAdmin && window.innerWidth < 768
        && !localStorage.getItem('prefer_desktop')) {
      if (to.path === '/employee-contracts') {
        next({ path: '/m/contracts', query: to.query });
      } else if (to.query.tab === 'salary' || to.query.payslip) {
        next({ path: '/m/salary', query: { payslip: to.query.payslip } });
      } else {
        next({ path: '/m', query: to.query });
      }
      return;
    }

    // Module-based access (role-based RBAC): block admin pages whose module the
    // user's roles don't grant. Mirrors the backend ModuleAccess middleware.
    const ROUTE_MODULE = [
      ['/employees', 'hr'], ['/organization-chart', 'hr'], ['/contracts', 'hr'], ['/onboarding', 'hr'],
      ['/employment-history', 'hr'], ['/profile-change-requests', 'hr'], ['/dependents', 'hr'], ['/departments', 'hr'],
      ['/personnel-decisions', 'hr'],
      ['/assets', 'hr'], ['/asset-assignments', 'hr'],
      ['/attendance', 'time'], ['/timesheet', 'time'], ['/shifts', 'time'], ['/shift-roster', 'time'], ['/work-schedules', 'time'],
      ['/attendance-adjustments', 'time'], ['/leaves', 'time'],
      ['/overtime-requests', 'time'], ['/shift-swaps', 'time'], ['/shift-coverage', 'time'], ['/requests', 'time'], ['/holidays', 'time'],
      ['/salaries', 'payroll'], ['/salary-components', 'payroll'], ['/report-builder', 'payroll'], ['/piece-rate', 'payroll'],
      ['/recruitment', 'recruitment'], ['/recruitment-positions', 'recruitment'], ['/interviews', 'recruitment'],
      ['/job-families', 'settings'], ['/job-titles', 'settings'], ['/legal-entities', 'settings'],
      ['/audit-logs', 'settings'], ['/roles', 'settings'], ['/settings', 'settings'],
      ['/attendance-devices', 'settings']
    ];
    if (isAdmin) {
      let access = { full: true, modules: [], enabled: null, capabilities: [] };
      try {
        const a = JSON.parse(localStorage.getItem('access') || '{}');
        access = {
          full: a.full === true,
          modules: Array.isArray(a.modules) ? a.modules : [],
          enabled: Array.isArray(a.enabled) ? a.enabled : null,
          capabilities: Array.isArray(a.capabilities) ? a.capabilities : []
        };
      } catch {}
      const canUseDashboard = access.full || access.modules.some(m => ['hr', 'time', 'recruitment'].includes(m));
      if (to.path === '/' && !canUseDashboard) {
        next(access.capabilities.includes('org_chart.view') ? '/organization-chart' : (access.modules.includes('payroll') ? '/salaries' : '/employee-portal'));
        return;
      }
      const match = ROUTE_MODULE.find(([p]) => to.path === p || to.path.startsWith(p + '/'));
      if (match) {
        const mod = match[1];
        const issuesOnlySalary = to.path === '/salaries'
          && access.capabilities.includes('payslip_issues.view');
        const organizationOnly = to.path === '/organization-chart'
          && access.capabilities.includes('org_chart.view');
        const roleAllows = access.full || access.modules.includes(mod) || issuesOnlySalary || organizationOnly;
        const enabledOk = issuesOnlySalary || organizationOnly || mod === 'settings' || access.enabled === null || access.enabled.includes(mod);
        if (!roleAllows || !enabledOk) {
          next('/');
          return;
        }
      }
    }

    document.title = `${to.meta.title || 'HRM'} | Hệ thống Quản lý Nhân sự`;
    next();
  } catch (error) {
    console.error('Router guard error:', error);
    next('/login');
  }
});

export default router;
