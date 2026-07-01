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
  'NGHỈ': 'absent'
};

const toBackendAttendanceStatus = {
  present: 'ON_TIME',
  late: 'LATE',
  early: 'EARLY_LEAVE',
  absent: 'ABSENT'
};

function normalizeAttendance(record) {
  if (!record || typeof record !== 'object') return record;
  const rawStatus = record.status == null ? '' : String(record.status);
  const normalizedStatus = attendanceStatusMap[rawStatus.toUpperCase()] || rawStatus.toLowerCase();
  // Backend stores worked hours in meta.worked_hours (axios flattens meta to
  // top-level). The UI expects `total_work_hours` — map it so totals aren't 0h.
  const workedHours = record.total_work_hours ?? record.worked_hours ?? 0;
  return {
    ...record,
    status: normalizedStatus,
    total_work_hours: workedHours,
    worked_hours: record.worked_hours ?? workedHours,
    record_date: record.record_date || record.work_date || record.attendance_date,
    attendance_date: record.attendance_date || record.work_date || record.record_date
  };
}

function normalizeAttendanceList(data) {
  return Array.isArray(data) ? data.map(normalizeAttendance) : normalizeAttendance(data);
}

const otStatusMap = { PENDING: 'pending', 'CHỜ_DUYỆT': 'pending', APPROVED: 'approved', 'ĐÃ_DUYỆT': 'approved', REJECTED: 'rejected', CANCELLED: 'cancelled' };

function mapOvertime(r) {
  if (!r || typeof r !== 'object') return r;
  let meta = r.meta;
  if (typeof meta === 'string') { try { meta = JSON.parse(meta); } catch (e) { meta = {}; } }
  meta = meta || {};
  const raw = String(r.status || '');
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
  };
}

export const attendanceService = {
  getAll: async (params) => {
    return attendanceService.getRecords(params);
  },

  getRecords: async (params) => {
    const response = await axiosClient.get('/attendances', { params });
    return normalizeAttendanceList(response.data);
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
      total_work_hours: data.total_work_hours,
      late_minutes: data.late_minutes,
      early_leave_minutes: data.early_leave_minutes,
      overtime_hours: data.overtime_hours,
      status: toBackendAttendanceStatus[data.status] || data.status
    });
    return normalizeAttendance(response.data);
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
    // FE gửi overtime_date/hours; BE nhận work_date + (start/end | total_hours).
    const payload = {
      employee_id: data.employee_id,
      work_date: data.work_date || data.overtime_date,
      total_hours: data.hours != null && data.hours !== '' ? Number(data.hours) : undefined,
      start_time: data.start_time || undefined,
      end_time: data.end_time || undefined,
      reason: data.reason,
    };
    const response = await axiosClient.post('/overtime-requests', payload);
    return response.data;
  },

  approveOvertime: async (id, compOff = false) => {
    const response = await axiosClient.post(`/overtime-requests/${id}/approve`, { comp_off: !!compOff });
    return { data: response.data, message: response.apiMessage };
  },

  // P3 — Bảng công tháng (timesheet grid nhân viên × ngày).
  getTimesheet: async (month, params = {}) => {
    const response = await axiosClient.get('/attendance/timesheet', { params: { month, ...params } });
    return response.data; // { month, start, end, standard_days, days[], rows[] }
  },

  // Tái phân loại trạng thái chấm công theo ca + dung sai (engine).
  recomputeTimesheet: async (month, params = {}) => {
    const response = await axiosClient.post('/attendance/recompute', { month, ...params });
    return response.data; // { scanned, updated }
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
