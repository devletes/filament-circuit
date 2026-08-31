<?php

namespace Workbench\App\Workflows\Nodes;

use Devletes\Circuit\Support\NodeDefinition;

class EndNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-stop-circle';
    }

    public function description(): ?string
    {
        return 'Where the flow stops';
    }

    public function isTerminal(): bool
    {
        return true;
    }

    public function sort(): int
    {
        return 90;
    }
}
