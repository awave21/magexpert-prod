<template>
  <AdminLayout>
    <template #header>
      <!-- Главная шапка страницы -->
      <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
          <div>
            <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">Управление контентом</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
              Создавайте и управляйте мероприятиями и категориями
            </p>
          </div>
          
          <!-- Табы -->
          <nav class="flex space-x-1 rounded-xl p-1 bg-zinc-100 dark:bg-zinc-800">
            <Link
              :href="route('admin.events')"
              :class="[
                activeTab === 'events' 
                  ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' 
                  : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                'px-6 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-500'
              ]"
            >
              Мероприятия
            </Link>
            <Link
              :href="route('admin.categories')"
              :class="[
                activeTab === 'categories' 
                  ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' 
                  : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                'px-6 py-2.5 text-sm font-medium rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-500'
              ]"
            >
              Категории
            </Link>
          </nav>
        </div>
      </div>
    </template>

    <!-- Контентная область с отдельным блоком для фильтров -->
    <div class="space-y-6">
      <!-- Блок управления и фильтров -->
      <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
        <!-- Заголовок секции с кнопкой создания -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
          <div>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">
              {{ activeTab === 'events' ? 'Мероприятия' : 'Категории' }}
            </h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
              {{ activeTab === 'events' 
                ? 'Управление мероприятиями, создание и редактирование событий' 
                : 'Управление категориями для группировки мероприятий' 
              }}
            </p>
          </div>
          
          <!-- Кнопка создания -->
          <div class="flex-shrink-0">
            <button
              v-if="(activeTab === 'events' && canManageEvents) || (activeTab === 'categories' && canManageCategories)"
              @click="activeTab === 'events' ? showCreateEventModal = true : showCreateCategoryModal = true"
              class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white transition-all duration-200 hover:bg-zinc-800 hover:scale-105 active:scale-95 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 shadow-lg hover:shadow-xl"
            >
              <PlusIcon class="mr-2 size-4" />
              {{ activeTab === 'events' ? 'Создать мероприятие' : 'Создать категорию' }}
            </button>
          </div>
        </div>
        
        <!-- Фильтры для мероприятий -->
        <div v-if="activeTab === 'events'" class="space-y-4">
          <!-- Основной поиск -->
          <div class="relative">
            <input 
              v-model="searchQuery" 
              type="text" 
              placeholder="Поиск мероприятий по названию, описанию или месту проведения..." 
              class="w-full rounded-lg border border-zinc-300 bg-white pl-10 pr-4 py-3 text-sm text-zinc-900 placeholder-zinc-500 transition-all duration-200 ease-in-out hover:border-zinc-400 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 dark:hover:border-zinc-600" 
              @input="debouncedSearch" 
            />
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-zinc-400" />
          </div>
          
          <!-- Сетка фильтров -->
          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Статус</label>
              <SelectList v-model="statusFilter" :options="statusOptions" placeholder="Все" @change="applyFilters" />
            </div>
            
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Категории</label>
              <MultiSelectInput 
                v-model="categoryFilter" 
                :options="categoryMultiSelectOptions" 
                placeholder="Все категории"
                :searchable="true"
                :show-selected="false"
              />
            </div>
            
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Оплата</label>
              <SelectList v-model="paymentFilter" :options="paymentOptions" placeholder="Все" @change="applyFilters" />
            </div>
            
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Формат</label>
              <SelectList v-model="formatFilter" :options="formatOptions" placeholder="Все" @change="applyFilters" />
            </div>
            
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Тип</label>
              <SelectList v-model="typeFilter" :options="typeOptions" placeholder="Все" @change="applyFilters" />
            </div>
            
            <div>
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Архив</label>
              <SelectList v-model="archiveFilter" :options="archiveOptions" placeholder="Все" @change="applyFilters" />
            </div>
          </div>
          
          <!-- Дополнительные фильтры и кнопка сброса -->
          <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 pt-2 border-t border-zinc-200 dark:border-zinc-700">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 flex-1">
              <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Регистрация</label>
                <SelectList v-model="registrationFilter" :options="registrationOptions" placeholder="Все" @change="applyFilters" />
              </div>
            </div>
            
            <!-- Кнопка сброса и статистика -->
            <div class="flex items-center gap-3">
              <div v-if="hasActiveFilters()" class="text-xs text-zinc-500 dark:text-zinc-400">
                Активных фильтров: {{ getActiveFiltersCount() }}
              </div>
              <button
                v-if="hasActiveFilters()"
                @click="resetFilters"
                class="inline-flex items-center justify-center rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition-all duration-200 hover:bg-zinc-50 hover:border-zinc-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
              >
                <XCircleIcon class="mr-2 size-4" />
                Сбросить фильтры
              </button>
            </div>
          </div>
        </div>

        <!-- Фильтры для категорий -->
        <div v-else class="space-y-4">
          <!-- Основной поиск -->
          <div class="relative">
            <input 
              v-model="categorySearchQuery" 
              type="text" 
              placeholder="Поиск категорий по названию или описанию..." 
              class="w-full rounded-lg border border-zinc-300 bg-white pl-10 pr-4 py-3 text-sm text-zinc-900 placeholder-zinc-500 transition-all duration-200 ease-in-out hover:border-zinc-400 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-400 dark:hover:border-zinc-600" 
              @input="debouncedCategorySearch" 
            />
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-zinc-400" />
          </div>
          
          <!-- Фильтры категорий -->
          <div class="flex items-center gap-4">
            <div class="w-48">
              <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Статус</label>
              <SelectList v-model="categoryStatusFilter" :options="statusOptions" placeholder="Все статусы" @change="applyCategoryFilters" />
            </div>
                     </div>
         </div>
       </div>

      <!-- Скелетон загрузки -->
      <div v-if="loading" class="space-y-4">
        <div v-for="i in 5" :key="i" class="animate-pulse">
          <div class="h-20 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
        </div>
      </div>
      
      <!-- Контент вкладок -->
      <div v-else>
        <!-- Таблица Мероприятий -->
        <div v-if="activeTab === 'events'">
          <div v-if="events && events.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                  <tr>
                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Мероприятие</th>
                    <th scope="col" class="hidden lg:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Дата проведения</th>
                    <th scope="col" class="hidden xl:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Статус</th>
                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Действия</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                  <tr v-for="event in events.data" :key="`event-${event.id}`" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <td class="whitespace-nowrap px-4 sm:px-6 py-4">
                      <div class="flex items-center">
                        <div class="size-12 sm:size-16 flex-shrink-0 overflow-hidden rounded-lg">
                          <img v-if="event.image" :src="event.image" :alt="event.title" class="size-full object-cover" />
                          <div v-else class="size-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                            <PhotoIcon class="size-8" />
                          </div>
                        </div>
                        <div class="ml-3 sm:ml-4">
                          <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ event.title }}</div>
                          <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            <!-- Отображение категорий -->
                            <div class="flex flex-wrap items-center gap-1">
                              <span v-if="event.categories && event.categories.length > 0"
                                v-for="(category, index) in event.categories" 
                                :key="category.id"
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200"
                              >
                                {{ category.name }}
                              </span>
                              <!-- Fallback для старых записей с category.name -->
                              <span v-else-if="event.category" 
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200"
                              >
                                {{ event.category.name }}
                              </span>
                            </div>
                            <div class="mt-1">
                              <span v-if="event.format">{{ getFormatLabel(event.format) }}</span>
                              <span v-if="event.event_type" class="ml-2">• {{ getTypeLabel(event.event_type) }}</span>
                            </div>
                          </div>
                          <div v-if="event.is_on_demand" class="lg:hidden text-xs text-amber-600 dark:text-amber-400 mt-1">По запросу</div>
                          <div v-else class="lg:hidden text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ formatDateRange(event.start_date, event.end_date) }}</div>
                          <!-- Отображение информации об оплате для мобильных -->
                          <div class="lg:hidden text-xs mt-1">
                            <span v-if="event.is_paid" class="font-medium text-emerald-600 dark:text-emerald-400">
                              {{ event.price && event.show_price ? formatPrice(event.price) + ' ₽' : 'Платно' }}
                            </span>
                            <span v-else class="text-blue-600 dark:text-blue-400">Бесплатно</span>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="hidden lg:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                      <div v-if="event.is_on_demand" class="text-sm text-amber-600 dark:text-amber-400">
                        <span>По запросу</span>
                      </div>
                      <template v-else>
                        <div class="text-sm text-zinc-900 dark:text-white">{{ formatDateRange(event.start_date, event.end_date, true) }}</div>
                        <div v-if="event.start_time || event.end_time" class="text-xs text-zinc-500 dark:text-zinc-400">{{ formatTimeRange(event.start_time, event.end_time) }}</div>
                      </template>
                      <!-- Отображение информации об оплате для десктопа -->
                      <div class="mt-1 text-xs">
                        <span v-if="event.is_paid" class="font-medium text-emerald-600 dark:text-emerald-400">
                          {{ event.price && event.show_price ? formatPrice(event.price) + ' ₽' : 'Платно' }}
                        </span>
                        <span v-else class="text-blue-600 dark:text-blue-400">Бесплатно</span>
                      </div>
                    </td>
                    <td class="hidden xl:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                      <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="{ 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': event.is_active, 'bg-zinc-100 text-zinc-800 dark:bg-zinc-900/30 dark:text-zinc-300': !event.is_active }">
                        {{ event.is_active ? 'Активно' : 'Неактивно' }}
                      </span>
                    </td>
                    <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm font-medium">
                      <div class="flex items-center gap-2">
                        <button @click="editEvent(event)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-zinc-600 transition-colors duration-150 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                          <PencilIcon class="size-4" />
                          <span class="hidden sm:inline">Редактировать</span>
                        </button>
                        <button @click="confirmDeleteEvent(event)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-red-600 transition-colors duration-150 hover:bg-red-50 hover:text-red-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:hover:text-red-300">
                          <TrashIcon class="size-4" />
                          <span class="hidden sm:inline">Удалить</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <CalendarDaysIcon class="mb-4 size-16 text-zinc-300 dark:text-zinc-600" />
            <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Мероприятия не найдены</h3>
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ getNoResultsMessage() }}</p>
            <button v-if="hasActiveFilters()" @click="resetFilters" class="mt-4 inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
              <XCircleIcon class="mr-2 size-4" />
              Сбросить фильтры
            </button>
          </div>
        </div>
        
        <!-- Таблица Категорий -->
        <div v-if="activeTab === 'categories'">
          <div v-if="categoriesData && categoriesData.data.length > 0" class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                  <tr>
                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Название</th>
                    <th scope="col" class="hidden md:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Описание</th>
                    <th scope="col" class="hidden lg:table-cell px-4 sm:px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Мероприятия</th>
                    <th scope="col" class="hidden xl:table-cell px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Статус</th>
                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Действия</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                  <tr v-for="category in categoriesData.data" :key="`category-${category.id}`" class="group transition-colors duration-150 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <td class="whitespace-nowrap px-4 sm:px-6 py-4">
                      <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ category.name }}</div>
                      <div class="text-xs text-zinc-500 dark:text-zinc-400">/{{ category.slug }}</div>
                    </td>
                    <td class="hidden md:table-cell whitespace-normal px-4 sm:px-6 py-4 text-sm text-zinc-600 dark:text-zinc-400 max-w-sm">
                      <p class="truncate">{{ category.description || '—' }}</p>
                    </td>
                    <td class="hidden lg:table-cell whitespace-nowrap px-4 sm:px-6 py-4 text-sm text-center">
                      <span class="text-zinc-900 dark:text-white">{{ category.active_events_count }}</span>
                      <span class="text-zinc-500 dark:text-zinc-400"> / {{ category.events_count }}</span>
                    </td>
                    <td class="hidden xl:table-cell whitespace-nowrap px-4 sm:px-6 py-4">
                      <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="{ 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300': category.is_active, 'bg-zinc-100 text-zinc-800 dark:bg-zinc-900/30 dark:text-zinc-300': !category.is_active }">
                        {{ category.is_active ? 'Активна' : 'Неактивна' }}
                      </span>
                    </td>
                    <td class="whitespace-nowrap px-4 sm:px-6 py-4 text-sm font-medium">
                      <div class="flex items-center gap-2">
                        <button @click="editCategory(category)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-zinc-600 transition-colors duration-150 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                          <PencilIcon class="size-4" /> <span class="hidden sm:inline">Редактировать</span>
                        </button>
                        <button @click="confirmDeleteCategory(category)" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-red-600 transition-colors duration-150 hover:bg-red-50 hover:text-red-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:hover:text-red-300">
                          <TrashIcon class="size-4" /> <span class="hidden sm:inline">Удалить</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white py-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <TagIcon class="mb-4 size-16 text-zinc-300 dark:text-zinc-600" />
            <h3 class="text-xl font-medium text-zinc-900 dark:text-white">Категории не найдены</h3>
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ getNoCategoriesMessage() }}</p>
            <button v-if="hasActiveCategoryFilters()" @click="resetCategoryFilters" class="mt-4 inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
              <XCircleIcon class="mr-2 size-4" />
              Сбросить фильтры
            </button>
          </div>
        </div>
      </div>

      <!-- Пагинация -->
      <div v-if="(activeTab === 'events' && events && events.data.length > 0) || (activeTab === 'categories' && categoriesData && categoriesData.data.length > 0)" class="mt-6">
        <div v-if="activeTab === 'events'" class="flex flex-col sm:flex-row items-center justify-between gap-4">
          <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
            <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
              <span v-if="events.total > 0">Показано {{ events.from }}-{{ events.to }} из {{ events.total }} мероприятий</span>
              <span v-else>Всего 0 мероприятий</span>
            </div>
            <div class="flex items-center text-sm text-zinc-700 dark:text-zinc-300">
              <span class="mr-2">Показывать:</span>
              <div class="w-20"><SelectList v-model="perPage" :options="perPageOptions" @change="changePerPage" /></div>
            </div>
          </div>
          <div class="w-full sm:w-auto"><Pagination :links="events.links" :show-first-last-buttons="true" :max-visible-pages="5" /></div>
        </div>
        
        <div v-if="activeTab === 'categories'" class="flex flex-col sm:flex-row items-center justify-between gap-4">
          <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto">
            <div class="text-sm text-zinc-700 dark:text-zinc-300 text-center sm:text-left">
              <span v-if="categoriesData.total > 0">Показано {{ categoriesData.from }}-{{ categoriesData.to }} из {{ categoriesData.total }} категорий</span>
              <span v-else>Всего 0 категорий</span>
            </div>
            <div class="flex items-center text-sm text-zinc-700 dark:text-zinc-300">
              <span class="mr-2">Показывать:</span>
              <div class="w-20"><SelectList v-model="categoryPerPage" :options="perPageOptions" @change="changeCategoryPerPage" /></div>
            </div>
          </div>
          <div class="w-full sm:w-auto"><Pagination :links="categoriesData.links" :show-first-last-buttons="true" :max-visible-pages="5" /></div>
        </div>
      </div>

      <!-- Модальные окна -->
      <CreateEventModal
        :show="showCreateEventModal"
        :event="selectedEvent"
        :categories="categories"
        :speakers="speakers"
        @close="closeCreateEventModal"
        @created="handleEventCreated"
        @updated="handleEventUpdated"
      />
      <CreateCategoryModal :show="showCreateCategoryModal" :category="selectedCategory" @close="closeCreateCategoryModal" @created="handleCategoryCreated" @updated="handleCategoryUpdated" />
      
      <ConfirmModal :show="showDeleteModal" :title="deleteModalTitle" :message="deleteModalMessage" confirm-text="Удалить" cancel-text="Отмена" confirm-button-class="bg-red-600 hover:bg-red-700 text-white" @confirm="deleteConfirmed" @close="showDeleteModal = false" />
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SelectList from '@/Components/SelectList.vue';
import MultiSelectInput from '@/Components/Form/MultiSelectInput.vue';
import Pagination from '@/Components/Pagination.vue';
import CreateEventModal from '@/Components/Modal/CreateEventModal.vue';
import CreateCategoryModal from '@/Components/Modal/CreateCategoryModal.vue';
import ConfirmModal from '@/Components/Modal/ConfirmModal.vue';
import debounce from 'lodash/debounce';
import { 
  PlusIcon, 
  MagnifyingGlassIcon, 
  PhotoIcon, 
  PencilIcon, 
  TrashIcon, 
  CalendarDaysIcon,
  XCircleIcon,
  TagIcon
} from '@heroicons/vue/24/outline';

