<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateEventCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:migrate-categories 
                            {--dry-run : Показать что будет сделано без выполнения изменений}
                            {--force : Выполнить без подтверждения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Перенести основные категории мероприятий в новую систему множественного выбора';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Начинаем миграцию категорий мероприятий...');
        
        // Получаем все мероприятия с основной категорией
        $eventsWithCategory = Event::whereNotNull('category_id')
            ->with('category')
            ->get();
            
        if ($eventsWithCategory->isEmpty()) {
            $this->info('✅ Нет мероприятий с основной категорией для миграции.');
            return Command::SUCCESS;
        }
        
        $this->info("📋 Найдено мероприятий для миграции: {$eventsWithCategory->count()}");
        
        // Показываем что будет мигрировано
        $this->table(
            ['ID мероприятия', 'Название', 'Основная категория'],
            $eventsWithCategory->map(function ($event) {
                return [
                    $event->id,
                    $event->title,
                    $event->category ? $event->category->name : "ID: {$event->category_id} (не найдена)"
                ];
            })->toArray()
        );
        
        if ($this->option('dry-run')) {
            $this->warn('🔍 Режим проверки: изменения НЕ будут сохранены.');
            $this->showMigrationPlan($eventsWithCategory);
            return Command::SUCCESS;
        }
        
        // Подтверждение выполнения
        if (!$this->option('force')) {
            if (!$this->confirm('Выполнить миграцию категорий?', true)) {
                $this->info('❌ Миграция отменена.');
                return Command::FAILURE;
            }
        }
        
        // Выполняем миграцию
        return $this->executeMigration($eventsWithCategory);
    }
    
    /**
     * Показать план миграции без выполнения.
     */
    private function showMigrationPlan($events)
    {
        $this->info("\n📝 План миграции:");
        
        foreach ($events as $event) {
            // Проверяем, есть ли уже связь в pivot таблице
            $existsInPivot = DB::table('event_categories')
                ->where('event_id', $event->id)
                ->where('category_id', $event->category_id)
                ->exists();
                
            if ($existsInPivot) {
                $this->line("   ⚠️  Мероприятие '{$event->title}' уже имеет связь с категорией в новой системе");
            } else {
                $categoryName = $event->category ? $event->category->name : "ID: {$event->category_id}";
                $this->line("   ➕ Добавить категорию '{$categoryName}' к мероприятию '{$event->title}'");
            }
        }
    }
    
    /**
     * Выполнить миграцию данных.
     */
    private function executeMigration($events)
    {
        $this->info("\n🔄 Выполняем миграцию...");
        
        $migrated = 0;
        $skipped = 0;
        $errors = 0;
        
        $progressBar = $this->output->createProgressBar($events->count());
        $progressBar->start();
        
        DB::beginTransaction();
        
        try {
            foreach ($events as $event) {
                $progressBar->advance();
                
                // Проверяем существование категории
                if (!$event->category) {
                    $this->newLine();
                    $this->error("❌ Категория с ID {$event->category_id} не найдена для мероприятия '{$event->title}'");
                    $errors++;
                    continue;
                }
                
                // Проверяем, нет ли уже связи в pivot таблице
                $exists = DB::table('event_categories')
                    ->where('event_id', $event->id)
                    ->where('category_id', $event->category_id)
                    ->exists();
                    
                if ($exists) {
                    $skipped++;
                    continue;
                }
                
                // Создаем связь в pivot таблице
                DB::table('event_categories')->insert([
                    'event_id' => $event->id,
                    'category_id' => $event->category_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $migrated++;
            }
            
            DB::commit();
            $progressBar->finish();
            
            $this->newLine(2);
            $this->info("✅ Миграция завершена успешно!");
            $this->info("   • Перенесено категорий: {$migrated}");
            $this->info("   • Пропущено (уже существуют): {$skipped}");
            
            if ($errors > 0) {
                $this->warn("   • Ошибок: {$errors}");
            }
            
            // Предлагаем очистить старые данные
            if ($migrated > 0) {
                $this->newLine();
                $this->comment('💡 Совет: После проверки корректности миграции можно очистить поле category_id:');
                $this->comment('   php artisan events:clear-old-categories');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $progressBar->finish();
            
            $this->newLine(2);
            $this->error("❌ Ошибка при выполнении миграции: {$e->getMessage()}");
            $this->error("Все изменения отменены.");
            
            return Command::FAILURE;
        }
    }
}
