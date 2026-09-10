// Helpers hiển thị dùng chung cho các màn mobile (/m/*).

export const todayISO = () => new Date().toLocaleDateString('en-CA'); // local YYYY-MM-DD

// "2026-07-13 08:02:11" | ISO | "08:02" → "08:02"
export const hhmm = (t) => {
  if (!t) return '—';
  const s = String(t);
  const m = s.match(/(\d{2}):(\d{2})/);
  return m ? `${m[1]}:${m[2]}` : s;
};

export const dmy = (d) => {
  if (!d) return '—';
  const s = String(d).slice(0, 10).split('-');
  return s.length === 3 ? `${s[2]}/${s[1]}/${s[0]}` : d;
};

export const vnd = (n) =>
  new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(n) || 0);

// Trạng thái đơn (đã normalize bởi leaveService) → nhãn + màu chip.
export const statusLabel = (s) => ({
  pending: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối', cancelled: 'Đã hủy',
}[s] || s || '—');

export const statusChip = (s) => ({
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
  approved: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
  rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
  cancelled: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
}[s] || 'bg-gray-100 text-gray-500');

// Trạng thái chấm công (normalize bởi attendanceService) → nhãn + màu.
export const attLabel = (s) => ({
  present: 'Đúng giờ', late: 'Đi muộn', early: 'Về sớm', absent: 'Vắng',
}[s] || s || '—');

export const attChip = (s) => ({
  present: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
  late: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
  early: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
  absent: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
}[s] || 'bg-gray-100 text-gray-500');
