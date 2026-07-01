// Dựng các bước cho <ApprovalTimeline> từ một ĐƠN bất kỳ (nghỉ/tăng ca/điều chỉnh
// công/đổi ca/đổi thông tin). Mục tiêu: luôn hiện rõ NGƯỜI TẠO ĐƠN và NGƯỜI DUYỆT
// (tên thật, không phải chỉ vai trò), kèm trạng thái đúng màu.
//
// Backend lưu duyệt nhiều cấp trong meta.approval = { chain, current, steps:[
//   { role, status:PENDING|APPROVED|REJECTED, approver_id, at, comment } ] }.
// approver_id là id NHÂN VIÊN đã duyệt → resolve sang tên qua `resolveName`.

const ROLE_LABELS = {
  MANAGER: 'Quản lý',
  DEPT_HEAD: 'Trưởng bộ phận',
  HR: 'Nhân sự',
  DIRECTOR: 'Giám đốc',
  ACCOUNTANT: 'Kế toán',
  ADMIN: 'Quản trị',
};

// Trạng thái EN (ApprovalFlow) hoặc VN → nhãn VN chuẩn dùng cho ApprovalTimeline.
const STATUS_MAP = {
  PENDING: 'CHỜ_DUYỆT', 'CHỜ_DUYỆT': 'CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ': 'CHỜ_DUYỆT',
  APPROVED: 'ĐÃ_DUYỆT', 'ĐÃ_DUYỆT': 'ĐÃ_DUYỆT', COMPLETED: 'ĐÃ_DUYỆT',
  REJECTED: 'TỪ_CHỐI', 'TỪ_CHỐI': 'TỪ_CHỐI',
  CANCELLED: 'ĐÃ_HỦY', 'ĐÃ_HỦY': 'ĐÃ_HỦY',
};

const normStatus = (s) => STATUS_MAP[String(s || '').toUpperCase()] || 'CHỜ_DUYỆT';

// Nhãn trạng thái đơn TIẾNG VIỆT dùng chung cho BẢNG DANH SÁCH (khớp với timeline).
// Nhận mọi dạng: lowercase (leave/OT), UPPERCASE (profile-change), nhãn VN cũ.
export function statusVN(status) {
  const s = String(status || '').toUpperCase();
  if (['ĐÃ_DUYỆT', 'HOÀN_THÀNH', 'APPROVED', 'COMPLETED', 'DONE'].includes(s)) return 'Đã duyệt';
  if (['TỪ_CHỐI', 'REJECTED'].includes(s)) return 'Từ chối';
  if (['ĐÃ_HỦY', 'CANCELLED', 'CANCELED'].includes(s)) return 'Đã hủy';
  if (['CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ', 'PENDING', 'IN_PROGRESS'].includes(s)) return 'Chờ duyệt';
  return status ? String(status) : '—';
}

// Variant cho <BaseBadge> theo trạng thái (success/warning/destructive/default).
export function statusVariant(status) {
  const s = String(status || '').toUpperCase();
  if (['ĐÃ_DUYỆT', 'HOÀN_THÀNH', 'APPROVED', 'COMPLETED', 'DONE'].includes(s)) return 'success';
  if (['TỪ_CHỐI', 'REJECTED', 'ĐÃ_HỦY', 'CANCELLED', 'CANCELED'].includes(s)) return 'destructive';
  if (['CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ', 'PENDING', 'IN_PROGRESS'].includes(s)) return 'warning';
  return 'default';
}
const isDecided = (s) => ['APPROVED', 'ĐÃ_DUYỆT', 'REJECTED', 'TỪ_CHỐI'].includes(String(s || '').toUpperCase());

/**
 * @param {object} request - đơn (có .status, .approval (meta), .created_at...).
 * @param {object} opts
 * @param {string} opts.creatorName  - tên người tạo đơn (caller tự resolve).
 * @param {(id:number)=>string} [opts.resolveName] - id NV → tên (để hiện người duyệt).
 * @param {number|string} [opts.approverId] - id người duyệt cuối (cho đơn 1 cấp).
 * @returns {Array} steps cho <ApprovalTimeline>.
 */
export function buildApprovalSteps(request, { creatorName, resolveName, approverId } = {}) {
  const req = request || {};
  const steps = [{
    step_name: 'Khởi tạo đơn',
    approver_name: creatorName || 'Nhân viên',
    status: 'ĐÃ_DUYỆT',
    action_date: req.created_at || req.createdAt || null,
  }];

  const ap = req.approval;
  // Duyệt nhiều cấp thực tế.
  if (ap && Array.isArray(ap.steps) && ap.steps.length) {
    ap.steps.forEach((s, idx) => {
      const role = String(s.role || '').toUpperCase();
      const roleLabel = ROLE_LABELS[role] || role || `Cấp ${idx + 1}`;
      const decided = isDecided(s.status);
      const who = decided && resolveName && s.approver_id ? (resolveName(s.approver_id) || roleLabel) : `Chờ ${roleLabel} duyệt`;
      steps.push({
        step_name: `Cấp ${idx + 1} · ${roleLabel}`,
        approver_name: who,
        status: normStatus(s.status),
        action_date: s.at || null,
        comment: s.comment || null,
      });
    });
    return steps;
  }

  // Đơn 1 cấp (không cấu hình chuỗi): suy 1 bước duyệt từ trạng thái tổng.
  const overall = normStatus(req.status);
  const decided = overall === 'ĐÃ_DUYỆT' || overall === 'TỪ_CHỐI';
  let who;
  if (decided) {
    // Đã xử lý: hiện tên người duyệt nếu có, không thì "Người duyệt" (KHÔNG để "Chờ duyệt").
    const resolved = resolveName && (approverId || req.approver_id) ? resolveName(approverId || req.approver_id) : null;
    who = resolved || 'Người duyệt';
  } else if (overall === 'ĐÃ_HỦY') {
    who = 'Đã hủy';
  } else {
    who = 'Chờ duyệt';
  }
  steps.push({
    step_name: 'Duyệt đơn',
    approver_name: who,
    status: overall === 'CHỜ_DUYỆT' ? 'CHỜ_DUYỆT' : overall,
    action_date: req.decided_at || req.approved_at || req.updated_at || null,
  });
  return steps;
}