const toast = useToast();

const props = defineProps({
  events: Object,
  categories: Array,
  speakers: Array,
  filters: Object,
  canManageEvents: Boolean,
  canManageCategories: Boolean,
  categoriesData: Object,
  activeTab: {
      type: String,
      default: 'events',
  },
});

// Общее состояние
const loading = ref(false);
const showDeleteModal = ref(false);
const itemToDelete = ref(null);
const deleteModalTitle = ref('');
const deleteModalMessage = ref('');

// Состояние для вкладки "Мероприятия"
const searchQuery = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const categoryFilter = ref(Array.isArray(props.filters.category) ? props.filters.category : (props.filters.category ? [props.filters.category] : []));
const paymentFilter = ref(props.filters.payment || '');
const formatFilter = ref(props.filters.format || '');
const typeFilter = ref(props.filters.type || '');
const archiveFilter = ref(props.filters.archive || '');
const registrationFilter = ref(props.filters.registration || '');
const perPage = ref(props.filters.per_page || 10);
const showCreateEventModal = ref(false);
const selectedEvent = ref(null);

// Состояние для вкладки "Категории"
const categorySearchQuery = ref(props.filters.search || '');
const categoryStatusFilter = ref(props.filters.status || '');
const categoryPerPage = ref(props.filters.per_page || 10);
const showCreateCategoryModal = ref(false);
const selectedCategory = ref(null);

