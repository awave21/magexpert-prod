import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { useToast } from 'vue-toastification';

export const useAdminStore = defineStore('admin', () => {
  // Инициализация toast
  const toast = useToast();

  // состояние как ref
  const isMobileMenuOpen = ref(false);
  const darkMode = ref(false);
  const notifications = ref([]);
  const isNotificationDrawerOpen = ref(false);
  const notificationFilters = ref({
    read: 'all', // all, read, unread
    type: 'all', // all, order, user, payment, system
    search: '',
  });
  
  // геттеры как computed
  const unreadNotificationsCount = computed(() => {
    return notifications.value.filter(n => !n.read).length;
  });
  
  const filteredNotifications = computed(() => {
    return notifications.value.filter(notification => {
      // Фильтрация по статусу прочтения
      if (notificationFilters.value.read !== 'all') {
        const isRead = notification.read;
        if (notificationFilters.value.read === 'read' && !isRead) return false;
        if (notificationFilters.value.read === 'unread' && isRead) return false;
      }

      // Фильтрация по типу
      if (notificationFilters.value.type !== 'all' && notification.type !== notificationFilters.value.type) {
        return false;
      }

      // Фильтрация по поисковому запросу
      if (notificationFilters.value.search && !notification.title.toLowerCase().includes(notificationFilters.value.search.toLowerCase()) &&
          !notification.message.toLowerCase().includes(notificationFilters.value.search.toLowerCase())) {
        return false;
      }

      return true;
    }).sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
  });
  
  // действия как функции
  function toggleMobileMenu() {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
    
    // Предотвращаем прокрутку body при открытом меню
    if (isMobileMenuOpen.value) {
      document.body.classList.add('overflow-hidden');
    } else {
      document.body.classList.remove('overflow-hidden');
    }
  }
  
  function closeMobileMenu() {
    isMobileMenuOpen.value = false;
    document.body.classList.remove('overflow-hidden');
  }
  
  function toggleDarkMode() {
    darkMode.value = !darkMode.value;
    localStorage.setItem('darkMode', darkMode.value ? 'true' : 'false');
    
    // Применяем класс к документу с анимацией
    if (darkMode.value) {
      document.documentElement.classList.add('dark');
      document.documentElement.style.colorScheme = 'dark';
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.style.colorScheme = 'light';
    }
    
    // Принудительно перерисовываем компоненты
    document.body.style.display = 'none';
    setTimeout(() => {
      document.body.style.display = '';
    }, 5);
  }
  
  function initDarkMode() {
    // Проверяем сохраненные настройки или системные предпочтения
    const savedDarkMode = localStorage.getItem('darkMode');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    darkMode.value = savedDarkMode === 'true' || (savedDarkMode === null && prefersDark);
    
    // Применяем начальное состояние без анимации
    if (darkMode.value) {
      document.documentElement.classList.add('dark');
      document.documentElement.style.colorScheme = 'dark';
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.style.colorScheme = 'light';
    }

    // Добавляем класс для анимации переходов цветов только после начальной загрузки
    setTimeout(() => {
      document.documentElement.classList.add('theme-transition');
    }, 100);
    
    // Слушаем изменения системной темы
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (localStorage.getItem('darkMode') === null) {
        darkMode.value = e.matches;
        if (darkMode.value) {
          document.documentElement.classList.add('dark');
          document.documentElement.style.colorScheme = 'dark';
        } else {
          document.documentElement.classList.remove('dark');
          document.documentElement.style.colorScheme = 'light';
        }
      }
    });
  }
  
  // Функции для работы с панелью уведомлений
  function toggleNotificationDrawer() {
    isNotificationDrawerOpen.value = !isNotificationDrawerOpen.value;
  }

  function closeNotificationDrawer() {
    isNotificationDrawerOpen.value = false;
  }

  function setNotificationFilter(filter, value) {
    notificationFilters.value[filter] = value;
  }
  
  // Функции для работы с уведомлениями
  function addNotification(notification) {
    // Добавление уведомления с обязательными полями
    const newNotification = {
      id: Date.now(),
      read: false,
      timestamp: new Date(),
      type: 'system', // По умолчанию системное уведомление
      title: 'Уведомление',
      message: '',
      icon: null,
      url: null,
      showToast: true, // Показывать ли тост при добавлении
      ...notification
    };
    
    notifications.value.unshift(newNotification);
    
    // Сохраняем уведомления в localStorage (ограничиваем до 100 последних)
    if (notifications.value.length > 100) {
      notifications.value = notifications.value.slice(0, 100);
    }
    localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
    
    // Показываем toast, если требуется
    if (newNotification.showToast) {
      showToastNotification(newNotification);
    }
    
    return newNotification;
  }
  
  function markNotificationAsRead(id) {
    const notification = notifications.value.find(n => n.id === id);
    if (notification) {
      notification.read = true;
      // Сохраняем обновленные уведомления
      localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
    }
  }
  
  function removeNotification(id) {
    notifications.value = notifications.value.filter(n => n.id !== id);
    // Сохраняем обновленные уведомления
    localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
  }
  
  function markAllNotificationsAsRead() {
    notifications.value.forEach(notification => {
      notification.read = true;
    });
    // Сохраняем обновленные уведомления
    localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
  }
  
  function deleteNotification(id) {
    notifications.value = notifications.value.filter(n => n.id !== id);
    // Сохраняем обновленные уведомления
    localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
  }
  
  function clearNotifications() {
    notifications.value = [];
    localStorage.removeItem('admin_notifications');
  }
  
  // Функция для установки уведомлений из внешнего источника (сервера)
  function setNotifications(notificationsList) {
    if (Array.isArray(notificationsList)) {
      // Преобразуем даты в объекты Date
      notifications.value = notificationsList.map(notification => ({
        ...notification,
        timestamp: new Date(notification.timestamp || notification.created_at)
      }));
      
      // Сохраняем в localStorage
      localStorage.setItem('admin_notifications', JSON.stringify(notifications.value));
    }
  }
  
  // Функция для получения списка уведомлений с сервера
  function fetchNotifications() {
    // Здесь должен быть API запрос для получения уведомлений с сервера
    // Пример:
    /*
    axios.get(route('api.admin.notifications'))
      .then(response => {
        const serverNotifications = response.data.map(n => ({
          ...n,
          timestamp: new Date(n.timestamp)
        }));
        
        // Объединяем с локальными уведомлениями
        const localNotifications = JSON.parse(localStorage.getItem('admin_notifications') || '[]');
        const mergedNotifications = [...serverNotifications, ...localNotifications];
        
        // Убираем дубликаты
        const uniqueNotifications = mergedNotifications.filter((n, index, self) => 
          index === self.findIndex(t => t.id === n.id)
        );
        
        notifications.value = uniqueNotifications;
      })
      .catch(error => {
        console.error('Ошибка при загрузке уведомлений:', error);
      });
    */
    
    // Временно загружаем из localStorage
    try {
      const savedNotifications = localStorage.getItem('admin_notifications');
      if (savedNotifications) {
        notifications.value = JSON.parse(savedNotifications).map(n => ({
          ...n,
          timestamp: new Date(n.timestamp)
        }));
      }
    } catch (error) {
      console.error('Ошибка при загрузке уведомлений из localStorage:', error);
    }
  }
  
  // Добавляет новое уведомление о заказе
  function addOrderNotification(orderData) {
    return addNotification({
      type: 'order',
      title: `Новый заказ #${orderData.id}`,
      message: `Создан новый заказ на сумму ${orderData.amount} руб.`,
      icon: 'shopping-cart',
      url: `/admin/orders/${orderData.id}`,
      data: orderData
    });
  }
  
  // Добавляет новое уведомление о пользователе
  function addUserNotification(userData, action = 'registered') {
    let title, message;
    
    switch (action) {
      case 'registered':
        title = 'Новый пользователь';
        message = `Зарегистрирован новый пользователь: ${userData.name}`;
        break;
      case 'updated':
        title = 'Обновление профиля';
        message = `Пользователь ${userData.name} обновил свой профиль`;
        break;
      default:
        title = 'Действие пользователя';
        message = `Пользователь ${userData.name}: ${action}`;
    }
    
    return addNotification({
      type: 'user',
      title,
      message,
      icon: 'user',
      url: `/admin/users/${userData.id}`,
      data: userData
    });
  }
  
  // Добавляет новое уведомление об оплате
  function addPaymentNotification(paymentData) {
    return addNotification({
      type: 'payment',
      title: `Оплата заказа #${paymentData.order_id}`,
      message: `Получена оплата на сумму ${paymentData.amount} руб.`,
      icon: 'credit-card',
      url: `/admin/orders/${paymentData.order_id}`,
      data: paymentData
    });
  }
  
  // Показывает toast уведомление
  function showToastNotification(notification) {
    let toastType = 'default';
    
    // Определяем тип toast на основе типа уведомления
    switch (notification.type) {
      case 'order':
        toastType = 'success';
        break;
      case 'payment':
        toastType = 'info';
        break;
      case 'system':
        toastType = 'default';
        break;
      case 'error':
        toastType = 'error';
        break;
      case 'warning':
        toastType = 'warning';
        break;
    }
    
    // Создаем кастомный toast с возможностью перехода к уведомлению
    toast[toastType](
      {
        component: {
          template: `
            <div @click="handleClick" class="cursor-pointer flex items-start">
              <div class="flex-1">
                <div class="font-medium">${notification.title}</div>
                <div class="text-sm mt-1">${notification.message}</div>
              </div>
            </div>
          `,
          methods: {
            handleClick() {
              if (notification.url) {
                // Если есть URL, переходим по нему
                window.location.href = notification.url;
              } else {
                // Иначе открываем панель уведомлений
                toggleNotificationDrawer();
              }
              // Помечаем как прочитанное
              markNotificationAsRead(notification.id);
            }
          }
        }
      },
      {
        timeout: 5000,
        closeOnClick: true,
        draggable: true,
        closeButton: true
      }
    );
  }
  
  // Инициализация при создании хранилища
  fetchNotifications();

  return {
    // состояние
    isMobileMenuOpen,
    darkMode,
    notifications,
    isNotificationDrawerOpen,
    notificationFilters,
    
    // геттеры
    unreadNotificationsCount,
    filteredNotifications,
    
    // действия с мобильным меню и темой
    toggleMobileMenu,
    closeMobileMenu,
    toggleDarkMode,
    initDarkMode,
    
    // действия с панелью уведомлений
    toggleNotificationDrawer,
    closeNotificationDrawer,
    setNotificationFilter,
    
    // действия с уведомлениями
    addNotification,
    markNotificationAsRead,
    removeNotification,
    markAllNotificationsAsRead,
    deleteNotification,
    clearNotifications,
    setNotifications,
    fetchNotifications,
    
    // специализированные типы уведомлений
    addOrderNotification,
    addUserNotification,
    addPaymentNotification,
    showToastNotification
  };
}); 