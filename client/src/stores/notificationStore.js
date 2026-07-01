import { ref, computed } from 'vue';

const notifications = ref([]);
let notificationId = 0;

export function useNotificationStore() {
  const addNotification = (message, type = 'info') => {
    const id = ++notificationId;
    notifications.value.unshift({
      id,
      message,
      type,
      timestamp: new Date(),
      read: false
    });
    
    if (notifications.value.length > 50) {
      notifications.value = notifications.value.slice(0, 50);
    }
    
    return id;
  };

  const addSuccess = (message) => addNotification(message, 'success');
  const addError = (message) => addNotification(message, 'error');
  const addWarning = (message) => addNotification(message, 'warning');
  const addInfo = (message) => addNotification(message, 'info');

  const markAsRead = (id) => {
    const notification = notifications.value.find(n => n.id === id);
    if (notification) {
      notification.read = true;
    }
  };

  const markAllAsRead = () => {
    notifications.value.forEach(n => n.read = true);
  };

  const clearAll = () => {
    notifications.value = [];
  };

  const removeNotification = (id) => {
    const index = notifications.value.findIndex(n => n.id === id);
    if (index !== -1) {
      notifications.value.splice(index, 1);
    }
  };

  const unreadCount = computed(() => 
    notifications.value.filter(n => !n.read).length
  );

  const allNotifications = computed(() => notifications.value);

  return {
    notifications: allNotifications,
    unreadCount,
    addNotification,
    addSuccess,
    addError,
    addWarning,
    addInfo,
    markAsRead,
    markAllAsRead,
    clearAll,
    removeNotification
  };
}