// Варианты для выпадающих списков
const perPageOptions = [
  { value: 5, label: '5' },
  { value: 10, label: '10' },
  { value: 25, label: '25' },
  { value: 50, label: '50' },
];

const statusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'active', label: 'Активные' },
  { value: 'inactive', label: 'Неактивные' },
];

const categoryOptions = computed(() => {
  return [
    { value: '', label: 'Все категории' },
    ...props.categories.map(cat => ({ value: cat.id, label: cat.name }))
  ];
});

const categoryMultiSelectOptions = computed(() => {
  return props.categories.map(cat => ({ 
    value: cat.id, 
    text: cat.name 
  }));
});

const paymentOptions = [
  { value: '', label: 'Все типы' },
  { value: 'paid', label: 'Платные' },
  { value: 'free', label: 'Бесплатные' },
];

const formatOptions = [
  { value: '', label: 'Все форматы' },
  { value: 'online', label: 'Онлайн' },
  { value: 'offline', label: 'Офлайн' },
  { value: 'hybrid', label: 'Гибридный' },
];

const typeOptions = [
  { value: '', label: 'Все типы' },
  { value: 'webinar', label: 'Вебинар' },
  { value: 'conference', label: 'Конференция' },
  { value: 'workshop', label: 'Мастер-класс' },
  { value: 'other', label: 'Другое' },
];

