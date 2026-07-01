import { ref, computed } from 'vue';

/**
 * Lightweight i18n — NO external dependency (intentionally not vue-i18n).
 *
 * API:
 *   import { useI18n } from '@/i18n';   // or relative path
 *   const { t, locale, setLocale, availableLocales } = useI18n();
 *
 *   - locale:           Ref<'vi' | 'en'>  (default 'vi', persisted to localStorage 'locale')
 *   - t(key, fallback): string            (dot-path lookup; returns fallback or key if missing)
 *   - setLocale('en'):  void
 *   - availableLocales: [{ code, label }]
 *
 * The `t` function reads `locale.value` reactively, so any template using
 * `{{ t('nav.dashboard') }}` re-renders when the locale changes.
 *
 * State is module-level so all components share one locale.
 */

const STORAGE_KEY = 'locale';

const messages = {
  vi: {
    common: {
      search: 'Tìm kiếm...',
      searchPages: 'Tìm kiếm trang...',
      save: 'Lưu',
      cancel: 'Hủy',
      confirm: 'Xác nhận',
      delete: 'Xóa',
      edit: 'Sửa',
      add: 'Thêm',
      close: 'Đóng',
      loading: 'Đang tải...',
      noData: 'Không có dữ liệu',
      logout: 'Đăng xuất',
      changePassword: 'Đổi mật khẩu',
      admin: 'Quản trị viên',
      employee: 'Nhân viên',
      askAi: 'Hỏi HR AI',
      createdByAi: 'Tạo bởi AI',
      notifications: 'Thông báo',
      markAllRead: 'Đánh dấu đã đọc',
      noNotifications: 'Không có thông báo mới',
      send: 'Gửi',
    },
    ai: {
      title: 'Trợ lý HR',
      badge: 'AI',
      placeholder: 'Nhập câu hỏi cho trợ lý HR...',
      greeting: 'Xin chào! Tôi là trợ lý HR. Bạn cần hỗ trợ gì về nhân sự, chấm công hay nghỉ phép?',
      thinking: 'Đang soạn câu trả lời...',
      notConfigured: 'Trợ lý AI chưa được cấu hình — thêm ANTHROPIC_API_KEY để kích hoạt.',
      error: 'Đã xảy ra lỗi khi kết nối trợ lý AI. Vui lòng thử lại.',
    },
    nav: {
      dashboard: 'Tổng quan',
      organization: 'Tổ chức',
      employees: 'Nhân viên',
      departments: 'Phòng ban',
      roles: 'Vai trò & Phân quyền',
      attendanceLeave: 'Chấm công & Phép',
      attendance: 'Chấm công',
      leaves: 'Nghỉ phép',
      recruitment: 'Tuyển dụng',
      payroll: 'Lương',
      contracts: 'Hợp đồng',
      assets: 'Tài sản',
      communications: 'Truyền thông',
      requests: 'Yêu cầu',
      reports: 'Báo cáo',
      administration: 'Quản trị',
      platform: 'Platform',
      settings: 'Cấu hình',
    },
    breadcrumb: {
      workspace: 'Workspace',
    },
    dashboard: {
      greetingMorning: 'Chào buổi sáng',
      greetingAfternoon: 'Chào buổi chiều',
      greetingEvening: 'Chào buổi tối',
      subtitle: 'Tổng quan vận hành nhân sự hôm nay',
      checkIn: 'Chấm công',
      requestLeave: 'Xin nghỉ',
      addEmployee: 'Thêm NV',
      totalEmployees: 'Tổng nhân viên',
      presentToday: 'Có mặt hôm nay',
      pendingLeaves: 'Đơn nghỉ chờ duyệt',
      contractsExpiring: 'Hợp đồng sắp hết hạn',
      candidatesInProgress: 'Ứng viên đang xử lý',
      aiInsights: 'AI Insights',
      confidence: 'Độ tin cậy',
      attendanceTrend: 'Xu hướng chấm công 30 ngày',
      leaveByStatus: 'Nghỉ phép theo trạng thái',
      headcountByDept: 'Nhân viên theo phòng ban',
      toDo: 'Cần xử lý',
      recentActivity: 'Hoạt động gần đây',
      present: 'Có mặt',
      late: 'Đi muộn',
      absent: 'Vắng',
      apiUnavailable: 'API không khả dụng — đang hiển thị dữ liệu mẫu.',
      loadingData: 'Đang tải dữ liệu...',
      noActivity: 'Chưa có hoạt động nào',
      noData: 'Chưa có dữ liệu',
      withinDays: 'Trong 30 ngày tới',
      needResponse: 'Cần phản hồi cho nhân viên',
      recruitmentPipeline: 'Pipeline tuyển dụng',
      apiUnavailableTitle: 'Không tải được dữ liệu',
      apiUnavailableBody: 'Không thể kết nối tới máy chủ. Các chỉ số bên dưới đang để trống cho tới khi kết nối được khôi phục.',
      retry: 'Thử lại',
      candidates: 'Ứng viên',
      attendanceRate: 'Tỷ lệ có mặt',
      ofTotal: 'trên tổng số',
      upcoming: 'Sắp tới',
      birthday: 'Sinh nhật',
      workAnniversary: 'Kỷ niệm công tác',
      newHire: 'Nhân viên mới',
      noUpcoming: 'Không có sự kiện sắp tới',
      today: 'Hôm nay',
      inDays: 'Trong {n} ngày',
      yearsAt: '{n} năm gắn bó',
      joined: 'Gia nhập {date}',
      leave: 'Nghỉ phép',
      leaveTotal: 'Tổng',
      onLeaveToday: 'Đang nghỉ hôm nay',
      peopleCount: '{n} người',
      noOneOnLeave: 'Không có ai nghỉ hôm nay',
      yearQuotaUsed: 'Quỹ phép năm đã dùng',
      quotaCaption: 'Đã dùng {used}/{total} ngày',
      until: 'đến {date}',
    },
  },
  en: {
    common: {
      search: 'Search...',
      searchPages: 'Search pages...',
      save: 'Save',
      cancel: 'Cancel',
      confirm: 'Confirm',
      delete: 'Delete',
      edit: 'Edit',
      add: 'Add',
      close: 'Close',
      loading: 'Loading...',
      noData: 'No data',
      logout: 'Log out',
      changePassword: 'Change password',
      admin: 'Administrator',
      employee: 'Employee',
      askAi: 'Ask HR AI',
      createdByAi: 'Created by AI',
      notifications: 'Notifications',
      markAllRead: 'Mark all as read',
      noNotifications: 'No new notifications',
      send: 'Send',
    },
    ai: {
      title: 'HR Assistant',
      badge: 'AI',
      placeholder: 'Ask the HR assistant anything...',
      greeting: 'Hi! I am your HR assistant. How can I help with people, attendance or leave?',
      thinking: 'Thinking...',
      notConfigured: 'The AI assistant is not configured yet — add ANTHROPIC_API_KEY to enable it.',
      error: 'Something went wrong reaching the AI assistant. Please try again.',
    },
    nav: {
      dashboard: 'Dashboard',
      organization: 'Organization',
      employees: 'Employees',
      departments: 'Departments',
      roles: 'Roles & Permissions',
      attendanceLeave: 'Attendance & Leave',
      attendance: 'Attendance',
      leaves: 'Leave',
      recruitment: 'Recruitment',
      payroll: 'Payroll',
      contracts: 'Contracts',
      assets: 'Assets',
      communications: 'Communications',
      requests: 'Requests',
      reports: 'Reports',
      administration: 'Administration',
      platform: 'Platform',
      settings: 'Settings',
    },
    breadcrumb: {
      workspace: 'Workspace',
    },
    dashboard: {
      greetingMorning: 'Good morning',
      greetingAfternoon: 'Good afternoon',
      greetingEvening: 'Good evening',
      subtitle: "Today's people operations overview",
      checkIn: 'Check in',
      requestLeave: 'Request leave',
      addEmployee: 'Add employee',
      totalEmployees: 'Total employees',
      presentToday: 'Present today',
      pendingLeaves: 'Pending leave requests',
      contractsExpiring: 'Contracts expiring',
      candidatesInProgress: 'Candidates in progress',
      aiInsights: 'AI Insights',
      confidence: 'Confidence',
      attendanceTrend: '30-day attendance trend',
      leaveByStatus: 'Leave by status',
      headcountByDept: 'Headcount by department',
      toDo: 'To do',
      recentActivity: 'Recent activity',
      present: 'Present',
      late: 'Late',
      absent: 'Absent',
      apiUnavailable: 'API unavailable — showing sample data.',
      loadingData: 'Loading data...',
      noActivity: 'No activity yet',
      noData: 'No data yet',
      withinDays: 'Within the next 30 days',
      needResponse: 'Awaiting your response',
      recruitmentPipeline: 'Recruitment pipeline',
      apiUnavailableTitle: 'Could not load data',
      apiUnavailableBody: 'We could not reach the server. The metrics below stay empty until the connection is restored.',
      retry: 'Retry',
      candidates: 'Candidates',
      attendanceRate: 'Attendance rate',
      ofTotal: 'of total',
      upcoming: 'Upcoming',
      birthday: 'Birthday',
      workAnniversary: 'Work anniversary',
      newHire: 'New hire',
      noUpcoming: 'No upcoming events',
      today: 'Today',
      inDays: 'In {n} days',
      yearsAt: '{n} years with us',
      joined: 'Joined {date}',
      leave: 'Leave',
      leaveTotal: 'Total',
      onLeaveToday: 'On leave today',
      peopleCount: '{n} people',
      noOneOnLeave: 'No one is on leave today',
      yearQuotaUsed: 'Annual leave quota used',
      quotaCaption: 'Used {used}/{total} days',
      until: 'until {date}',
    },
  },
};

