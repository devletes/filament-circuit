<?php

namespace Devletes\Circuit\Tests\Fixtures\Nodes;

use Devletes\Circuit\Support\NodeDefinition;

class EndNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-stop-circle';
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