const archiveOptions = [
  { value: '', label: 'Все' },
  { value: 'archived', label: 'В архиве' },
  { value: 'active', label: 'Не в архиве' },
];

const registrationOptions = [
  { value: '', label: 'Все' },
  { value: 'enabled', label: 'Регистрация открыта' },
  { value: 'disabled', label: 'Регистрация закрыта' },
];

// Форматирование дат и времени
const formatDate = (date, withYear = true) => {
  if (!date) return '';
  const options = { month: 'long', day: 'numeric' };
  if (withYear) options.year = 'numeric';
  return new Date(date).toLocaleDateString('ru-RU', options);
};

const formatDateRange = (startDate, endDate, withYear = false) => {
  if (!startDate || !endDate) return '';
  const start = new Date(startDate);
  const end = new Date(endDate);
  if (start.getTime() === end.getTime()) return formatDate(startDate, withYear);
  const startMonth = start.getMonth(), endMonth = end.getMonth(), startYear = start.getFullYear(), endYear = end.getFullYear();
  if (startYear !== endYear) return `${formatDate(startDate, true)} - ${formatDate(endDate, true)}`;
  if (startMonth !== endMonth) return `${formatDate(startDate, false)} - ${formatDate(endDate, withYear)}`;
  return `${start.getDate()} - ${formatDate(endDate, withYear)}`;
};

