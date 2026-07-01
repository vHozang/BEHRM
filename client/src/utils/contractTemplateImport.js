// Import a company contract file (.docx / .pdf) in the browser, convert it to
// HTML, and auto-detect the blank fields that need filling so the admin can map
// them to system merge fields ({{key}}). No server/composer dependency.
//
// - DOCX: converted via `mammoth` (high fidelity, structured XML).
// - PDF:  text extracted via `pdfjs-dist` (best-effort; layout/structure is lossy).
//
// Detection replaces each blank with an ASCII sentinel so repeated/identical
// blanks stay distinct; applyMapping() swaps each sentinel for {{key}} or restores
// the original text when left unmapped.

const SENT_OPEN = '@@PCRF';   // private contract-field marker (unlikely in real text)
const SENT_CLOSE = 'FRCP@@';

const escapeHtml = (s) => String(s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const stripTags = (s) => String(s).replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

const deAccent = (s) => String(s).toLowerCase().normalize('NFD')
  .replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');

// label keyword -> system field key. Ordered: first match wins.
const SUGGEST_RULES = [
  [['ho va ten', 'ho ten', 'ten nhan vien', 'ten nguoi lao dong'], 'ho_ten'],
  [['ma nhan vien', 'ma nv', 'ma so nhan vien'], 'ma_nhan_vien'],
  [['ngay sinh', 'sinh ngay', 'ngay thang nam sinh'], 'ngay_sinh'],
  [['gioi tinh'], 'gioi_tinh'],
  [['ngay cap'], 'ngay_cap_cccd'],
  [['noi cap'], 'noi_cap_cccd'],
  [['cccd', 'cmnd', 'cmt', 'can cuoc', 'chung minh'], 'cccd'],
  [['thuong tru', 'dia chi'], 'dia_chi_thuong_tru'],
  [['so dien thoai', 'dien thoai', 'sdt', 'so dt'], 'sdt'],
  [['email'], 'email_ca_nhan'],
  [['chuc danh', 'chuc vu', 'vi tri cong viec', 'vi tri'], 'chuc_danh'],
  [['phong ban', 'bo phan', 'phong'], 'phong_ban'],
  [['loai hop dong'], 'loai_hop_dong'],
  [['so hop dong', 'so hd', 'hop dong so'], 'so_hop_dong'],
  [['ngay bat dau', 'hieu luc tu', 'bat dau tu'], 'ngay_bat_dau'],
  [['ngay ket thuc', 'het han', 'den ngay'], 'ngay_ket_thuc'],
  [['thoi han hop dong', 'thoi han'], 'thoi_han'],
  [['luong co ban', 'muc luong', 'tien luong', 'luong'], 'muc_luong'],
  [['phu cap'], 'phu_cap'],
  [['ma so thue', 'mst'], 'ma_so_thue_cong_ty'],
  [['nguoi dai dien', 'dai dien theo phap luat', 'dai dien'], 'nguoi_dai_dien'],
  [['ten cong ty', 'cong ty', 'don vi', 'ben su dung lao dong', 'ben a'], 'cong_ty'],
  [['ngay ky', 'ngay lap'], 'ngay_ky'],
];

export const suggestKey = (label) => {
  const t = deAccent(label || '');
  for (const [keywords, key] of SUGGEST_RULES) {
    if (keywords.some((k) => t.includes(k))) return key;
  }
  return '';
};

// Convert an uploaded file to HTML. Returns { html, format, warning? }.
export async function parseFileToHtml(file) {
  const name = (file?.name || '').toLowerCase();

  if (name.endsWith('.docx')) {
    const arrayBuffer = await file.arrayBuffer();
    // Ưu tiên docx-preview để GIỮ NGUYÊN ĐỊNH DẠNG (font, canh lề, bảng, in đậm…)
    // bằng cách nhúng luôn <style> của Word vào nội dung mẫu.
    try {
      const { renderAsync } = await import('docx-preview');
      // inWrapper:true để giữ lớp .docx-wrapper/.docx mà CSS sinh ra trỏ tới →
      // định dạng (font, canh lề, bảng, in đậm…) mới áp dụng đúng. Style + nội
      // dung vào 2 container riêng rồi ghép, tránh việc cùng container bị xóa.
      const styleEl = document.createElement('div');
      const bodyEl = document.createElement('div');
      await renderAsync(arrayBuffer, bodyEl, styleEl, {
        inWrapper: true,
        ignoreWidth: true,
        ignoreHeight: true,
        breakPages: false,
        ignoreLastRenderedPageBreak: true,
        experimental: true,
      });
      const html = (styleEl.innerHTML || '') + (bodyEl.innerHTML || '');
      if (html.trim()) {
        return { html, format: 'docx' };
      }
    } catch (e) {
      // ngã về mammoth nếu docx-preview lỗi
    }
    const mod = await import('mammoth');
    const mammoth = mod && mod.convertToHtml ? mod : (mod.default || mod);
    const result = await mammoth.convertToHtml({ arrayBuffer });
    return { html: result.value || '', format: 'docx', warning: 'Đã dùng chế độ chuyển đổi đơn giản (mất một phần định dạng).' };
  }

  if (name.endsWith('.pdf')) {
    const pdfjsLib = await import('pdfjs-dist');
    try {
      pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString();
    } catch (e) { /* fallback to library default */ }
    const data = new Uint8Array(await file.arrayBuffer());
    const pdf = await pdfjsLib.getDocument({ data }).promise;
    let html = '';
    for (let p = 1; p <= pdf.numPages; p++) {
      const page = await pdf.getPage(p);
      const tc = await page.getTextContent();
      let line = '', lastY = null, pageText = '';
      for (const it of tc.items) {
        const y = Array.isArray(it.transform) ? Math.round(it.transform[5]) : null;
        if (lastY !== null && y !== null && Math.abs(y - lastY) > 3) {
          pageText += line.trim() + '\n';
          line = '';
        }
        line += (it.str || '') + (it.hasEOL ? '\n' : ' ');
        lastY = y;
      }
      pageText += line;
      html += pageText.split(/\n+/).map((s) => s.trim()).filter(Boolean)
        .map((s) => `<p>${escapeHtml(s)}</p>`).join('');
    }
    return { html, format: 'pdf', warning: 'PDF duoc trich xuat dang van ban (best-effort) - bo cuc/bang co the lech, nen ra lai trong trinh soan thao.' };
  }

  throw new Error('Chi ho tro tep .docx hoac .pdf');
}

// Detect blank fields. Returns { content (with sentinels), fields:[{id,kind,label,originalText,key}] }.
export function scanTemplate(fullHtml) {
  // Giữ nguyên phần <style> đầu (CSS của docx-preview) — KHÔNG quét trong đó để
  // tránh nhận nhầm `[selector]`/`...` trong CSS thành trường trống.
  const styleEnd = String(fullHtml).lastIndexOf('</style>');
  const head = styleEnd >= 0 ? fullHtml.slice(0, styleEnd + 8) : '';
  const html = styleEnd >= 0 ? fullHtml.slice(styleEnd + 8) : fullHtml;

  const re = /(\{\{\s*\w+\s*\}\})|(\[[^\]\n]{1,60}\])|(_{3,})|(\.{3,}|…+)/g;
  let m, out = '', last = 0, idx = 0;
  const fields = [];

  while ((m = re.exec(html)) !== null) {
    out += html.slice(last, m.index);
    if (m[1]) {
      out += m[1]; // existing {{token}} - keep as-is
    } else {
      // Nhãn = đoạn chữ ngay trước chỗ trống. Bỏ các sentinel của chỗ trống
      // phía trước (nếu không nhãn sẽ lẫn "@@PCRF..@@").
      const ctx = stripTags(out).slice(-90)
        .replace(new RegExp(SENT_OPEN + '\\d+' + SENT_CLOSE, 'g'), ' ')
        .replace(/\S*(PCRF|FRCP)\S*/g, ' ') // dọn mảnh sentinel bị cắt ở mép cửa sổ
        .replace(/\s+/g, ' ')
        .replace(/[:\-–.]\s*$/, '')
        .trim();
      const tail = (ctx.split(/[.;]/).pop() || ctx).trim();
      fields.push({
        id: idx,
        kind: m[2] ? 'bracket' : (m[3] ? 'underscore' : 'dotted'),
        label: tail.slice(-45) || ('Truong ' + (idx + 1)),
        originalText: m[0],
        key: m[2] ? (suggestKey(m[2]) || suggestKey(tail)) : suggestKey(tail),
      });
      out += `${SENT_OPEN}${idx}${SENT_CLOSE}`;
      idx++;
    }
    last = m.index + m[0].length;
  }
  out += html.slice(last);
  return { content: head + out, fields };
}

// Replace sentinels with {{key}} (mapped) or the original blank text (unmapped).
export function applyMapping(content, fields) {
  let out = content;
  for (const f of fields) {
    const re = new RegExp(SENT_OPEN + f.id + SENT_CLOSE, 'g');
    out = out.replace(re, f.key ? `{{${f.key}}}` : f.originalText);
  }
  return out;
}
