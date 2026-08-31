<?php

namespace Workbench\App\Workflows\Nodes;

use Devletes\Circuit\Support\NodeDefinition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class NotifyNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-bell-alert';
    }

    public function color(): string
    {
        return 'info';
    }

    public function description(): ?string
    {
        return 'Send a notification';
    }

    public function sort(): int
    {
        return 20;
    }

    public function schema(): array
    {
        return [
            Select::make('channel')
                ->options(['mail' => 'Email', 'slack' => 'Slack', 'sms' => 'SMS'])
                ->default('mail')
                ->required(),
            TextInput::make('recipient')
                ->label('Recipient')
                ->placeholder('finance@example.com'),
        ];
    }

    public function summarise(array $config): ?string
    {
        return filled($config['recipient'] ?? null)
            ? $config['recipient']
            : null;
    }
}