const formatTime = (time) => time ? time.slice(0, 5) : '';
const formatTimeRange = (startTime, endTime) => {
  if (startTime && endTime) return `${formatTime(startTime)} - ${formatTime(endTime)}`;
  if (startTime) return `Начало в ${formatTime(startTime)}`;
  return '';
};

const formatPrice = (price) => {
  if (price === null || price === undefined) return '—';
  // Преобразуем в число, чтобы убрать лишние нули из decimal строк
  const numPrice = parseFloat(price);
  // Форматируем число с пробелами в качестве разделителей тысяч
  // и убираем дробную часть если она равна 0
  return numPrice.toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
    useGrouping: true
  }).replace(/,/g, ' ');
};

// Функции для получения меток форматов и типов
const getFormatLabel = (format) => {
  const option = formatOptions.find(opt => opt.value === format);
  return option ? option.label : format;
};

const getTypeLabel = (type) => {
  const option = typeOptions.find(opt => opt.value === type);
  return option ? option.label : type;
};

// Фильтрация и поиск для Мероприятий
const debouncedSearch = debounce(() => applyFilters(), 300);
const applyFilters = () => {
  loading.value = true;
  const filters = { 
    search: searchQuery.value, 
    status: statusFilter.value, 
    category: Array.isArray(categoryFilter.value) && categoryFilter.value.length > 0 ? categoryFilter.value : '',
    payment: paymentFilter.value,
    format: formatFilter.value,
    type: typeFilter.value,
    archive: archiveFilter.value,
    registration: registrationFilter.value,
    per_page: perPage.value, 
    page: 1 
  };
  
  // Отладка: выводим параметры фильтрации в консоль
  console.log('Применяем фильтры:', filters);
  
  router.get(route('admin.events'), filters, { 
    preserveState: true, 
    preserveScroll: true, 
    onFinish: () => { loading.value = false; }
  });
};
const hasActiveFilters = () => {
  return statusFilter.value !== '' || 
         searchQuery.value !== '' || 
         (Array.isArray(categoryFilter.value) && categoryFilter.value.length > 0) ||
         paymentFilter.value !== '' ||
         formatFilter.value !== '' ||
         typeFilter.value !== '' ||
         archiveFilter.value !== '' ||
         registrationFilter.value !== '';
};

