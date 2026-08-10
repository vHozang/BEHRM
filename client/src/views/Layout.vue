<template>
  <div class="min-h-screen bg-background text-foreground">
    <!-- Mobile Menu Overlay -->
    <div 
      v-if="isMobileMenuOpen" 
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 lg:hidden"
      @click="isMobileMenuOpen = false"
    ></div>

    <!-- Sidebar -->
    <aside 
      :class="[
        'fixed left-0 top-0 h-full w-72 bg-sidebar backdrop-blur-xl border-r border-sidebar-border z-50 transition-transform duration-300 shadow-[18px_0_60px_-46px_rgba(15,23,42,0.45)]',
        isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full',
        isSidebarCollapsed ? 'lg:-translate-x-full' : 'lg:translate-x-0'
      ]"
    >
      <div class="flex h-[4.5rem] items-center justify-between border-b border-sidebar-border/70 px-4 py-4 relative">
        <div class="flex min-w-0 items-center gap-3">
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-sm font-extrabold text-primary">
            HR
          </div>
          <div class="min-w-0">
            <h1 class="truncate text-xl font-bold leading-none" style="font-family: 'Montserrat', sans-serif;">
              <span class="font-extrabold" style="color: #124DA3;">CODE</span><span class="font-extrabold" style="color: #F37022;">DEN</span><span class="font-extrabold" style="color: #4EB748;">NGU</span>
            </h1>
            <span class="mt-1 block text-[10px] font-semibold uppercase tracking-normal text-muted-foreground" style="font-family: Inter, sans-serif;">HRM Workspace</span>
          </div>
        </div>
        <button 
          @click="isMobileMenuOpen = false"
          class="rounded-lg p-2 hover:bg-sidebar-accent lg:hidden"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <nav class="h-[calc(100%-4.5rem)] overflow-y-auto px-3 py-4">
        <!-- Render Dashboards (No heading) -->
        <div class="space-y-1 pb-3">
          <router-link
            v-for="item in dashboardGroup.items"
            :key="item.path"
            :to="item.path"
            @click="isMobileMenuOpen = false"
            class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all duration-200"
            :class="isActive(item.path) 
              ? 'bg-primary/10 text-primary font-semibold ring-1 ring-primary/15' 
              : 'text-sidebar-foreground hover:bg-sidebar-accent/65 hover:text-sidebar-accent-foreground'"
          >
            <component :is="item.icon" class="h-[1.125rem] w-[1.125rem] flex-shrink-0 transition-colors" :class="isActive(item.path) ? 'text-primary' : 'text-muted-foreground group-hover:text-sidebar-accent-foreground'" />
            <span class="text-sm font-medium">{{ item.label }}</span>
          </router-link>
        </div>

        <!-- Render collapsible groups -->
        <div v-for="group in menuGroups" :key="group.id" class="space-y-1 border-t border-sidebar-border/70 py-3 first:border-t-0">
          <button 
            @click="toggleMenu(group)"
            class="flex w-full items-center justify-between px-3 py-1.5 text-[10px] font-bold uppercase tracking-normal text-muted-foreground transition-colors hover:text-foreground"
          >
            <span>{{ group.label }}</span>
            <svg 
              class="w-4 h-4 transition-transform duration-200" 
              :class="group.isOpen ? 'rotate-180' : ''"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          
          <div v-show="group.isOpen" class="mt-1 space-y-1">
            <router-link
              v-for="item in group.items"
              :key="item.path"
              :to="item.path"
              @click="isMobileMenuOpen = false"
              class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all duration-200"
              :class="isActive(item.path) 
                ? 'bg-primary/10 text-primary font-semibold ring-1 ring-primary/15' 
                : 'text-sidebar-foreground hover:bg-sidebar-accent/65 hover:text-sidebar-accent-foreground'"
            >
              <component :is="item.icon" class="h-[1.125rem] w-[1.125rem] flex-shrink-0 transition-colors" :class="isActive(item.path) ? 'text-primary' : 'text-muted-foreground group-hover:text-sidebar-accent-foreground'" />
              <span class="text-sm font-medium">{{ item.label }}</span>
            </router-link>
          </div>
        </div>
      </nav>
    </aside>

    <!-- Main Content -->
    <div :class="['flex-1 flex flex-col overflow-hidden transition-[margin] duration-300', isSidebarCollapsed ? 'lg:ml-0' : 'lg:ml-72']">
      <!-- Top Bar -->
      <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-border/70 bg-background/88 px-4 backdrop-blur-xl sm:px-6">
        <div class="flex min-w-0 items-center gap-4">
          <button
            @click="toggleSidebar"
            class="p-2 rounded-lg hover-elevate active-elevate-2"
            data-testid="button-sidebar-toggle"
            :title="isSidebarCollapsed ? 'Mở thanh điều hướng' : 'Thu gọn thanh điều hướng'"
            aria-label="Bật/tắt thanh điều hướng"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <!-- Breadcrumbs -->
          <div class="hidden min-w-0 sm:block">
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
              <router-link to="/" class="hover:text-foreground">{{ t('breadcrumb.workspace', 'Workspace') }}</router-link>
              <span>/</span>
              <span>HRM</span>
            </div>
            <p class="truncate text-sm font-semibold text-foreground">{{ currentPageTitle }}</p>
          </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
          <!-- Search with Dropdown -->
          <div class="relative hidden md:block" ref="searchContainerRef">
            <input
              v-model="searchQuery"
              type="search"
              :placeholder="t('common.searchPages', 'Tìm kiếm trang...')"
              class="h-10 w-72 rounded-xl border border-input bg-card/90 px-4 py-2 pl-10 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring xl:w-[22rem]"
              data-testid="input-global-search"
              @focus="isSearchFocused = true"
              @keydown.enter="handleSearchEnter"
              @keydown.down.prevent="navigateSearchResults(1)"
              @keydown.up.prevent="navigateSearchResults(-1)"
              @keydown.escape="closeSearch"
            />
            <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            
            <!-- Search Results Dropdown -->
            <div 
              v-if="isSearchFocused && (searchQuery.trim() || filteredNavItems.length > 0)"
              class="absolute left-0 right-0 top-full z-50 mt-2 max-h-64 overflow-y-auto rounded-xl border border-border bg-card shadow-lg"
            >
              <div v-if="filteredNavItems.length === 0 && searchQuery.trim()" class="p-3 text-muted-foreground text-sm text-center">
                Không tìm thấy trang nào
              </div>
              <div v-else class="py-1">
                <div
                  v-for="(item, index) in filteredNavItems"
                  :key="item.path"
                  @click="navigateToPage(item)"
                  :class="[
                    'flex items-center gap-3 px-4 py-2 cursor-pointer transition-colors',
                    selectedSearchIndex === index ? 'bg-primary/10 text-primary' : 'hover:bg-muted'
                  ]"
                >
                  <component :is="item.icon" class="w-4 h-4" />
                  <span class="text-sm">{{ item.label }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick Add (+) -->
          <div v-if="quickAddActions.length" class="relative" ref="quickAddContainerRef">
            <button
              type="button"
              @click="isQuickAddOpen = !isQuickAddOpen"
              class="flex items-center justify-center h-9 w-9 rounded-xl border border-primary/25 bg-primary/10 text-primary shadow-sm transition-colors hover:bg-primary/15"
              data-testid="button-quick-add"
              title="Tạo nhanh"
              aria-label="Tạo nhanh"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
            </button>
            <div
              v-if="isQuickAddOpen"
              class="absolute right-0 top-full z-50 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-card py-1 shadow-lg"
            >
              <p class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Tạo nhanh</p>
              <button
                v-for="action in quickAddActions"
                :key="action.path"
                @click="runQuickAdd(action)"
                class="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-foreground transition-colors hover:bg-muted"
              >
                <component :is="action.icon" class="h-4 w-4 text-muted-foreground" />
                <span>{{ action.label }}</span>
              </button>
            </div>
          </div>

          <!-- AI Assistant button -->
          <button
            type="button"
            @click="openAiAssistant"
            class="hidden items-center gap-1.5 rounded-xl border border-ai/25 bg-ai/10 px-3 py-2 text-sm font-semibold text-ai shadow-sm transition-colors hover:bg-ai/15 sm:inline-flex"
            data-testid="button-ai-assistant"
            :title="t('common.askAi', 'Hỏi HR AI')"
          >
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 2l1.6 4.6L18 8.2l-4.4 1.6L12 14.4l-1.6-4.6L6 8.2l4.4-1.6L12 2zm6.5 9l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6zM6 14l.7 2 2 .7-2 .7L6 19.4 5.3 17.4l-2-.7 2-.7L6 14z" />
            </svg>
            <span>{{ t('common.askAi', 'Hỏi HR AI') }}</span>
          </button>

          <!-- Language switcher -->
          <div class="relative" ref="langContainerRef">
            <button
              type="button"
              @click="toggleLangMenu"
              class="flex items-center gap-1 rounded-xl border border-border bg-card/90 px-2.5 py-2 text-sm font-semibold text-foreground shadow-sm hover-elevate active-elevate-2"
              data-testid="button-language-switcher"
            >
              {{ currentLocaleLabel }}
              <svg class="h-3.5 w-3.5 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div
              v-if="isLangMenuOpen"
              class="absolute right-0 top-full z-50 mt-2 w-28 overflow-hidden rounded-xl border border-border bg-card py-1 shadow-lg"
            >
              <button
                v-for="loc in availableLocales"
                :key="loc.code"
                @click="selectLocale(loc.code)"
                class="flex w-full items-center justify-between px-3 py-2 text-sm transition-colors hover:bg-muted"
                :class="locale === loc.code ? 'text-primary font-semibold' : 'text-foreground'"
              >
                {{ loc.label }}
                <svg v-if="locale === loc.code" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </button>
            </div>
          </div>

          <ThemeToggle />

          <!-- Notifications -->
          <div class="relative" ref="notificationContainerRef">
            <button 
              @click="toggleNotifications"
              class="relative rounded-xl border border-border bg-card/90 p-2.5 shadow-sm hover-elevate active-elevate-2" 
              data-testid="button-notifications"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
              </svg>
              <span v-if="unreadCount > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-destructive text-destructive-foreground text-xs rounded-full flex items-center justify-center font-medium">
                {{ unreadCount }}
              </span>
            </button>
            
            <!-- Notifications Dropdown -->
            <div 
              v-if="isNotificationsOpen"
              class="absolute right-0 top-full z-50 mt-2 w-80 rounded-xl border border-border bg-card shadow-lg"
            >
              <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                <h3 class="font-semibold text-sm">{{ t('common.notifications', 'Thông báo') }}</h3>
                <button
                  @click="markAllAsRead"
                  class="text-xs text-primary hover:underline"
                >
                  {{ t('common.markAllRead', 'Đánh dấu đã đọc') }}
                </button>
              </div>
              <div class="max-h-64 overflow-y-auto">
                <div 
                  v-for="notification in notifications" 
                  :key="notification.id"
                  :class="[
                    'flex items-start gap-3 px-4 py-3 border-b border-border last:border-b-0 cursor-pointer hover:bg-muted/50 transition-colors',
                    !notification.read ? 'bg-primary/5' : ''
                  ]"
                  @click="markAsRead(notification.id)"
                >
                  <div :class="[
                    'w-2 h-2 rounded-full mt-2 flex-shrink-0',
                    notification.read ? 'bg-muted-foreground/30' : 'bg-primary'
                  ]"></div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm">{{ notification.message }}</p>
                    <p class="text-xs text-muted-foreground mt-1">{{ notification.time }}</p>
                  </div>
                </div>
              </div>
              <div v-if="notifications.length === 0" class="p-4 text-center text-muted-foreground text-sm">
                {{ t('common.noNotifications', 'Không có thông báo mới') }}
              </div>
            </div>
          </div>

          <!-- Avatar Dropdown -->
          <div class="relative" ref="avatarContainerRef">
            <button
              @click="toggleAvatarMenu"
              class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-sm font-bold text-primary-foreground shadow-sm transition-opacity hover:opacity-90"
              data-testid="button-avatar"
              :title="currentUserEmail"
            >
              {{ currentUserInitials }}
            </button>

            <!-- Avatar Dropdown Menu -->
            <div
              v-if="isAvatarMenuOpen"
              class="absolute right-0 top-full z-50 mt-2 w-56 rounded-xl border border-border bg-card shadow-lg"
            >
              <div class="px-4 py-3 border-b border-border">
                <p class="text-sm font-medium text-foreground truncate">{{ currentUserEmail }}</p>
                <p class="text-xs text-muted-foreground mt-0.5" :title="currentUserRoleDetails">{{ currentUserRoleLabel }}</p>
              </div>
              <div class="py-1">
                <button
                  @click="openChangePassword"
                  class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-foreground hover:bg-muted transition-colors text-left"
                >
                  <svg class="w-4 h-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                  </svg>
                  {{ t('common.changePassword', 'Đổi mật khẩu') }}
                </button>
                <button
                  @click="handleLogout"
                  class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-destructive hover:bg-destructive/10 transition-colors text-left"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                  </svg>
                  {{ t('common.logout', 'Đăng xuất') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- Change Password Modal -->
      <div v-if="showChangePasswordModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showChangePasswordModal = false">
        <div class="bg-background border border-border rounded-xl shadow-xl w-full max-w-md p-6">
          <h3 class="text-lg font-bold text-foreground mb-4">Đổi mật khẩu</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Mật khẩu hiện tại <span class="text-destructive">*</span></label>
              <input v-model="pwForm.currentPassword" type="password" autocomplete="current-password" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Nhập mật khẩu hiện tại..." />
            </div>
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Mật khẩu mới <span class="text-destructive">*</span></label>
              <input v-model="pwForm.newPassword" type="password" autocomplete="new-password" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Tối thiểu 8 ký tự, gồm chữ và số..." />
            </div>
            <div>
              <label class="block text-sm font-medium text-foreground mb-1">Xác nhận mật khẩu <span class="text-destructive">*</span></label>
              <input v-model="pwForm.confirmPassword" type="password" autocomplete="new-password" class="w-full px-3 py-2 rounded-lg border border-input bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-ring" placeholder="Nhập lại mật khẩu mới..." />
            </div>
            <div v-if="pwError" class="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
              <p class="text-destructive text-sm">{{ pwError }}</p>
            </div>
            <div v-if="pwSuccess" class="p-3 bg-success/10 border border-success/20 rounded-lg">
              <p class="text-success text-sm">{{ pwSuccess }}</p>
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-6">
            <button @click="showChangePasswordModal = false" class="px-4 py-2 rounded-lg border border-border text-foreground hover:bg-muted transition-colors text-sm">Hủy</button>
            <button @click="submitChangePassword" :disabled="pwLoading" class="px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors text-sm font-medium disabled:opacity-50">
              {{ pwLoading ? 'Đang lưu...' : 'Đổi mật khẩu' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Page Content -->
      <main class="flex-1 overflow-auto p-4 pb-20 sm:p-6 lg:pb-6 xl:p-7">
        <router-view />
      </main>
    </div>

    <!-- Mobile Bottom Navigation Bar (Grab Food / Mobile App style) -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 h-16 bg-card/95 backdrop-blur-xl border-t border-border flex items-center justify-around z-40 px-2 pb-safe shadow-lg">
      <router-link 
        v-for="item in mobileNavItems" 
        :key="item.path" 
        :to="item.path"
        class="flex flex-col items-center justify-center flex-1 h-full text-muted-foreground transition-all duration-200"
        active-class="text-primary font-semibold scale-105"
      >
        <component :is="item.icon" class="w-5 h-5" />
        <span class="text-[10px] mt-1">{{ item.label }}</span>
      </router-link>
    </nav>

    <!-- AI Assistant Chat Drawer -->
    <BaseDrawer v-model="isAiOpen" :hide-header="true" width="26rem" test-id="ai-drawer">
      <template #header>
        <div class="flex items-center gap-2">
          <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-ai/25 bg-ai/10 text-ai">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 2l1.6 4.6L18 8.2l-4.4 1.6L12 14.4l-1.6-4.6L6 8.2l4.4-1.6L12 2zm6.5 9l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6zM6 14l.7 2 2 .7-2 .7L6 19.4 5.3 17.4l-2-.7 2-.7L6 14z" />
            </svg>
          </span>
          <h2 class="truncate text-base font-semibold">{{ t('ai.title', 'Trợ lý HR') }}</h2>
        </div>
      </template>

      <!-- Chat body: full-height column with scrollable list + composer -->
      <div class="flex h-full flex-col">
        <!-- Not-configured info note -->
        <div
          v-if="!aiConfigured"
          class="mb-3 flex items-start gap-2 rounded-lg border border-ai/25 bg-ai/10 px-3 py-2 text-xs text-ai"
        >
          <svg class="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ t('ai.notConfigured', 'Trợ lý AI chưa được cấu hình — thêm ANTHROPIC_API_KEY') }}</span>
        </div>

        <!-- Scrollable message list -->
        <div ref="aiMessagesRef" class="flex-1 space-y-3 overflow-y-auto pr-1">
          <div
            v-for="(msg, idx) in aiMessages"
            :key="idx"
            class="flex"
            :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
          >
            <div
              v-if="msg.role === 'assistant'"
              class="max-w-[85%] rounded-2xl rounded-tl-sm border border-border bg-muted/40 px-3.5 py-2.5 text-sm text-foreground"
            >
              <span class="mb-1 inline-flex items-center gap-1 rounded-md border border-ai/25 bg-ai/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-ai">
                <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12 2l1.6 4.6L18 8.2l-4.4 1.6L12 14.4l-1.6-4.6L6 8.2l4.4-1.6L12 2z" />
                </svg>
                {{ t('ai.badge', 'AI') }}
              </span>
              <p class="whitespace-pre-wrap break-words leading-relaxed">{{ msg.content }}</p>
            </div>
            <div
              v-else
              class="max-w-[85%] whitespace-pre-wrap break-words rounded-2xl rounded-tr-sm bg-primary px-3.5 py-2.5 text-sm leading-relaxed text-primary-foreground"
            >
              {{ msg.content }}
            </div>
          </div>

          <!-- Typing indicator -->
          <div v-if="aiLoading" class="flex justify-start">
            <div class="flex items-center gap-1.5 rounded-2xl rounded-tl-sm border border-border bg-muted/40 px-3.5 py-3">
              <span class="h-2 w-2 animate-bounce rounded-full bg-ai/70 [animation-delay:-0.3s]"></span>
              <span class="h-2 w-2 animate-bounce rounded-full bg-ai/70 [animation-delay:-0.15s]"></span>
              <span class="h-2 w-2 animate-bounce rounded-full bg-ai/70"></span>
            </div>
          </div>
        </div>

        <!-- Composer -->
        <form class="mt-3 flex items-end gap-2 border-t border-border pt-3" @submit.prevent="sendAiMessage">
          <textarea
            v-model="aiInput"
            rows="1"
            :placeholder="t('ai.placeholder', 'Nhập câu hỏi cho trợ lý HR...')"
            class="max-h-28 flex-1 resize-none rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
            data-testid="input-ai-message"
            @keydown.enter.exact.prevent="sendAiMessage"
          ></textarea>
          <button
            type="submit"
            :disabled="aiLoading || !aiInput.trim()"
            class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-ai text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
            :title="t('common.send', 'Gửi')"
            data-testid="button-ai-send"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
          </button>
        </form>
      </div>
    </BaseDrawer>
  </div>
</template>

<script setup>
import { ref, shallowRef, onMounted, onUnmounted, computed, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useNotificationStore } from '../stores/notificationStore';
import { notificationService } from '../services/notificationService';
import BaseModal from '../components/BaseModal.vue';
import BaseInput from '../components/BaseInput.vue';
import BaseButton from '../components/BaseButton.vue';
import BaseDrawer from '../components/BaseDrawer.vue';
import { authService } from '../services/authService';
import { primaryUserRoleLabel, userRoleLabels } from '../utils/userRole';
import { aiService } from '../services/aiService';
import { useI18n } from '../i18n';
import ThemeToggle from '../components/ThemeToggle.vue';
import IconDashboard from '../components/IconDashboard.vue';
import IconUser from '../components/IconUser.vue';
import IconBuilding from '../components/IconBuilding.vue';
import IconClock from '../components/IconClock.vue';
import IconCalendar from '../components/IconCalendar.vue';
import IconCash from '../components/IconCash.vue';
import IconShield from '../components/IconShield.vue';
import IconBriefcase from '../components/IconBriefcase.vue';
import IconFileText from '../components/IconFileText.vue';
import IconBox from '../components/IconBox.vue';
import IconNewspaper from '../components/IconNewspaper.vue';
import IconShieldCheck from '../components/IconShieldCheck.vue';
import IconSupport from '../components/IconSupport.vue';

const route = useRoute();
const router = useRouter();
const isMobileMenuOpen = ref(false);

// Desktop sidebar collapse (persisted). On large screens the hamburger collapses
// the sidebar to give the content full width; on small screens it opens the
// off-canvas drawer instead.
const isSidebarCollapsed = ref(localStorage.getItem('sidebar_collapsed') === '1');
const toggleSidebar = () => {
  if (typeof window !== 'undefined' && window.innerWidth >= 1024) {
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
    localStorage.setItem('sidebar_collapsed', isSidebarCollapsed.value ? '1' : '0');
  } else {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
  }
};

// Quick Add (+) — create-shortcuts gated by admin shell + module access.
const isQuickAddOpen = ref(false);
const quickAddContainerRef = ref(null);
const QUICK_ADD = [
  { label: 'Xin nghỉ phép', path: '/leaves', module: 'time', icon: IconCalendar },
  { label: 'Đơn điều chỉnh công', path: '/attendance-adjustments', module: 'time', icon: IconClock },
  { label: 'Đăng ký tăng ca', path: '/overtime-requests', module: 'time', icon: IconClock },
  { label: 'Thêm nhân viên', path: '/employees', module: 'hr', icon: IconUser },
  { label: 'Tạo hợp đồng', path: '/contracts', module: 'hr', icon: IconFileText },
  { label: 'Checklist hội nhập', path: '/onboarding', module: 'hr', icon: IconUser },
];
const quickAddActions = computed(() =>
  isAdmin.value ? QUICK_ADD.filter((a) => authService.canAccessModule(a.module)) : []
);
const runQuickAdd = (action) => {
  isQuickAddOpen.value = false;
  router.push(action.path);
};

// i18n
const { t, locale, setLocale, availableLocales, currentLocaleLabel } = useI18n();

// Language switcher dropdown
const isLangMenuOpen = ref(false);
const langContainerRef = ref(null);
const toggleLangMenu = () => {
  isLangMenuOpen.value = !isLangMenuOpen.value;
  isNotificationsOpen.value = false;
  isAvatarMenuOpen.value = false;
};
const selectLocale = (code) => {
  setLocale(code);
  isLangMenuOpen.value = false;
};

// ── AI assistant chat drawer ────────────────────────────────────────────────
const isAiOpen = ref(false);
const aiMessages = ref([]);        // { role: 'user'|'assistant', content, badge?, configured? }
const aiInput = ref('');
const aiLoading = ref(false);
const aiConfigured = ref(true);    // flips false when backend reports missing key
const aiMessagesRef = ref(null);

const scrollAiToBottom = () => {
  nextTick(() => {
    const el = aiMessagesRef.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
};

const openAiAssistant = () => {
  isLangMenuOpen.value = false;
  isNotificationsOpen.value = false;
  isAvatarMenuOpen.value = false;
  isAiOpen.value = true;
  if (aiMessages.value.length === 0) {
    aiMessages.value.push({ role: 'assistant', content: t('ai.greeting') });
  }
  scrollAiToBottom();
};

const sendAiMessage = async () => {
  const text = aiInput.value.trim();
  if (!text || aiLoading.value) return;

  aiMessages.value.push({ role: 'user', content: text });
  aiInput.value = '';
  aiLoading.value = true;
  scrollAiToBottom();

  // Send the recent history (last ~6 turns), excluding the greeting bubble and
  // the message we just pushed, as plain {role, content} pairs.
  const history = aiMessages.value
    .filter((m) => m.role === 'user' || m.role === 'assistant')
    .slice(0, -1)            // drop the just-added user message (sent as `message`)
    .slice(-6)
    .map((m) => ({ role: m.role, content: m.content }));

  try {
    const data = await aiService.ask(text, history);
    aiConfigured.value = data?.configured !== false;
    aiMessages.value.push({
      role: 'assistant',
      content: data?.answer || t('ai.error'),
      configured: aiConfigured.value,
    });
  } catch (e) {
    aiMessages.value.push({ role: 'assistant', content: t('ai.error') });
  } finally {
    aiLoading.value = false;
    scrollAiToBottom();
  }
};

const searchQuery = ref('');
const isSearchFocused = ref(false);
const selectedSearchIndex = ref(0);
const searchContainerRef = ref(null);

const isNotificationsOpen = ref(false);
const notificationContainerRef = ref(null);

// Avatar dropdown
const isAvatarMenuOpen = ref(false);
const avatarContainerRef = ref(null);
const showChangePasswordModal = ref(false);
const pwForm = ref({ currentPassword: '', newPassword: '', confirmPassword: '' });
const pwError = ref('');
const pwSuccess = ref('');
const pwLoading = ref(false);

const currentUser = ref(authService.getUser());
const currentUserEmail = computed(() => currentUser.value?.company_email || authService.getUserEmail() || 'Người dùng');
const currentUserInitials = computed(() => {
  const email = currentUserEmail.value;
  const user = currentUser.value;
  const name = user?.name || user?.full_name || email;
  return name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2) || 'U';
});
const currentUserRoleLabel = computed(() => primaryUserRoleLabel(currentUser.value));
const currentUserRoleDetails = computed(() => userRoleLabels(currentUser.value).join(' · ') || currentUserRoleLabel.value);

const notificationStore = useNotificationStore();
const storeNotifications = notificationStore.notifications;
const storeUnreadCount = notificationStore.unreadCount;

// Thông báo bền từ backend (chuông) — gộp với toast phiên hiện tại.
const persistedNotifications = ref([]);
const loadPersisted = async () => {
  try {
    persistedNotifications.value = await notificationService.getAll();
  } catch (e) {
    /* im lặng — chuông không chặn UI */
  }
};

const isAdmin = computed(() => authService.isAdmin());
const canUseDashboard = computed(() => {
  const access = authService.getAccess();
  return access.full || access.modules.some(module => ['hr', 'time', 'recruitment'].includes(module));
});
const isSuperAdmin = computed(() => authService.isSuperAdmin());

const notifications = computed(() => {
  const persisted = persistedNotifications.value.map(n => ({
    id: 'db-' + n.id,
    _persisted: true,
    _rawId: n.id,
    // message có thể null (dữ liệu legacy) → chỉ nối khi có, tránh "Tiêu đề: null".
    message: [n.title, n.message].filter(Boolean).join(': ') || 'Thông báo',
    time: formatTime(new Date(n.created_at)),
    read: !!n.read_at,
  }));
  const toasts = storeNotifications.value.map(n => ({
    id: 'toast-' + n.id,
    _persisted: false,
    _rawId: n.id,
    message: n.message,
    time: formatTime(n.timestamp),
    read: n.read,
  }));
  return [...persisted, ...toasts];
});

const unreadCount = computed(() =>
  persistedNotifications.value.filter(n => !n.read_at).length + storeUnreadCount.value
);

const formatTime = (date) => {
  if (!date) return '';
  const now = new Date();
  const diff = now - new Date(date);
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  
  if (minutes < 1) return 'Vừa xong';
  if (minutes < 60) return `${minutes} phút trước`;
  if (hours < 24) return `${hours} giờ trước`;
  if (days === 1) return 'Hôm qua';
  return `${days} ngày trước`;
};

const handleLogout = () => authService.logout();
const navGroupsData = shallowRef([
  {
    id: 'dashboard',
    label: 'Tổng quan',
    isOpen: true,
    items: [
      { path: '/', name: 'dashboard', label: 'Dashboard', icon: IconDashboard, adminOnly: true }
    ]
  },
  {
    id: 'hr',
    label: 'Nhân sự & Hợp đồng',
    isOpen: true,
    items: [
      { path: '/employees', name: 'employees', label: 'Nhân viên', icon: IconUser, adminOnly: true },
      { path: '/organization-chart', name: 'organization-chart', label: 'Sơ đồ tổ chức', icon: IconBuilding, adminOnly: true },
      { path: '/contracts', name: 'contracts', label: 'Hợp đồng lao động', icon: IconFileText, adminOnly: true },
      { path: '/onboarding', name: 'onboarding', label: 'Hội nhập & Nghỉ việc', icon: IconUser, adminOnly: true },
      { path: '/departments', name: 'departments', label: 'Phòng ban', icon: IconBuilding, adminOnly: true },
      { path: '/profile-change-requests', name: 'profile-change-requests', label: 'Đơn đổi thông tin', icon: IconFileText, adminOnly: true },
      { path: '/personnel-decisions', name: 'personnel-decisions', label: 'Quyết định nhân sự', icon: IconShieldCheck, adminOnly: true },
    ]
  },
  {
    id: 'recruitment',
    label: 'Tuyển dụng & AI',
    isOpen: true,
    items: [
      { path: '/recruitment', name: 'recruitment', label: 'Ứng viên (Kanban)', icon: IconBriefcase, adminOnly: true },
      { path: '/recruitment-positions', name: 'recruitment-positions', label: 'Tin tuyển dụng', icon: IconBriefcase, adminOnly: true },
      { path: '/interviews', name: 'interviews', label: 'Lịch phỏng vấn', icon: IconCalendar, adminOnly: true }
    ]
  },
  {
    id: 'time',
    label: 'Công & Lịch',
    isOpen: true,
    items: [
      { path: '/attendance', name: 'attendance', label: 'Chấm công', icon: IconClock, adminOnly: true },
      { path: '/timesheet', name: 'timesheet', label: 'Bảng công tháng', icon: IconClock, adminOnly: true },
      { path: '/shifts', name: 'shifts', label: 'Ca làm việc & Xếp ca', icon: IconClock, adminOnly: true },
      { path: '/attendance-adjustments', name: 'attendance-adjustments', label: 'Đơn điều chỉnh công', icon: IconClock, adminOnly: true },
      { path: '/leaves', name: 'leaves', label: 'Nghỉ phép', icon: IconCalendar, adminOnly: true },
      { path: '/holidays', name: 'holidays', label: 'Lịch nghỉ lễ', icon: IconCalendar, adminOnly: true },
      { path: '/overtime-requests', name: 'overtime-requests', label: 'Đăng ký tăng ca', icon: IconClock, adminOnly: true },
      { path: '/shift-swaps', name: 'shift-swaps', label: 'Yêu cầu đổi ca', icon: IconCalendar, adminOnly: true },
      { path: '/shift-coverage', name: 'shift-coverage', label: 'Phủ ca khi vắng', icon: IconClock, adminOnly: true },
      { path: '/requests', name: 'requests', label: 'Yêu cầu phê duyệt', icon: IconFileText, adminOnly: true },
    ]
  },
  {
    id: 'payroll',
    label: 'Lương & Báo cáo',
    isOpen: true,
    items: [
      { path: '/salaries', name: 'salaries', label: 'Tính lương', icon: IconCash, adminOnly: true },
      { path: '/salary-components', name: 'salary-components', label: 'Thành phần lương', icon: IconCash, adminOnly: true },
      { path: '/piece-rate', name: 'piece-rate', label: 'Công khoán sản phẩm', icon: IconCash, adminOnly: true },
      { path: '/report-builder', name: 'report-builder', label: 'Báo cáo tùy chỉnh', icon: IconFileText, adminOnly: true }
    ]
  },
  {
    id: 'communications',
    label: 'Truyền thông',
    isOpen: false,
    items: [
      { path: '/news', name: 'news', label: 'Tin tức nội bộ', icon: IconNewspaper, adminOnly: false },
      { path: '/policies', name: 'policies', label: 'Chính sách công ty', icon: IconShieldCheck, adminOnly: false },
      { path: '/service-tickets', name: 'service-tickets', label: 'Hỗ trợ nội bộ', icon: IconSupport, adminOnly: false }
    ]
  },
  {
    id: 'settings',
    label: 'Cấu hình',
    isOpen: false,
    items: [
      { path: '/settings', name: 'settings', label: 'Cấu hình nghiệp vụ', icon: IconShieldCheck, adminOnly: true },
      { path: '/attendance-devices', name: 'attendance-devices', label: 'Máy chấm công', icon: IconClock, adminOnly: true },
      { path: '/job-families', name: 'job-families', label: 'Nhóm chức danh', icon: IconBuilding, adminOnly: true },
      { path: '/job-titles', name: 'job-titles', label: 'Chức danh', icon: IconUser, adminOnly: true },
      { path: '/roles', name: 'roles', label: 'Vai trò & Phân quyền', icon: IconShield, adminOnly: true },
      { path: '/legal-entities', name: 'legal-entities', label: 'Chi nhánh / Pháp nhân', icon: IconBuilding, adminOnly: true },
      { path: '/audit-logs', name: 'audit-logs', label: 'Nhật ký hệ thống', icon: IconFileText, adminOnly: true },
    ]
  },
  {
    id: 'platform',
    label: 'Quản trị Platform',
    isOpen: false,
    superAdminOnly: true,
    items: [
      { path: '/platform/tenants', name: 'platform-tenants', label: 'Tổ chức (Tenant)', icon: IconBuilding, adminOnly: true },
    ]
  }
]);

const filteredGroups = computed(() => {
  if (!isAdmin.value) {
    return [
      {
        id: 'employee',
        label: 'Nhân viên',
        isOpen: true,
        items: [
          { path: '/employee-portal', name: 'employee-portal', label: 'Tổng quan Portal', icon: IconDashboard, adminOnly: false },
          { path: '/employee-contracts', name: 'employee-contracts', label: 'Hợp đồng lao động', icon: IconFileText, adminOnly: false },
          { path: '/attendance', name: 'attendance', label: 'Chấm công', icon: IconClock, adminOnly: false },
          { path: '/leaves', name: 'leaves', label: 'Nghỉ phép', icon: IconCalendar, adminOnly: false },
          { path: '/work-schedules', name: 'work-schedules', label: 'Lịch làm việc', icon: IconCalendar, adminOnly: false },
        ]
      },
      {
        id: 'employee-comm',
        label: 'Truyền thông & Trợ giúp',
        isOpen: true,
        items: [
          { path: '/news', name: 'news', label: 'Tin tức nội bộ', icon: IconNewspaper, adminOnly: false },
          { path: '/policies', name: 'policies', label: 'Chính sách công ty', icon: IconShieldCheck, adminOnly: false },
          { path: '/service-tickets', name: 'service-tickets', label: 'Hỗ trợ nội bộ', icon: IconSupport, adminOnly: false }
        ]
      }
    ];
  }

  // Map sidebar groups to access modules; groups with no module (dashboard) are
  // always shown to admin-shell users. Module-gated groups hide when the user's
  // role doesn't grant that module (role-based access control).
  const GROUP_MODULE = {
    hr: 'hr', time: 'time', payroll: 'payroll',
    recruitment: 'recruitment', communications: 'communications', settings: 'settings'
  };

  return navGroupsData.value
    .filter(group => !group.superAdminOnly || isSuperAdmin.value)
    .filter(group => {
      if (group.id === 'dashboard') return canUseDashboard.value;
      const m = GROUP_MODULE[group.id];
      const hasPayslipIssueAccess = group.id === 'payroll'
        && authService.hasCapability('payslip_issues.view');
      return group.id === 'communications' || !m || authService.canAccessModule(m) || hasPayslipIssueAccess;
    })
    .map(group => {
      const issuesOnly = group.id === 'payroll'
        && !authService.canAccessModule('payroll')
        && authService.hasCapability('payslip_issues.view');
      const items = group.items
        .filter(item => isAdmin.value || !item.adminOnly)
        .filter(item => !issuesOnly || item.path === '/salaries')
        .map(item => issuesOnly ? { ...item, label: 'Phiếu chưa phát hành' } : item);

      return { ...group, items };
    }).filter(group => group.items.length > 0);
});

const mobileNavItems = computed(() => {
  if (isAdmin.value) {
    const items = [];
    if (canUseDashboard.value) items.push({ path: '/', label: 'Tổng quan', icon: IconDashboard });
    else if (authService.canAccessModule('payroll')) items.push({ path: '/salaries', label: 'Tính lương', icon: IconCash });
    else if (authService.hasCapability('payslip_issues.view')) items.push({ path: '/salaries', label: 'Phiếu lỗi', icon: IconCash });
    if (authService.canAccessModule('time')) items.push({ path: '/attendance', label: 'Chấm công', icon: IconClock });
    if (authService.canAccessModule('recruitment')) items.push({ path: '/recruitment', label: 'Tuyển dụng', icon: IconBriefcase });
    items.push(
      { path: '/news', label: 'Tin tức', icon: IconNewspaper },
      { path: '/service-tickets', label: 'Hỗ trợ', icon: IconSupport },
    );
    return items;
  } else {
    return [
      { path: '/employee-portal', label: 'Portal', icon: IconDashboard },
      { path: '/attendance', label: 'Chấm công', icon: IconClock },
      { path: '/leaves', label: 'Nghỉ phép', icon: IconCalendar },
      { path: '/news', label: 'Tin tức', icon: IconNewspaper },
      { path: '/service-tickets', label: 'Hỗ trợ', icon: IconSupport },
    ];
  }
});

const dashboardGroup = computed(() => filteredGroups.value.find(g => g.id === 'dashboard') || { items: [] });
const menuGroups = computed(() => filteredGroups.value.filter(g => g.id !== 'dashboard'));

const toggleMenu = (groupItem) => {
  navGroupsData.value = navGroupsData.value.map(g =>
    g.id === groupItem.id ? { ...g, isOpen: !g.isOpen } : g
  );
};

const navItems = computed(() => {
  return filteredGroups.value.flatMap(group => group.items);
});

const filteredNavItems = computed(() => {
  const query = searchQuery.value.toLowerCase().trim();
  if (!query) return navItems.value;
  return navItems.value.filter(item => 
    item.label.toLowerCase().includes(query) || 
    item.name.toLowerCase().includes(query) ||
    item.path.toLowerCase().includes(query)
  );
});

const currentPageTitle = computed(() => {
  const item = navItems.value.find(i => i.path === route.path);
  return item?.label || route.meta.title || 'Dashboard';
});

const isActive = (path) => {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
};

const navigateToPage = (item) => {
  router.push(item.path);
  searchQuery.value = '';
  isSearchFocused.value = false;
  selectedSearchIndex.value = 0;
};

const handleSearchEnter = () => {
  if (filteredNavItems.value.length > 0) {
    navigateToPage(filteredNavItems.value[selectedSearchIndex.value]);
  }
};

const navigateSearchResults = (direction) => {
  const len = filteredNavItems.value.length;
  if (len === 0) return;
  selectedSearchIndex.value = (selectedSearchIndex.value + direction + len) % len;
};

const closeSearch = () => {
  isSearchFocused.value = false;
  searchQuery.value = '';
  selectedSearchIndex.value = 0;
};

const toggleNotifications = () => {
  isNotificationsOpen.value = !isNotificationsOpen.value;
  if (isNotificationsOpen.value) loadPersisted();
};

const markAsRead = async (id) => {
  const n = notifications.value.find(x => x.id === id);
  if (!n) return;
  if (n._persisted) {
    try { await notificationService.markRead(n._rawId); } catch (e) { /* noop */ }
    const p = persistedNotifications.value.find(x => x.id === n._rawId);
    if (p) p.read_at = new Date().toISOString();
  } else {
    notificationStore.markAsRead(n._rawId);
  }
};

const markAllAsRead = async () => {
  try { await notificationService.markAllRead(); } catch (e) { /* noop */ }
  persistedNotifications.value.forEach(n => { if (!n.read_at) n.read_at = new Date().toISOString(); });
  notificationStore.markAllAsRead();
};

const handleClickOutside = (event) => {
  if (searchContainerRef.value && !searchContainerRef.value.contains(event.target)) {
    isSearchFocused.value = false;
  }
  if (notificationContainerRef.value && !notificationContainerRef.value.contains(event.target)) {
    isNotificationsOpen.value = false;
  }
  if (avatarContainerRef.value && !avatarContainerRef.value.contains(event.target)) {
    isAvatarMenuOpen.value = false;
  }
  if (langContainerRef.value && !langContainerRef.value.contains(event.target)) {
    isLangMenuOpen.value = false;
  }
  if (quickAddContainerRef.value && !quickAddContainerRef.value.contains(event.target)) {
    isQuickAddOpen.value = false;
  }
};

const toggleAvatarMenu = () => {
  isAvatarMenuOpen.value = !isAvatarMenuOpen.value;
  isNotificationsOpen.value = false;
};

const openChangePassword = () => {
  isAvatarMenuOpen.value = false;
  pwForm.value = { currentPassword: '', newPassword: '', confirmPassword: '' };
  pwError.value = '';
  pwSuccess.value = '';
  showChangePasswordModal.value = true;
};

const submitChangePassword = async () => {
  pwError.value = '';
  pwSuccess.value = '';
  if (!pwForm.value.currentPassword) {
    pwError.value = 'Vui lòng nhập mật khẩu hiện tại';
    return;
  }
  if (!pwForm.value.newPassword || pwForm.value.newPassword.length < 8
      || !/[A-Za-z]/.test(pwForm.value.newPassword)
      || !/\d/.test(pwForm.value.newPassword)) {
    pwError.value = 'Mật khẩu mới phải có ít nhất 8 ký tự, gồm chữ và số';
    return;
  }
  if (pwForm.value.newPassword === pwForm.value.currentPassword) {
    pwError.value = 'Mật khẩu mới phải khác mật khẩu hiện tại';
    return;
  }
  if (pwForm.value.newPassword !== pwForm.value.confirmPassword) {
    pwError.value = 'Mật khẩu xác nhận không khớp';
    return;
  }
  const user = authService.getUser();
  if (!user?.id) {
    pwError.value = 'Không xác định được người dùng';
    return;
  }
  pwLoading.value = true;
  try {
    await authService.changePassword(
      pwForm.value.currentPassword,
      pwForm.value.newPassword,
      pwForm.value.confirmPassword
    );
    pwSuccess.value = 'Đổi mật khẩu thành công! Vui lòng đăng nhập lại.';
    setTimeout(() => { showChangePasswordModal.value = false; authService.logout(); }, 2000);
  } catch (err) {
    pwError.value = err.response?.data?.errors?.current_password?.[0]
      || err.response?.data?.errors?.password?.[0]
      || err.response?.data?.message
      || 'Đổi mật khẩu thất bại';
  } finally {
    pwLoading.value = false;
  }
};

let notifPoll = null;
onMounted(async () => {
  document.addEventListener('click', handleClickOutside);
  loadPersisted();
  notifPoll = setInterval(loadPersisted, 60000);
  try {
    currentUser.value = await authService.me();
  } catch {
    // Thông tin role không được làm gián đoạn shell nếu API đồng bộ tạm lỗi.
  }
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
  if (notifPoll) clearInterval(notifPoll);
});
</script>
