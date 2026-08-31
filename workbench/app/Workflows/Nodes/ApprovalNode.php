<?php

namespace Workbench\App\Workflows\Nodes;

use Devletes\Circuit\Support\NodeDefinition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class ApprovalNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-check-badge';
    }

    public function color(): string
    {
        return 'primary';
    }

    public function description(): ?string
    {
        return 'Route to an approver';
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
            Select::make('approver')
                ->label('Approver')
                ->options([
                    'line_manager' => 'Line manager',
                    'department_head' => 'Department head',
                    'finance' => 'Finance',
                ])
                ->required(),
            TextInput::make('escalate_after')
                ->label('Escalate after (hours)')
                ->numeric()
                ->suffix('h'),
            Toggle::make('notify_requester')
                ->label('Notify the requester'),
        ];
    }

    public function summarise(array $config): ?string
    {
        return match ($config['approver'] ?? null) {
            'line_manager' => 'Line manager',
            'department_head' => 'Department head',
            'finance' => 'Finance',
            default => null,
        };
    }

    public function validateConfig(array $config): array
    {
        return blank($config['approver'] ?? null)
            ? ['An approver must be selected.']
            : [];
    }
}