const getActiveFiltersCount = () => {
  let count = 0;
  if (statusFilter.value !== '') count++;
  if (searchQuery.value !== '') count++;
  if (Array.isArray(categoryFilter.value) && categoryFilter.value.length > 0) count++;
  if (paymentFilter.value !== '') count++;
  if (formatFilter.value !== '') count++;
  if (typeFilter.value !== '') count++;
  if (archiveFilter.value !== '') count++;
  if (registrationFilter.value !== '') count++;
  return count;
};

const resetFilters = () => {
  searchQuery.value = '';
  statusFilter.value = '';
  categoryFilter.value = [];
  paymentFilter.value = '';
  formatFilter.value = '';
  typeFilter.value = '';
  archiveFilter.value = '';
  registrationFilter.value = '';
  applyFilters();
};
const getNoResultsMessage = () => hasActiveFilters() ? 'По заданным фильтрам мероприятий не найдено.' : 'Мероприятий пока нет.';
const changePerPage = () => applyFilters();

// Фильтрация и поиск для Категорий
const debouncedCategorySearch = debounce(() => applyCategoryFilters(), 300);
const applyCategoryFilters = () => {
  loading.value = true;
  router.get(route('admin.categories'), { 
    search: categorySearchQuery.value, status: categoryStatusFilter.value, per_page: categoryPerPage.value, page: 1 
  }, { preserveState: true, preserveScroll: true, onFinish: () => { loading.value = false; }});
};
const hasActiveCategoryFilters = () => categoryStatusFilter.value !== '' || categorySearchQuery.value !== '';
const resetCategoryFilters = () => {
  categorySearchQuery.value = ''; categoryStatusFilter.value = '';
  applyCategoryFilters();
};
const getNoCategoriesMessage = () => hasActiveCategoryFilters() ? 'По заданным фильтрам категорий не найдено.' : 'Категорий пока нет.';
const changeCategoryPerPage = () => applyCategoryFilters();

