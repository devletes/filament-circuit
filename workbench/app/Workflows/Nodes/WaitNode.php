<?php

namespace Workbench\App\Workflows\Nodes;

use Devletes\Circuit\Contracts\ExecutesNode;
use Devletes\Circuit\Contracts\ResumesNode;
use Devletes\Circuit\Support\NodeDefinition;
use Devletes\Circuit\Support\NodeResult;
use Filament\Forms\Components\TextInput;

/**
 * Shows the runtime contracts: this node suspends the walk on a timer and is
 * resumed when the engine hears back.
 */
class WaitNode extends NodeDefinition implements ExecutesNode, ResumesNode
{
    public function icon(): ?string
    {
        return 'heroicon-o-clock';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function description(): ?string
    {
        return 'Pause before continuing';
    }

    public function sort(): int
    {
        return 30;
    }

    public function maxOutgoing(): ?int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            TextInput::make('hours')
                ->label('Wait for (hours)')
                ->numeric()
                ->default(24)
                ->required(),
        ];
    }

    public function summarise(array $config): ?string
    {
        return filled($config['hours'] ?? null)
            ? $config['hours'].' hours'
            : null;
    }

    public function execute(array $node, mixed $context = null): NodeResult
    {
        return NodeResult::waiting('timer:'.$node['id']);
    }

    public function resume(array $node, mixed $context = null, array $payload = []): NodeResult
    {
        return NodeResult::completed($payload);
    }
}
