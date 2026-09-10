export const integrationEndpointLabel = (url = '') => {
  if (url.includes('100.105.84.89')) return 'Mac (ưu tiên)';
  if (url.includes('100.95.129.101')) return 'Windows (dự phòng)';
  return 'Endpoint bổ sung';
};

export const normalizePercentage = (value) => {
  const number = Number(value);
  if (!Number.isFinite(number)) return 0;
  return Math.abs(number) <= 1 ? number * 100 : number;
};

export const notificationStatusEnabled = (value) => (
  value === true || value === 1 || value === '1' || String(value).toUpperCase() === 'ACTIVE'
);

export const certificateExpiryStatus = (expiryDate, now = new Date()) => {
  if (!expiryDate) return { label: 'Không thời hạn', variant: 'default' };
  const expiry = new Date(`${String(expiryDate).slice(0, 10)}T23:59:59`);
  if (Number.isNaN(expiry.getTime())) return { label: 'Không thời hạn', variant: 'default' };
  if (expiry.getTime() < now.getTime()) return { label: 'Hết hạn', variant: 'error' };
  const days = Math.ceil((expiry.getTime() - now.getTime()) / 86400000);
  if (days <= 30) return { label: 'Sắp hết hạn', variant: 'warning' };
  return { label: 'Còn hạn', variant: 'success' };
};