// CRUD для Мероприятий
const closeCreateEventModal = () => { showCreateEventModal.value = false; selectedEvent.value = null; };
const editEvent = (event) => { selectedEvent.value = event; showCreateEventModal.value = true; };
const handleEventCreated = () => { router.reload({ only: ['events'] }); toast.success('Мероприятие создано'); };
const handleEventUpdated = () => { router.reload({ only: ['events'] }); toast.success('Мероприятие обновлено'); };
const confirmDeleteEvent = (event) => {
  itemToDelete.value = { type: 'event', id: event.id };
  deleteModalTitle.value = 'Удаление мероприятия';
  deleteModalMessage.value = `Вы действительно хотите удалить мероприятие «${event.title}»?`;
  showDeleteModal.value = true;
};

// CRUD для Категорий
const closeCreateCategoryModal = () => { showCreateCategoryModal.value = false; selectedCategory.value = null; };
const editCategory = (category) => { selectedCategory.value = category; showCreateCategoryModal.value = true; };
const handleCategoryCreated = () => { router.reload({ only: ['categoriesData'] }); toast.success('Категория создана'); };
const handleCategoryUpdated = () => { router.reload({ only: ['categoriesData'] }); toast.success('Категория обновлена'); };
const confirmDeleteCategory = (category) => {
  itemToDelete.value = { type: 'category', id: category.id };
  deleteModalTitle.value = 'Удаление категории';
  deleteModalMessage.value = `Вы действительно хотите удалить категорию «${category.name}»? Это действие невозможно отменить.`;
  showDeleteModal.value = true;
};

// Общее удаление
const deleteConfirmed = () => {
  if (!itemToDelete.value) return;
  const { type, id } = itemToDelete.value;
  const url = type === 'event' 
    ? route('admin.events.destroy', id) 
    : route('admin.categories.destroy', id);
  
  router.delete(url, {
    onSuccess: () => {
      toast.success(`${type === 'event' ? 'Мероприятие' : 'Категория'} успешно удалено`);
      showDeleteModal.value = false;
      itemToDelete.value = null;
    },
    onError: (errors) => {
      const errorMsg = errors.error || `Ошибка при удалении ${type === 'event' ? 'мероприятия' : 'категории'}`;
      toast.error(errorMsg);
      showDeleteModal.value = false;
    }
  });
};

// Отслеживание изменений фильтра категорий
watch(categoryFilter, () => {
  applyFilters();
}, { deep: true });

// Отслеживание flash-сообщений
watch(() => [props.events, props.categoriesData], () => {
  const flash = router.page.props.flash;
  if (flash.success) toast.success(flash.success);
  if (flash.error) toast.error(flash.error);
}, { deep: true, immediate: true });
</script> 