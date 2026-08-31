<?php

namespace Workbench\App\Workflows\Nodes;

use Devletes\Circuit\Support\NodeDefinition;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;

/**
 * The type whose configuration says more than one line, so it draws its body
 * with real infolist entries instead of a summary.
 */
class TaskNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function description(): ?string
    {
        return 'Assign work to somebody';
    }

    public function sort(): int
    {
        return 15;
    }

    public function schema(): array
    {
        return [
            TextInput::make('title')->required(),
            TextInput::make('assignee'),
            Toggle::make('blocking')->label('Blocks the flow'),
        ];
    }

    public function summarise(array $config): ?string
    {
        return $config['title'] ?? null;
    }

    public function infolist(array $config): array
    {
        return [
            TextEntry::make('title')->hiddenLabel()->placeholder('Untitled task'),
            TextEntry::make('assignee')->label('Assignee')->placeholder('Unassigned'),
            IconEntry::make('blocking')->label('Blocking')->boolean(),
        ];
    }
}
