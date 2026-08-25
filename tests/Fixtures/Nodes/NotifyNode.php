<?php

namespace Devletes\Circuit\Tests\Fixtures\Nodes;

use Devletes\Circuit\Support\NodeDefinition;
use Filament\Forms\Components\TextInput;

/**
 * A plain type: no outcomes, no infolist, no config rules — the baseline the
 * other fixtures are compared against.
 */
class NotifyNode extends NodeDefinition
{
    public function sort(): int
    {
        return 20;
    }

    public function schema(): array
    {
        return [TextInput::make('recipient')];
    }
}
