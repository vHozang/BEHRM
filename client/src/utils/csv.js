const escapeCell = (value) => {
  let cell = value ?? '';
  if (typeof cell === 'string' && /^[=+\-@\t\r]/.test(cell)) cell = `'${cell}`;
  return `"${String(cell).replace(/"/g, '""')}"`;
};

export const toCsv = (rows) => `\uFEFF${rows.map(row => row.map(escapeCell).join(',')).join('\n')}`;

// Parse CSV (hỗ trợ ô có dấu phẩy/xuống dòng trong ngoặc kép, BOM, CRLF).
// Trả mảng các mảng ô; dòng rỗng bị bỏ.
export const parseCsv = (text) => {
  const s = String(text).replace(/^﻿/, '');
  const rows = [];
  let row = [], cell = '', inQ = false;
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (inQ) {
      if (c === '"') { if (s[i + 1] === '"') { cell += '"'; i++; } else inQ = false; }
      else cell += c;
    } else if (c === '"') inQ = true;
    else if (c === ',') { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && s[i + 1] === '\n') i++;
      row.push(cell); cell = '';
      if (row.some(x => x.trim() !== '')) rows.push(row);
      row = [];
    } else cell += c;
  }
  row.push(cell);
  if (row.some(x => x.trim() !== '')) rows.push(row);
  return rows;
};

export const downloadCsv = (rows, fileName) => {
  const url = URL.createObjectURL(new Blob([toCsv(rows)], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName.endsWith('.csv') ? fileName : `${fileName}.csv`;
  link.click();
  URL.revokeObjectURL(url);
};
