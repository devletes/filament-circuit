<?php

namespace Workbench\App\Workflows\Nodes;

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
        return 'Where the request enters the flow';
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
