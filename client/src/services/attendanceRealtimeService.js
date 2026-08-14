import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axiosClient from './axiosClient';

let echo = null;
let subscribedChannels = [];
let changeCursor = null;
let fallbackTimer = null;
let activeOnChange = null;
let catchUpPromise = null;

const clearFallback = () => {
  if (fallbackTimer) clearInterval(fallbackTimer);
  fallbackTimer = null;
};

const catchUp = async (onChange = activeOnChange) => {
  if (!onChange || catchUpPromise) return catchUpPromise;
  catchUpPromise = (async () => {
    let hasMore = true;
    while (hasMore) {
      const response = await axiosClient.get('/attendance/changes', {
        params: changeCursor ? { since: changeCursor } : {}
      });
      const data = response.pageData || {};
      if (data.next_cursor) changeCursor = data.next_cursor;
      if (data.reset_required) {
        onChange({ change_type: 'reset_required', reset_required: true, cursor: changeCursor });
      }
      for (const event of data.items || []) onChange(event);
      hasMore = Boolean(data.has_more);
    }
  })().finally(() => { catchUpPromise = null; });
  return catchUpPromise;
};

export const attendanceRealtimeService = {
  async connect(onChange, { legalEntityId = null } = {}) {
    attendanceRealtimeService.disconnect();
    changeCursor = null;
    activeOnChange = onChange;
    const response = await axiosClient.get('/attendance/realtime/config', {
      params: legalEntityId ? { legal_entity_id: legalEntityId } : {}
    });
    const config = response.data || {};
    const channels = Array.isArray(config.channels)
      ? config.channels.filter(Boolean)
      : (config.channel ? [config.channel] : []);
    if (!config.enabled || !config.key || !channels.length) {
      attendanceRealtimeService.startFallback(onChange);
      return false;
    }

    window.Pusher = Pusher;
    echo = new Echo({
      broadcaster: 'reverb',
      key: config.key,
      wsHost: config.host || window.location.hostname,
      wsPort: Number(config.port || 80),
      wssPort: Number(config.port || 443),
      forceTLS: (config.scheme || 'https') === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: '/api/v1/attendance/realtime/auth',
      auth: { headers: { Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}` } }
    });

    subscribedChannels = channels;
    for (const channel of channels) {
      echo.private(channel).listen('.attendance.changed', (event) => {
        if (event.cursor) changeCursor = event.cursor;
        onChange(event);
      });
    }

    const connection = echo.connector?.pusher?.connection;
    connection?.bind('connected', () => {
      clearFallback();
      catchUp(onChange).catch(() => attendanceRealtimeService.startFallback(onChange));
    });
    connection?.bind('unavailable', () => attendanceRealtimeService.startFallback(onChange));
    connection?.bind('failed', () => attendanceRealtimeService.startFallback(onChange));
    connection?.bind('disconnected', () => attendanceRealtimeService.startFallback(onChange));
    return true;
  },

  startFallback(onChange) {
    activeOnChange = onChange;
    if (fallbackTimer) return;
    const poll = () => catchUp(onChange).catch(() => {
        // HTTP attendance remains available while realtime is offline.
      });
    poll();
    fallbackTimer = setInterval(poll, 30000);
  },

  disconnect() {
    clearFallback();
    if (echo) subscribedChannels.forEach((channel) => echo.leave(channel));
    echo?.disconnect();
    echo = null;
    subscribedChannels = [];
    activeOnChange = null;
  }
};
