<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class SetDefaultKinescopeType extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:set-default-kinescope-type 
                            {--dry-run : Показать какие события будут обновлены без фактического изменения}
                            {--force : Принудительно обновить все события с пустым kinescope_type}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Установить тип плеера Кинескопа по умолчанию (video) для существующих событий';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🎬 Начинаю обновление типов плеера Кинескопа...');
        
        // Получаем события которые нужно обновить
        $eventsQuery = Event::where(function ($query) {
            $query->whereNull('kinescope_type')
                  ->orWhere('kinescope_type', '');
        })->where(function ($query) {
            // Обновляем только те, у которых есть ID кинескопа или плейлиста
            $query->whereNotNull('kinescope_id')
                  ->orWhereNotNull('kinescope_playlist_id');
        });

        $totalEvents = $eventsQuery->count();
        
        if ($totalEvents === 0) {
            $this->info('✅ Все события уже имеют установленный тип плеера Кинескопа');
            return Command::SUCCESS;
        }

        $this->info("📊 Найдено событий для обновления: {$totalEvents}");

        // Если режим dry-run, показываем что будет обновлено
        if ($this->option('dry-run')) {
            $this->warn('🔍 РЕЖИМ ПРЕДВАРИТЕЛЬНОГО ПРОСМОТРА (изменения не будут сохранены):');
            
            $events = $eventsQuery->get(['id', 'title', 'kinescope_id', 'kinescope_playlist_id']);
            
            $this->table(
                ['ID', 'Название', 'Kinescope ID', 'Playlist ID', 'Новый тип'],
                $events->map(function ($event) {
                    $newType = $event->kinescope_playlist_id ? 'playlist' : 'video';
                    return [
                        $event->id,
                        mb_substr($event->title, 0, 50) . (mb_strlen($event->title) > 50 ? '...' : ''),
                        $event->kinescope_id ?: '-',
                        $event->kinescope_playlist_id ?: '-',
                        $newType
                    ];
                })->toArray()
            );
            
            $this->info("💡 Для выполнения обновления запустите команду без флага --dry-run");
            return Command::SUCCESS;
        }

        // Подтверждение перед выполнением
        if (!$this->option('force')) {
            if (!$this->confirm("Обновить тип плеера для {$totalEvents} событий?")) {
                $this->info('❌ Операция отменена');
                return Command::CANCELLED;
            }
        }

        // Выполняем обновление с прогресс-баром
        $progressBar = $this->output->createProgressBar($totalEvents);
        $progressBar->setFormat('verbose');
        
        $updatedCount = 0;
        $errorCount = 0;
        
        $progressBar->start();
        
        $eventsQuery->chunk(100, function ($events) use (&$updatedCount, &$errorCount, $progressBar) {
            foreach ($events as $event) {
                try {
                    // Определяем тип на основе того, что заполнено
                    $kinescopeType = 'video'; // По умолчанию video
                    
                    if ($event->kinescope_playlist_id) {
                        $kinescopeType = 'playlist';
                    }
                    
                    $event->update(['kinescope_type' => $kinescopeType]);
                    $updatedCount++;
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Ошибка при обновлении события ID {$event->id}: " . $e->getMessage());
                }
                
                $progressBar->advance();
            }
        });
        
        $progressBar->finish();
        $this->newLine(2);
        
        // Результаты
        $this->info("✅ Обновление завершено!");
        $this->info("📈 Обновлено событий: {$updatedCount}");
        
        if ($errorCount > 0) {
            $this->warn("⚠️  Ошибок при обновлении: {$errorCount}");
        }
        
        // Статистика по типам
        $videoCount = Event::where('kinescope_type', 'video')->whereNotNull('kinescope_id')->count();
        $playlistCount = Event::where('kinescope_type', 'playlist')->whereNotNull('kinescope_playlist_id')->count();
        
        $this->info("\n📊 Текущая статистика:");
        $this->info("🎥 События с типом 'video': {$videoCount}");
        $this->info("📋 События с типом 'playlist': {$playlistCount}");
        
        return Command::SUCCESS;
    }
}
