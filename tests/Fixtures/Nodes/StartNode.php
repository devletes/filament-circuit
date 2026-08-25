<?php

namespace Devletes\Circuit\Tests\Fixtures\Nodes;

use Devletes\Circuit\Support\NodeDefinition;

class StartNode extends NodeDefinition
{
    public function icon(): ?string
    {
        return 'heroicon-o-play-circle';
    }

    public function color(): string
    {
        return 'success';
    }

    public function description(): ?string
    {
        return 'Where the flow begins';
    }

    public function isInitial(): bool
    {
        return true;
    }

    public function isSingleton(): bool
    {
        return true;
    }

    public function sort(): int
    {
        return 0;
    }
}
