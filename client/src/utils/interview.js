const BUSINESS_TIMEZONE = 'Asia/Ho_Chi_Minh';

export const interviewDateOnly = (value) => {
  const raw = String(value || '');
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;

  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return raw.slice(0, 10);
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: BUSINESS_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).formatToParts(parsed);
  const get = (type) => parts.find((part) => part.type === type)?.value || '';
  return `${get('year')}-${get('month')}-${get('day')}`;
};

export const formatInterviewDateTime = (item) => {
  if (!item?.interview_date) return '-';
  const [year, month, day] = interviewDateOnly(item.interview_date).split('-');
  const time = String(item.interview_time || '00:00').slice(0, 5);
  return `${time} ${day}/${month}/${year}`;
};

export const isLink = (value) => {
  if (!value) return false;
  return value.startsWith('http://') || value.startsWith('https://');
};

export const isUsableMeetingLink = (value) => {
  if (!isLink(value)) return false;
  try {
    const url = new URL(value);
    if (url.hostname !== 'meet.google.com') return true;
    const path = url.pathname.replace(/^\/+|\/+$/g, '');
    return /^[a-z]{3}-[a-z]{4}-[a-z]{3}$/i.test(path)
      || path.toLowerCase().startsWith('lookup/');
  } catch {
    return false;
  }
};

export const meetingUrl = (item) => {
  if (isUsableMeetingLink(item?.meeting_link)) return item.meeting_link;
  if (isUsableMeetingLink(item?.location)) return item.location;
  return '';
};
