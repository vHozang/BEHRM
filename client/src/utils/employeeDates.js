const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export const isValidIsoDate = (value) => {
  const normalized = String(value || '').trim();
  if (!ISO_DATE_PATTERN.test(normalized)) return false;

  const date = new Date(`${normalized}T00:00:00.000Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === normalized;
};

export const validateEmployeeDates = (hireDate, probationEndDate) => {
  const hire = String(hireDate || '').trim();
  const probationEnd = String(probationEndDate || '').trim();
  const errors = { hire_date: '', probation_end_date: '' };

  if (hire && !isValidIsoDate(hire)) {
    errors.hire_date = 'Ngày vào làm không hợp lệ (định dạng chuẩn YYYY-MM-DD).';
  }
  if (probationEnd && !isValidIsoDate(probationEnd)) {
    errors.probation_end_date = 'Ngày hết thử việc không hợp lệ (định dạng chuẩn YYYY-MM-DD).';
  }

  if (!errors.hire_date && !errors.probation_end_date && probationEnd) {
    if (!hire) {
      errors.hire_date = 'Vui lòng nhập ngày vào làm trước khi chọn ngày hết thử việc.';
    } else if (probationEnd < hire) {
      errors.probation_end_date = 'Ngày hết thử việc không được trước ngày vào làm.';
    }
  }

  return errors;
};
