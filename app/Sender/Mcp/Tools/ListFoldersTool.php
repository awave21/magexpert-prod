<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Tool;
use App\Sender\Models\TemplateFolder;
use stdClass;

class ListFoldersTool implements Tool
{
    public function name(): string
    {
        return 'list_folders';
    }

    public function title(): string
    {
        return 'Папки писем';
    }

    public function description(): string
    {
        return 'Папки писем организации с количеством писем в каждой и число писем без папки. Lists letter folders with counts.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function handle(array $args, McpContext $ctx): array
    {
        $folders = $ctx->organization->templateFolders()->withCount('templates')->orderBy('name')->get();

        return [
            'folders' => $folders->map(fn (TemplateFolder $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'templates_count' => $f->templates_count,
            ])->values()->all(),
            'without_folder' => $ctx->organization->templates()->whereNull('folder_id')->count(),
        ];
    }
}