const availableLocales = [
  { code: 'vi', label: 'VI' },
  { code: 'en', label: 'EN' },
];

function readInitialLocale() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'vi' || saved === 'en') return saved;
  } catch (e) {
    /* ignore */
  }
  return 'vi';
}

const locale = ref(readInitialLocale());

function resolve(dict, key) {
  return key.split('.').reduce((acc, part) => (acc && acc[part] != null ? acc[part] : undefined), dict);
}

/**
 * Translate a dot-path key for the current locale.
 * Falls back to: English -> provided fallback -> the key itself.
 */
function t(key, fallback) {
  if (!key) return fallback ?? '';
  const fromLocale = resolve(messages[locale.value] || {}, key);
  if (fromLocale != null) return fromLocale;
  const fromEn = resolve(messages.en, key);
  if (fromEn != null) return fromEn;
  return fallback != null ? fallback : key;
}

function setLocale(code) {
  if (code !== 'vi' && code !== 'en') return;
  locale.value = code;
  try {
    localStorage.setItem(STORAGE_KEY, code);
  } catch (e) {
    /* ignore */
  }
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('lang', code);
  }
}

export function useI18n() {
  return {
    locale,
    t,
    setLocale,
    availableLocales,
    // convenience computed of current locale label
    currentLocaleLabel: computed(
      () => (availableLocales.find((l) => l.code === locale.value) || availableLocales[0]).label
    ),
  };
}

export { locale, t, setLocale, availableLocales, messages };
export default useI18n;
