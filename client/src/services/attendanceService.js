import axiosClient from './axiosClient';

const attendanceStatusMap = {
  ON_TIME: 'present',
  PRESENT: 'present',
  CHECKED_IN: 'present',
  CHECKED_OUT: 'present',
  'ĐÃ_DUYỆT': 'present',
  'ĐÃ DUYỆT': 'present',
  LATE: 'late',
  'ĐI_MUỘN': 'late',
  'ĐI MUỘN': 'late',
  'MUỘN': 'late',
  EARLY_LEAVE: 'early',
  'VỀ_SỚM': 'early',
  'VỀ SỚM': 'early',
  ABSENT: 'absent',
  'VẮNG': 'absent',
  'NGHỈ': 'absent',
  HALF_DAY: 'half_day'
};

const toBackendAttendanceStatus = {
  present: 'ON_TIME',
  late: 'LATE',
  early: 'EARLY_LEAVE',
  absent: 'ABSENT',
  half_day: 'HALF_DAY'
};

function normalizeAttendance(record) {
  if (!record || typeof record !== 'object') return record;
  const rawStatus = record.status == null ? '' : String(record.status);
  const normalizedStatus = attendanceStatusMap[rawStatus.toUpperCase()] || rawStatus.toLowerCase();
  const regularMinutes = Number(record.regular_worked_minutes ?? 0);
  // Công thường is capped to the assigned shift. Prefer the central engine's
  // regular minutes so early arrival/late departure never inflate UI totals.
  const workedHours = regularMinutes > 0
    ? Math.round((regularMinutes / 60) * 100) / 100
    : Number(record.total_work_hours ?? record.worked_hours ?? 0);
  const payrollReview = record.payroll_review || record.payrollReview || null;
  const shift = record.shift_type || record.shiftType || null;
  return {
    ...record,
    status: normalizedStatus,
    total_work_hours: workedHours,
    worked_hours: record.worked_hours ?? workedHours,
    regular_worked_minutes: regularMinutes || Math.round(workedHours * 60),
    raw_presence_minutes: Number(record.raw_presence_minutes ?? 0),
    early_arrival_minutes: Number(record.early_arrival_minutes ?? 0),
    late_minutes: Number(record.late_minutes ?? 0),
    early_leave_minutes: Number(record.early_leave_minutes ?? 0),
    after_shift_minutes: Number(record.after_shift_minutes ?? 0),
    scheduled_minutes: Number(record.scheduled_minutes ?? 0),
    assigned_shift: shift,
    assigned_shift_code: shift?.shift_code || record.assigned_shift_code || record.shift_code || '',
    assigned_shift_name: shift?.shift_name || record.assigned_shift_name || record.shift_name || '',
    shift_start: record.shift_start || null,
    shift_end: record.shift_end || null,
    payroll_review: payrollReview,
    payroll_review_status: payrollReview?.status || record.payroll_review_status || null,
    payroll_review_percent: payrollReview?.approved_percent ?? record.payroll_review_percent ?? null,
    record_date: record.record_date || record.work_date || record.attendance_date,
    attendance_date: record.attendance_date || record.work_date || record.record_date
  };
}

function normalizeAttendanceList(data) {
  return Array.isArray(data) ? data.map(normalizeAttendance) : normalizeAttendance(data);
}

const otStatusMap = {
  PENDING: 'pending',
  'CHỜ_DUYỆT': 'pending',
  OFFERED: 'offered',
  APPROVED: 'approved',
  'ĐÃ_DUYỆT': 'approved',
  REJECTED: 'rejected',
  DECLINED: 'declined',
  CANCELLED: 'cancelled'
};

