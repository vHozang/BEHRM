const extensionOf = (filename = '') => {
  const match = String(filename).toLowerCase().match(/\.([a-z0-9]+)$/);
  return match?.[1] || '';
};

export const cvPreviewKind = (filename = '', mimeType = '') => {
  const extension = extensionOf(filename);
  const mime = String(mimeType).toLowerCase();

  if (extension === 'pdf' || mime === 'application/pdf') return 'pdf';
  if (extension === 'docx' || mime.includes('wordprocessingml')) return 'docx';
  return 'download';
};

export const cvFilename = (candidate) => candidate?.cv_original_filename
  || candidate?.cv?.original_filename
  || candidate?.cv_path?.split('/').pop()
  || `cv-ung-vien-${candidate?.id || 'unknown'}`;
