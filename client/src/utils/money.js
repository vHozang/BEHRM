export const normalizeMoneySeparator = (value) => value === ',' ? ',' : '.';

export const moneyDigits = (value) => {
  if (value === null || value === undefined || value === '') return '';
  return String(value).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
};

export const parseMoneyInput = (value) => {
  const digits = moneyDigits(value);
  return digits === '' ? '' : Number(digits);
};

export const formatMoneyInput = (value, separator = '.') => {
  const digits = moneyDigits(value);
  if (digits === '') return '';
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, normalizeMoneySeparator(separator));
};