function mapOvertime(r) {
  if (!r || typeof r !== 'object') return r;
  let meta = r.meta;
  if (typeof meta === 'string') { try { meta = JSON.parse(meta); } catch (e) { meta = {}; } }
  meta = meta || {};
  const reconciliation = meta.overtime_reconciliation || {};
  const raw = String(r.status || '');
  const approvedMinutes = Number(reconciliation.approved_minutes ?? (Number(r.total_hours || r.hours || 0) * 60));
  const payableMinutes = Number(reconciliation.payable_minutes ?? meta.payable_overtime_minutes ?? 0);
  return {
    ...r,
    meta,
    status: otStatusMap[raw.toUpperCase()] || raw.toLowerCase(),
    hours: Number(r.total_hours || r.hours || 0),
    reason: meta.reason || r.reason || '',
    day_type: meta.day_type || null,
    multiplier: meta.multiplier || null,
    night_hours: Number(meta.night_hours || 0),
    classify_label: meta.label || '',
    kind: meta.kind || 'EMPLOYEE_REQUEST',
    is_ticket: (meta.kind || '') === 'MANAGER_TICKET',
    approved_minutes: approvedMinutes,
    approved_hours: Math.round((approvedMinutes / 60) * 100) / 100,
    actual_outside_minutes: Number(reconciliation.actual_outside_minutes ?? 0),
    actual_outside_hours: Math.round((Number(reconciliation.actual_outside_minutes ?? 0) / 60) * 100) / 100,
    matched_minutes: Number(reconciliation.matched_minutes ?? 0),
    payable_overtime_minutes: payableMinutes,
    payable_overtime_hours: Math.round((payableMinutes / 60) * 100) / 100,
    reconciliation_status: reconciliation.status || null,
    reconciliation_mode: reconciliation.mode || null,
    reconciliation_warnings: Array.isArray(reconciliation.warnings) ? reconciliation.warnings : [],
    converted_to_comp_off: !!meta.converted_to_comp_off,
  };
}

