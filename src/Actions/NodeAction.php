<?php

namespace Devletes\Circuit\Actions;

use Closure;
use Filament\Actions\Action;

/**
 * An action rendered on a node card.
 *
 * Plain `Filament\Actions\Action` instances work as node actions too — they are
 * mounted with `['nodeId' => ..., 'nodeType' => ...]`, so every closure can take
 * `$arguments` and resolve the node with `$component->getNode($arguments['nodeId'])`.
 *
 * NodeAction adds two things on top:
 *
 * - `$node`, `$nodeId` and `$nodeType` as closure injections, so a label /
 *   icon / visible / action closure can read the node without the lookup dance;
 * - `mutatesGraph()`, which marks an action a read-only canvas must not render.
 */
class NodeAction extends Action
{
    protected bool|Closure $mutatesGraph = false;

    protected ?Closure $livewireTargetCallback = null;

    /**
     * Declares that running this action edits the graph. Read-only canvases
     * ({@see \Devletes\Circuit\Infolists\Components\CircuitEntry}) drop such
     * actions rather than rendering a button that cannot work.
     */
    public function mutatesGraph(bool|Closure $condition = true): static
    {
        $this->mutatesGraph = $condition;

        return $this;
    }

    public function isGraphMutating(): bool
    {
        return (bool) $this->evaluate($this->mutatesGraph);
    }

    /**
     * The Livewire request this action's button should show a spinner for,
     * resolved per node — `livewireTarget()` takes a plain string, but an
     * action only knows which call it will make once it knows which node it
     * is rendered on.
     */
    public function livewireTargetUsing(?Closure $callback): static
    {
        $this->livewireTargetCallback = $callback;

        return $this;
    }

    public function getLivewireTarget(): ?string
    {
        if ($this->livewireTargetCallback !== null) {
            return $this->evaluate($this->livewireTargetCallback);
        }

        return parent::getLivewireTarget();
    }

    public function getNodeId(): ?string
    {
        return $this->getArguments()['nodeId'] ?? null;
    }

    public function getNodeTypeName(): ?string
    {
        return $this->getArguments()['nodeType'] ?? null;
    }

    /**
     * The node this action is rendered on (or mounted from), read back off the
     * canvas by id — the same lookup a plain Action would do by hand.
     *
     * @return array<string, mixed>|null
     */
    public function getNode(): ?array
    {
        $component = $this->getSchemaComponent();

        if ($component === null || ! method_exists($component, 'getNode')) {
            return null;
        }

        return $component->getNode($this->getNodeId());
    }

    /** @return array<mixed> */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'node' => [$this->getNode()],
            'nodeId' => [$this->getNodeId()],
            'nodeType' => [$this->getNodeTypeName()],
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
