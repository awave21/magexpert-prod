<?php

namespace App\Sender\Console;

use App\Sender\Mcp\Support\TemplateLinter;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use Illuminate\Console\Command;

/**
 * Проверяет сохранённые письма теми же правилами, что кнопка «Проверить»: отправитель, неизвестные переменные,
 * ссылки, картинки, размер, тема. Шаблоны живут в Sender без версий, поэтому проверка нужна после правок и перед выкладкой.
 * Код выхода 1, если есть ошибки (или предупреждения с --strict).
 */
class CheckTemplatesCommand extends Command
{
    protected $signature = 'sender:check-templates {--organization= : Только эта организация (slug)} {--strict : Предупреждения тоже считать ошибкой}';

    protected $description = 'Проверить сохранённые письма: отправитель, переменные, ссылки, размер';

    public function handle(TemplateLinter $linter): int
    {
        $system = array_map('strval', array_values((array) config('sender.client.templates', [])));
        $failed = false;
        $rows = [];
        $checked = 0;

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, string $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        foreach ($organizations as $organization) {
            $organization->templates()->orderBy('id')->get()->each(function (Template $template) use ($linter, $organization, $system, &$rows, &$failed, &$checked): void {
                $checked++;
                $kind = in_array($template->slug, $system, true) || in_array((string) $template->id, $system, true) ? 'transactional' : 'campaign';

                $findings = $linter->check($organization, [
                    'subject' => (string) $template->subject,
                    'preheader' => $template->preheader,
                    'html' => (string) $template->body_html,
                    'text' => $template->body_text,
                ], $kind, $template);

                foreach ($findings as $finding) {
                    if ($finding['severity'] === TemplateLinter::INFO) {
                        continue;
                    }

                    $rows[] = [$organization->slug, $template->id.' '.$template->slug, $finding['severity'], $finding['code'], $finding['message']];
                    $failed = $failed || $finding['severity'] === TemplateLinter::ERROR || ($this->option('strict') && $finding['severity'] === TemplateLinter::WARNING);
                }
            });
        }

        if ($rows === []) {
            $this->info("Проверено писем: {$checked}, замечаний нет");

            return self::SUCCESS;
        }

        $this->table(['Организация', 'Письмо', 'Уровень', 'Код', 'Замечание'], $rows);
        $this->line("Проверено писем: {$checked}, замечаний: ".count($rows));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
