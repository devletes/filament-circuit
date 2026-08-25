<?php

namespace Devletes\Circuit\Support;

/**
 * Value object over the saved graph JSON, plus the topology checks that are
 * cheap to run at save time and miserable to retrofit once records exist.
 *
 * Deliberately domain-free: it validates *shape and topology*, never meaning.
 * Whether an `approval` node has a valid approver is the consuming app's
 * business, enforced through the node type's own schema.
 */
class Graph
{
    /**
     * Edges may carry an `outcome` (binding the edge to one of the source
     * node type's declared outcomes) and/or a `condition` (an opaque payload
     * the consuming application defines and evaluates — Circuit only stores
     * it). A bare edge means "always follow".
     *
     * @param  array<int, array{id: string, type: string, position: array{x: float, y: float}, config: array}>  $nodes
     * @param  array<int, array{id: string, source: string, target: string, outcome?: string|null, condition?: array|null, label?: string|null}>  $edges
     */
    public function __construct(
        public readonly array $nodes = [],
        public readonly array $edges = [],
        public readonly array $viewport = ['x' => 0, 'y' => 0, 'zoom' => 1],
    ) {
    }

    public static function fromArray(array|string|null $raw): self
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return new self(
            nodes: array_values($raw['nodes'] ?? []),
            edges: array_values($raw['edges'] ?? []),
            viewport: $raw['viewport'] ?? ['x' => 0, 'y' => 0, 'zoom' => 1],
        );
    }

    public function toArray(): array
    {
        return [
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'viewport' => $this->viewport,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** @return array<string, array> */
    public function nodesById(): array
    {
        return collect($this->nodes)->keyBy('id')->all();
    }

    /** @return array<int, array> */
    public function nodesOfType(string $type): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn (array $node): bool => ($node['type'] ?? null) === $type,
        ));
    }

    /** @return array<int, string> Node ids reachable by following edges out of $from. */
    public function successors(string $from): array
    {
        return array_values(array_map(
            fn (array $edge): string => $edge['target'],
            array_filter($this->edges, fn (array $edge): bool => ($edge['source'] ?? null) === $from),
        ));
    }

    /**
     * Every node reachable from the given entry points.
     *
     * @param  array<int, string>  $entries
     * @return array<int, string>
     */
    public function reachableFrom(array $entries): array
    {
        $seen = [];
        $queue = $entries;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($this->successors($id) as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    public function hasCycle(): bool
    {
        $visiting = [];
        $visited = [];

        $walk = function (string $id) use (&$walk, &$visiting, &$visited): bool {
            if (isset($visiting[$id])) {
                return true;
            }

            if (isset($visited[$id])) {
                return false;
            }

            $visiting[$id] = true;

            foreach ($this->successors($id) as $next) {
                if ($walk($next)) {
                    return true;
                }
            }

            unset($visiting[$id]);
            $visited[$id] = true;

            return false;
        };

        foreach ($this->nodes as $node) {
            if ($walk($node['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Topology problems, as human-readable messages. Empty array means valid.
     *
     * @param  array<string, NodeType>  $nodeTypes  keyed by type name
     * @return array<int, string>
     */
    public function validate(array $nodeTypes, bool $topology = true, bool $config = true): array
    {
        return array_values(array_unique(array_column(
            $this->problems($nodeTypes, $topology, $config),
            'message',
        )));
    }

    /**
     * Problems paired with the node they belong to, so the canvas can mark the
     * offending node rather than only printing a message under the field.
     * A null `node` means the problem is about the graph as a whole.
     *
     * Two kinds, each switchable: the structural rules Circuit owns, and each
     * node type's own `validateConfigUsing()` — an approval node with a role
     * approver but no role passes topology and still must not be saved.
     *
     * Each problem carries two forms of the same complaint: `message` names the
     * node it belongs to and is what a list of problems shows, while `detail`
     * is the bare text for showing ON that node, where repeating its label
     * would just be reading the heading back.
     *
     * @param  array<string, NodeType>  $nodeTypes  keyed by type name
     * @return array<int, array{node: string|null, message: string, detail: string}>
     */
    public function problems(array $nodeTypes, bool $topology = true, bool $config = true): array
    {
        $problems = [];

        $add = static function (?string $node, string $message, ?string $detail = null) use (&$problems): void {
            $problems[] = ['node' => $node, 'message' => $message, 'detail' => $detail ?? $message];
        };

        if ($config) {
            foreach ($this->nodes as $node) {
                $type = $nodeTypes[$node['type'] ?? ''] ?? null;

                foreach ($type?->validateConfig((array) ($node['config'] ?? [])) ?? [] as $message) {
                    // Listed with the type's label, the way the structural
                    // messages already name the node they blame — but shown on
                    // the node itself without it.
                    $add($node['id'] ?? null, "{$type->getLabel()}: {$message}", $message);
                }
            }
        }

        if (! $topology) {
            return $problems;
        }

        if ($this->isEmpty()) {
            return [...$problems, ['node' => null, 'message' => 'The canvas is empty.', 'detail' => 'The canvas is empty.']];
        }

        $ids = array_column($this->nodes, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            $add(null, 'Two nodes share the same id.');
        }

        $byId = $this->nodesById();

        foreach ($this->edges as $edge) {
            foreach (['source', 'target'] as $end) {
                if (! isset($byId[$edge[$end] ?? ''])) {
                    $add(
                        isset($byId[$edge[$end === 'source' ? 'target' : 'source'] ?? '']) ? $edge[$end === 'source' ? 'target' : 'source'] : null,
                        "A connection points at a node that no longer exists ({$edge[$end]}).",
                    );
                }
            }
        }

        $initialTypes = array_keys(array_filter($nodeTypes, fn (NodeType $type): bool => $type->isInitial()));
        $terminalTypes = array_keys(array_filter($nodeTypes, fn (NodeType $type): bool => $type->isTerminal()));

        $entries = [];

        foreach ($initialTypes as $type) {
            $found = $this->nodesOfType($type);

            if ($found === []) {
                $add(null, "The graph needs a {$nodeTypes[$type]->getLabel()} node.");
            }

            $entries = [...$entries, ...array_column($found, 'id')];
        }

        foreach ($terminalTypes as $type) {
            if ($this->nodesOfType($type) === []) {
                $add(null, "The graph needs at least one {$nodeTypes[$type]->getLabel()} node.");
            }
        }

        foreach ($nodeTypes as $name => $type) {
            $found = $this->nodesOfType($name);

            if ($type->isSingleton() && count($found) > 1) {
                // Blame every duplicate but the first, so the original stays clean.
                foreach (array_slice($found, 1) as $duplicate) {
                    $add($duplicate['id'], "Only one {$type->getLabel()} node is allowed.");
                }
            }
        }

        if ($this->hasCycle()) {
            $add(null, 'The connections form a loop.');
        }

        if ($entries !== []) {
            $reachable = $this->reachableFrom($entries);
            $orphans = array_diff($ids, $reachable);

            foreach ($orphans as $orphan) {
                $type = $nodeTypes[$byId[$orphan]['type'] ?? ''] ?? null;
                $label = $type?->getLabel() ?? 'A node';
                $add($orphan, "{$label} is not connected to the flow.");
            }
        }

        foreach ($this->nodes as $node) {
            $type = $nodeTypes[$node['type'] ?? ''] ?? null;

            if (! $type) {
                $add($node['id'], "Unknown node type [{$node['type']}].");

                continue;
            }

            $out = count($this->successors($node['id']));
            $in = count(array_filter($this->edges, fn (array $e): bool => ($e['target'] ?? null) === $node['id']));

            if ($type->getMaxOutgoing() !== null && $out > $type->getMaxOutgoing()) {
                $add($node['id'], "{$type->getLabel()} allows at most {$type->getMaxOutgoing()} outgoing connection(s).");
            }

            if ($type->getMaxIncoming() !== null && $in > $type->getMaxIncoming()) {
                $add($node['id'], "{$type->getLabel()} allows at most {$type->getMaxIncoming()} incoming connection(s).");
            }

            if (! $type->isTerminal() && $out === 0) {
                $add($node['id'], "{$type->getLabel()} has no outgoing connection.");
            }
        }

        // Outcome-bound edges: the outcome must exist on the source node's
        // type, and no two edges out of one node may claim the same outcome
        // (which edge would the engine follow?). Bare edges stay exempt —
        // parallel "always" fan-out is legitimate. Conditions are opaque to
        // Circuit and deliberately not inspected here.
        $claimed = [];

        foreach ($this->edges as $edge) {
            $source = $edge['source'] ?? '';
            $outcome = $edge['outcome'] ?? null;

            if (blank($outcome) || ! isset($byId[$source])) {
                continue;
            }

            $type = $nodeTypes[$byId[$source]['type'] ?? ''] ?? null;

            if (! $type) {
                continue; // The unknown node type is already reported above.
            }

            if (! array_key_exists($outcome, $type->getOutcomes())) {
                $add($source, "{$type->getLabel()} has a connection bound to an unknown outcome [{$outcome}].");

                continue;
            }

            if (isset($claimed[$source][$outcome])) {
                $add($source, "{$type->getLabel()} has more than one connection for its {$type->getOutcomeLabel($outcome)} outcome.");
            }

            $claimed[$source][$outcome] = true;
        }

        return $problems;
    }
}
