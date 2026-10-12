<?php

namespace App\Sender\Mcp\Tools;

use App\Sender\Mcp\McpContext;
use App\Sender\Mcp\Support\Brand;
use App\Sender\Mcp\Tool;
use stdClass;

class GetBrandTool implements Tool
{
    public function __construct(private readonly Brand $brand) {}

    public function name(): string
    {
        return 'get_brand';
    }

    public function title(): string
    {
        return 'Фирменный стиль';
    }

    public function description(): string
    {
        return 'Фирменный стиль организации, которому должны следовать письма: название, отправитель по умолчанию и адреса отправителей, '
            .'цвета, шрифты и логотип (выведены из последних писем, помечены inferred). '
            .'Organization branding: name, default sender, inferred colors/fonts/logo.';
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
        return $this->brand->for($ctx->organization);
    }
}
