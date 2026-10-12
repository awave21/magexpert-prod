<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Models\Template;
use App\Sender\Support\SenderUi;

/**
 * Как письмо выглядит для ассистента: краткая карточка для списков и полная — с HTML или блоками.
 */
class TemplatePresenter
{
    public function __construct(private readonly TemplateVariables $variables) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Template $template): array
    {
        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'folder' => $template->folder ? ['id' => $template->folder->id, 'name' => $template->folder->name] : null,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'is_block_based' => $template->editor === Template::EDITOR_BLOCKS,
            'variables' => $this->variables->used($template->subject, (string) $template->preheader, (string) $template->body_html, (string) $template->body_text),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'edit_url' => SenderUi::url('templates/'.$template->id.($template->editor === Template::EDITOR_BLOCKS ? '/blocks' : '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function full(Template $template, bool $withHtml = true): array
    {
        $template->loadMissing('senderAddress');
        $blocks = $template->editor === Template::EDITOR_BLOCKS && is_array($template->design);

        return array_merge($this->summary($template), [
            'sender_address' => $template->senderAddress
                ? ['id' => $template->senderAddress->id, 'email' => $template->senderAddress->email, 'name' => $template->senderAddress->name]
                : null,
            'reply_to' => $template->reply_to,
            'design' => $blocks ? $template->design : null,
            'body_html' => $withHtml || ! $blocks ? $template->body_html : null,
            'body_text' => $template->body_text,
            'html_size_kb' => round(strlen((string) $template->body_html) / 1024, 1),
            'content_note' => $blocks
                ? 'Письмо собрано в визуальном редакторе: меняйте его через blocks (design.blocks), HTML пересоберётся сам.'
                : 'Письмо в режиме HTML: меняйте его через html.',
        ]);
    }
}
