<?php

namespace Devletes\Circuit\Tests\Fixtures\Nodes;

use Devletes\Circuit\Support\NodeDefinition;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;

class ApprovalNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-check-circle';
    }

    public function color(): string
    {
        return 'primary';
    }

    public function sort(): int
    {
        return 10;
    }

    public function outcomes(): array
    {
        return ['approved' => 'Approved', 'rejected' => 'Rejected'];
    }

    public function schema(): array
    {
        return [
            TextInput::make('approver')->required(),
            TextInput::make('escalate_after')->numeric(),
        ];
    }

    public function summarise(array $config): ?string
    {
        return $config['approver'] ?? null;
    }

    public function infolist(array $config): array
    {
        return [
            TextEntry::make('approver')->hiddenLabel()->placeholder('Nobody'),
            TextEntry::make('escalate_after')->label('Escalates after'),
        ];
    }

    public function validateConfig(array $config): array
    {
        return blank($config['approver'] ?? null)
            ? ['An approver must be selected.']
            : [];
    }
}
