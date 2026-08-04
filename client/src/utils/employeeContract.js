export const parseContractMeta = (contract) => {
  const value = contract?.meta;
  if (!value) return {};
  if (typeof value === 'object') return value;

  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
};

export const contractSignStatus = (contract) =>
  parseContractMeta(contract).sign_status || contract?.sign_status || null;

export const contractSignatureImage = (contract) => {
  const signature = parseContractMeta(contract).signature || contract?.signature;
  if (typeof signature === 'string') return signature;
  return signature?.image || '';
};

export const contractStatusLabel = (contract) => {
  const status = contractSignStatus(contract);
  if (status === 'SIGNED') return 'Đã ký';
  if (status === 'PENDING_SIGN') return 'Cần ký';
  return 'Chưa gửi ký';
};
