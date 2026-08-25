<?php

namespace Devletes\Circuit\Concerns;

use Closure;
use Devletes\Circuit\Support\NodeDefinition;
use Devletes\Circuit\Support\NodeRegistry;
use Devletes\Circuit\Support\NodeType;
use Illuminate\Container\Container;

/**
 * Shared by CircuitCanvas and CircuitEntry: accepts node types as inline
 * NodeType chains, NodeDefinition instances or class-strings, a whole
 * NodeRegistry, or a Closure resolving to any of those.
 */
trait HasNodeTypes
{
    /** @var array<int, NodeType|NodeDefinition|class-string<NodeDefinition>>|NodeRegistry|Closure */
    protected array|NodeRegistry|Closure $nodeTypes = [];

    /**
     * @param  array<int, NodeType|NodeDefinition|class-string<NodeDefinition>>|NodeRegistry|Closure  $types
     */
    public function nodeTypes(array|NodeRegistry|Closure $types): static
    {
        $this->nodeTypes = $types;

        return $this;
    }

    /** @return array<string, NodeType> keyed by type name */
    public function getNodeTypes(): array
    {
        $types = $this->evaluate($this->nodeTypes);

        if ($types instanceof NodeRegistry) {
            $types = $types->nodeTypes();
        }

        return collect($types)
            ->map(function (NodeType|NodeDefinition|string $type): NodeType {
                if (is_string($type)) {
                    $type = Container::getInstance()->make($type);
                }

                return $type instanceof NodeDefinition ? $type->toNodeType() : $type;
            })
            ->keyBy(fn (NodeType $type): string => $type->getName())
            ->all();
    }
}
