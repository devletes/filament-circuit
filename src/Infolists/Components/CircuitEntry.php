<?php

namespace Devletes\Circuit\Infolists\Components;

use Closure;
use Devletes\Circuit\Concerns\HasCanvasOptions;
use Devletes\Circuit\Concerns\HasEdgeLabels;
use Devletes\Circuit\Concerns\HasNodeActions;
use Devletes\Circuit\Concerns\HasNodeBodies;
use Devletes\Circuit\Concerns\HasNodeTypes;
use Devletes\Circuit\Support\Graph;
use Devletes\Circuit\Support\NodeType;
use Filament\Infolists\Components\Entry;

/**
 * Read-only counterpart to {@see \Devletes\Circuit\Forms\Components\CircuitCanvas}
 * for View pages: same canvas, same styling, no editing affordances.
 *
 * `nodeActions()` is supported here for read-only affordances — a "view
 * details" modal, a link out to whatever the node points at. Actions that
 * declare `mutatesGraph()` (both built-ins do) are dropped rather than
 * rendered, because a read-only canvas has no commit path to run them through.
 */
class CircuitEntry extends Entry
{
    use HasCanvasOptions;
    use HasEdgeLabels;
    use HasNodeActions;
    use HasNodeBodies;
    use HasNodeTypes;

    protected string $view = 'circuit::entry';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn (CircuitEntry $component): array => $component->getFlatNodeActions(),
        ]);
    }

    public function isCircuitReadonly(): bool
    {
        return true;
    }

    protected function circuitHeightKey(): string
    {
        return 'entry';
    }

    public function getGraph(): Graph
    {
        return Graph::fromArray($this->getState());
    }

    public function getCanvasConfig(): array
    {
        $graph = $this->getGraph();

        return [
            'nodeTypes' => collect($this->getNodeTypes())
                ->map(fn (NodeType $type): array => $type->toArray())
                ->values()
                ->all(),
            ...$this->getCanvasOptions(),
            // Nothing is dragged on a read-only canvas, so there is nothing to
            // snap; the grid is still drawn, at the shared default size.
            'snapToGrid' => false,
            'gridSize' => (int) config('circuit.grid.size', 16),
            'readonly' => $this->isCircuitReadonly(),
            // Nothing on a read-only canvas changes client-side, so the action
            // bars rendered here are the only ones this page will ever need.
            'nodeActions' => $this->getNodeActionsHtml(),
            'nodeBodies' => $this->getNodeBodiesHtml(),
            'componentKey' => null,
            'live' => false,
            'problems' => [],
            'messages' => [],
            // Read-only, so state is handed over directly rather than via
            // $wire — with condition labels baked in, same as the canvas.
            'graph' => $this->withEdgeLabels($graph)->toArray(),
        ];
    }
}
