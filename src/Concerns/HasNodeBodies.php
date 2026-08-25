<?php

namespace Devletes\Circuit\Concerns;

use Devletes\Circuit\Support\Graph;
use Devletes\Circuit\Support\NodeType;
use Filament\Schemas\Schema;

/**
 * Shared by CircuitCanvas and CircuitEntry: the optional infolist a node type
 * renders inside its card, for configuration that says more than the one line
 * {@see NodeType::summariseUsing()} affords.
 *
 * Same constraint as node actions — Alpine draws the nodes, so Filament
 * components cannot be emitted inside the `x-for` template. Each node's
 * infolist is rendered server-side into a `node id => html` map that Alpine
 * injects with `x-html`, refreshed on the round-trip that follows every commit
 * so a node whose config just changed redraws with it.
 *
 * Unlike the summary — computed once at save time and stored on the node —
 * these render from the live config on every trip, so nothing they show can go
 * stale against the data behind it.
 */
trait HasNodeBodies
{
    /**
     * The rendered card body for every node that has one, keyed by node id.
     *
     * @return array<string, string>
     */
    public function getNodeBodiesHtml(): array
    {
        $types = $this->getNodeTypes();

        // Rendering a schema per node is not free, and most graphs declare no
        // infolist at all — cost nothing for the types that opted out.
        if (! collect($types)->contains(fn (NodeType $type): bool => $type->hasInfolist())) {
            return [];
        }

        $html = [];

        foreach (Graph::fromArray($this->getState())->nodes as $node) {
            $rendered = $this->renderNodeBody($node, $types[$node['type'] ?? ''] ?? null);

            if (filled($rendered)) {
                $html[$node['id']] = $rendered;
            }
        }

        return $html;
    }

    /**
     * One node's card body. The node's config is handed to the schema as
     * constant state — the same way a RepeatableEntry hands each row its own
     * array — so entries name config keys and nothing is bound to $wire.
     *
     * @param  array<string, mixed>  $node
     */
    public function renderNodeBody(array $node, ?NodeType $type = null): ?string
    {
        $type ??= $this->getNodeTypes()[$node['type'] ?? ''] ?? null;

        if (! $type?->hasInfolist()) {
            return null;
        }

        $config = (array) ($node['config'] ?? []);
        $components = $type->getInfolist($config, $node);

        if ($components === []) {
            return null;
        }

        return Schema::make($this->getLivewire())
            ->key('circuit-node-'.($node['id'] ?? 'unknown'))
            ->components($components)
            ->constantState($config)
            ->columns(1)
            ->toHtml();
    }
}
