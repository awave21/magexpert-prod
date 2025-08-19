<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearOldEventCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:clear-old-categories 
                            {--dry-run : Показать что будет сделано без выполнения изменений}
                            {--force : Выполнить без подтверждения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистить поле category_id у мероприятий после миграции в новую систему категорий';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Начинаем очистку старых данных категорий...');
        
        // Получаем мероприятия с category_id, которые уже имеют связи в новой системе
        $eventsToClean = Event::whereNotNull('category_id')
            ->whereHas('categories', function ($query) {
                // Проверяем, есть ли связи в pivot таблице
                $query->whereNotNull('event_categories.category_id');
            })
            ->with(['category', 'categories'])
            ->get();
            
        if ($eventsToClean->isEmpty()) {
            $this->info('✅ Нет мероприятий для очистки. Возможно, миграция еще не выполнена или данные уже очищены.');
            $this->comment('💡 Сначала выполните: php artisan events:migrate-categories');
            return Command::SUCCESS;
        }
        
        $this->info("📋 Найдено мероприятий для очистки: {$eventsToClean->count()}");
        
        // Показываем что будет очищено
        $this->table(
            ['ID', 'Название', 'Старая категория', 'Новые категории'],
            $eventsToClean->map(function ($event) {
                return [
                    $event->id,
                    $event->title,
                    $event->category ? $event->category->name : "ID: {$event->category_id}",
                    $event->categories->pluck('name')->join(', ')
                ];
            })->toArray()
        );
        
        if ($this->option('dry-run')) {
            $this->warn('🔍 Режим проверки: изменения НЕ будут сохранены.');
            $this->showCleanupPlan($eventsToClean);
            return Command::SUCCESS;
        }
        
        // Предупреждение о безопасности
        $this->newLine();
        $this->warn('⚠️  ВНИМАНИЕ: Эта операция удалит данные из поля category_id!');
        $this->warn('   Убедитесь, что миграция прошла успешно и новая система работает корректно.');
        
        // Подтверждение выполнения
        if (!$this->option('force')) {
            if (!$this->confirm('Вы уверены, что хотите очистить старые данные категорий?', false)) {
                $this->info('❌ Очистка отменена.');
                return Command::FAILURE;
            }
        }
        
        // Выполняем очистку
        return $this->executeCleanup($eventsToClean);
    }
    
    /**
     * Показать план очистки без выполнения.
     */
    private function showCleanupPlan($events)
    {
        $this->info("\n📝 План очистки:");
        
        foreach ($events as $event) {
            $oldCategory = $event->category ? $event->category->name : "ID: {$event->category_id}";
            $newCategories = $event->categories->pluck('name')->join(', ');
            
            $this->line("   🗑️  Мероприятие '{$event->title}':");
            $this->line("      • Удалить старую категорию: {$oldCategory}");
            $this->line("      • Оставить новые категории: {$newCategories}");
        }
        
        $this->newLine();
        $this->comment('💾 Для выполнения используйте команду без --dry-run');
    }
    
    /**
     * Выполнить очистку данных.
     */
    private function executeCleanup($events)
    {
        $this->info("\n🔄 Выполняем очистку...");
        
        $cleaned = 0;
        $errors = 0;
        
        $progressBar = $this->output->createProgressBar($events->count());
        $progressBar->start();
        
        DB::beginTransaction();
        
        try {
            foreach ($events as $event) {
                $progressBar->advance();
                
                // Дополнительная проверка безопасности
                if ($event->categories->isEmpty()) {
                    $this->newLine();
                    $this->error("❌ Мероприятие '{$event->title}' не имеет категорий в новой системе. Пропускаем.");
                    $errors++;
                    continue;
                }
                
                // Сохраняем информацию для логирования
                $oldCategoryId = $event->category_id;
                $oldCategoryName = $event->category ? $event->category->name : "ID: {$oldCategoryId}";
                
                // Очищаем поле category_id
                $event->update(['category_id' => null]);
                
                $cleaned++;
                
                // Логируем действие (можно добавить в отдельную таблицу логов если нужно)
                \Log::info("Очищена старая категория для мероприятия", [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'old_category_id' => $oldCategoryId,
                    'old_category_name' => $oldCategoryName,
                    'new_categories' => $event->categories->pluck('name')->toArray()
                ]);
            }
            
            DB::commit();
            $progressBar->finish();
            
            $this->newLine(2);
            $this->info("✅ Очистка завершена успешно!");
            $this->info("   • Очищено мероприятий: {$cleaned}");
            
            if ($errors > 0) {
                $this->warn("   • Ошибок: {$errors}");
            }
            
            $this->newLine();
            $this->comment('💾 Все действия записаны в лог-файл для аудита.');
            $this->comment('🔄 Теперь все мероприятия используют только новую систему категорий.');
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $progressBar->finish();
            
            $this->newLine(2);
            $this->error("❌ Ошибка при выполнении очистки: {$e->getMessage()}");
            $this->error("Все изменения отменены.");
            
            return Command::FAILURE;
        }
    }
}