export const attendanceService = {
  getAll: async (params) => {
    return attendanceService.getRecords(params);
  },

  getRecordsPage: async (params = {}) => {
    const query = { ...params };
    if (query.status) query.status = toBackendAttendanceStatus[query.status] || query.status;
    const response = await axiosClient.get('/attendances', {
      params: { per_page: 50, ...query }
    });

    return {
      items: normalizeAttendanceList(Array.isArray(response.data) ? response.data : []),
      pagination: response.pagination || null,
      summary: response.summary || null,
    };
  },

  getCursorPage: async (params = {}, signal) => {
    const query = { ...params };
    if (query.status) query.status = toBackendAttendanceStatus[query.status] || query.status;
    const response = await axiosClient.get('/attendances', {
      params: { pagination: 'cursor', limit: 50, ...query },
      signal,
    });
    const payload = response.pageData || {};
    return { ...payload, items: normalizeAttendanceList(payload.items || []) };
  },

  getOverview: async (params = {}, signal) => {
    const query = { ...params };
    if (query.status) query.status = toBackendAttendanceStatus[query.status] || query.status;
    const response = await axiosClient.get('/attendance/overview', { params: query, signal });
    return response.data || {};
  },

  getDetail: async (id, signal) => {
    const response = await axiosClient.get(`/attendances/${id}`, { signal });
    return normalizeAttendance(response.data);
  },

  getRecords: async (params = {}) => {
    const records = [];
    let page = 1;
    let lastPage = 1;

    do {
      const result = await attendanceService.getRecordsPage({
        ...params,
        per_page: 100,
        page,
      });
      records.push(...result.items);
      lastPage = Number(result.pagination?.last_page || 1);
      page++;
    } while (page <= lastPage);

    return records;
  },

  checkIn: async (employee_id, geo = {}) => {
    const id = typeof employee_id === 'object' ? employee_id.employee_id : employee_id;
    const response = await axiosClient.post('/attendances/check-in', {
      employee_id: id,
      latitude: geo.latitude,
      longitude: geo.longitude,
      accuracy: geo.accuracy,
      source: geo.source || 'web',
      work_mode: geo.work_mode || 'office',
      site_name: geo.site_name,
    });
    // FE đọc record.review_status để biết lượt có bị đánh dấu "cần xem xét".
    return normalizeAttendance(response.data);
  },

  checkOut: async (employee_id, geo = {}) => {
    const id = typeof employee_id === 'object' ? employee_id.employee_id : employee_id;
    const response = await axiosClient.post('/attendances/check-out', {
      employee_id: id,
      latitude: geo.latitude,
      longitude: geo.longitude,
      accuracy: geo.accuracy,
      source: geo.source || 'web',
    });
    return normalizeAttendance(response.data);
  },

  // Admin xác minh một lượt chấm công bị đánh dấu (approve | reject).
  verify: async (id, decision, note) => {
    const response = await axiosClient.post(`/attendances/${id}/verify`, { decision, note });
    return normalizeAttendance(response.data);
  },

  update: async (id, data) => {
    const response = await axiosClient.patch(`/attendances/${id}`, {
      work_date: data.work_date || data.record_date || data.attendance_date,
      check_in_time: data.check_in_time,
      check_out_time: data.check_out_time,
      check_in_time_2: data.check_in_time_2 || null,
      check_out_time_2: data.check_out_time_2 || null,
      shift_type_id: data.shift_type_id || undefined,
      notes: data.notes || null,
    });
    return normalizeAttendance(response.data);
  },

  getPayrollReviews: async (params = {}) => {
    const response = await axiosClient.get('/attendance/payroll-reviews', {
      params: { per_page: 100, ...params }
    });
    return {
      items: Array.isArray(response.data) ? response.data : [],
      pagination: response.pagination || null,
    };
  },

  decidePayrollReview: async (id, percent, note) => {
    const response = await axiosClient.post(`/attendance/payroll-reviews/${id}/decision`, {
      percent: Number(percent),
      note: note || null,
    });
    return response.data;
  },

  getOvertime: async (params) => {
    const response = await axiosClient.get('/overtime-requests', { params });
    const data = response.data?.items || response.data || [];
    return Array.isArray(data) ? data.map(mapOvertime) : data;
  },

  getOvertimeUsage: async (employeeId, date) => {
    const response = await axiosClient.get('/overtime-requests/usage', { params: { employee_id: employeeId, date } });
    return response.data;
  },

  createOvertime: async (data) => {
    const payload = {
      employee_id: data.employee_id,
      work_date: data.work_date || data.overtime_date,
      start_time: data.start_time,
      end_time: data.end_time,
      reason: data.reason,
    };
    const response = await axiosClient.post('/overtime-requests', payload);
    return response.data;
  },

  createOvertimeTicket: async (data) => {
    const response = await axiosClient.post('/overtime-tickets', {
      employee_id: Number(data.employee_id),
      work_date: data.work_date || data.overtime_date,
      start_time: data.start_time,
      end_time: data.end_time,
      reason: data.reason || null,
    });
    return mapOvertime(response.data);
  },

  respondOvertimeTicket: async (id, decision, note = '') => {
    const response = await axiosClient.post(`/overtime-tickets/${id}/respond`, {
      decision,
      note: note || null,
    });
    return mapOvertime(response.data);
  },

  cancelOvertimeTicket: async (id, reason = '') => {
    const response = await axiosClient.post(`/overtime-tickets/${id}/cancel`, {
      reason: reason || null,
    });
    return mapOvertime(response.data);
  },

  approveOvertime: async (id, compOff = false) => {
    const response = await axiosClient.post(`/overtime-requests/${id}/approve`, { comp_off: !!compOff });
    return { data: response.data, message: response.apiMessage };
  },

  // P3 — Bảng công tháng (timesheet grid nhân viên × ngày).
  getTimesheet: async (month, params = {}) => {
    const response = await axiosClient.get('/attendance/timesheet', { params: { month, page: 1, per_page: 25, ...params } });
    return response.data; // { month, start, end, standard_days, days[], rows[] }
  },

  createTimesheetExport: async ({ month, format = 'xlsx', ...filters }) => {
    const response = await axiosClient.post('/attendance/timesheet/exports', { month, format, ...filters });
    return response.data;
  },

  getTimesheetExport: async (id) => {
    const response = await axiosClient.get(`/attendance/timesheet/exports/${id}`);
    return response.data;
  },

  downloadTimesheetExport: async (id, filename) => {
    const response = await axiosClient.get(`/attendance/timesheet/exports/${id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
  },

  // Tái phân loại trạng thái chấm công theo ca + dung sai (engine).
  recomputeTimesheet: async (month, params = {}) => {
    const response = await axiosClient.post('/attendance/recompute', { month, ...params });
    return response.data; // { scanned, updated }
  },

  // HR/Admin theo dõi bridge và yêu cầu lấy dữ liệu máy chấm công ngay lập tức.
  getDeviceSyncStatus: async () => {
    const response = await axiosClient.get('/attendance/device-sync');
    return response.data;
  },

  requestDeviceSync: async (deviceId = null) => {
    const response = await axiosClient.post('/attendance/device-sync', deviceId ? { device_id: deviceId } : {});
    return response.data;
  },

  // Chốt: tổng hợp công của kỳ lương (feed payroll).
  runSummary: async (salaryPeriodId) => {
    const response = await axiosClient.post('/attendance/summary/run', { salary_period_id: salaryPeriodId });
    return response.data; // { period_id, employees, rows_upserted }
  },

  // Khoá kỳ lương (chốt cứng — không sửa được chấm công/lương sau đó).
  closePeriod: async (salaryPeriodId) => {
    const response = await axiosClient.post(`/salary-periods/${salaryPeriodId}/close`);
    return response.data;
  }
};
