<?php

namespace Devletes\Circuit\Concerns;

use Closure;
use Devletes\Circuit\Support\Graph;

/**
 * Shared by CircuitCanvas and CircuitEntry: resolves the label pill rendered
 * at an edge's midpoint. Outcome labels come from the source node type's
 * declared outcomes natively, client-side; condition summaries have to come
 * from the app via `->edgeLabel()`, because the condition payload is opaque
 * to Circuit — so they are baked onto the edge as `label` before the graph
 * reaches Alpine.
 */
trait HasEdgeLabels
{
    protected ?Closure $edgeLabel = null;

    /**
     * Summarises an edge's `condition` for the label pill. Receives the edge
     * array as `$edge`, returns a short string — or null for no label. Not
     * consulted for outcome labels: an edge bound to an outcome shows that
     * outcome's label from the node type, without this hook.
     *
     * @param  Closure(array $edge): ?string  $callback
     */
    public function edgeLabel(?Closure $callback): static
    {
        $this->edgeLabel = $callback;

        return $this;
    }

    /**
     * Whether a condition payload actually says anything. Shallow on purpose:
     * any filled top-level value counts, so a schema's worth of untouched
     * nulls does not read as a live condition.
     */
    public function conditionIsFilled(mixed $condition): bool
    {
        if (is_array($condition)) {
            return array_filter($condition, fn ($value): bool => filled($value)) !== [];
        }

        return filled($condition);
    }

    /** The condition pill text for one edge, or null for no pill. */
    public function getEdgeConditionLabel(array $edge): ?string
    {
        if (! $this->conditionIsFilled($edge['condition'] ?? null)) {
            return null;
        }

        $label = $this->edgeLabel
            ? $this->evaluate($this->edgeLabel, ['edge' => $edge])
            : null;

        // The payload is opaque here, so without an app-supplied summary the
        // pill can only state that a condition exists.
        return filled($label) ? $label : __('Conditional');
    }

    /** A copy of the graph with condition labels baked onto the edges. */
    protected function withEdgeLabels(Graph $graph): Graph
    {
        $edges = array_map(
            fn (array $edge): array => [...$edge, 'label' => $this->getEdgeConditionLabel($edge)],
            $graph->edges,
        );

        return new Graph($graph->nodes, $edges, $graph->viewport);
    }
}
