<?php

namespace Devletes\Circuit\Concerns;

use Closure;
use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Support\Graph;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Size;

/**
 * Shared by CircuitCanvas and CircuitEntry: developer-supplied actions rendered
 * ON the node card, next to the double-click-to-configure affordance (which
 * this never replaces).
 *
 * Nodes are rendered client-side by Alpine, so the actions cannot be emitted
 * inside the `x-for` template. Instead each node's actions are rendered
 * server-side with Filament's own markup — `Action::toHtml()` and
 * `ActionGroup`'s dropdown — into a `node id => html` map that Alpine injects
 * with `x-html`. Alpine's `x-html` runs `initTree()` on what it injects and
 * Livewire hooks Alpine's init interceptor, so `wire:click`, `x-on:click`,
 * tooltips and dropdowns all wire themselves up exactly as they would in a
 * normal server render, even though the canvas carries `wire:ignore`.
 *
 * The map is refreshed on the same round-trip that re-validates topology after
 * each commit, so a node added on the client picks its actions up too.
 */
trait HasNodeActions
{
    /** @var array<int, Action|ActionGroup>|Closure */
    protected array|Closure $nodeActions = [];

    /** @var array<int, Action|ActionGroup>|null */
    protected ?array $cachedNodeActions = null;

    /**
     * Actions rendered on every node card: `Filament\Actions\Action` instances
     * render as icon-only buttons, an `ActionGroup` renders as one trigger
     * opening a dropdown — the same shape Filament tables accept for
     * `recordActions()`. A Closure is evaluated at render time.
     *
     * Each action is mounted with `['nodeId' => ..., 'nodeType' => ...]`, so
     * closures can take `$arguments` and resolve the node array with
     * `$component->getNode($arguments['nodeId'])`. {@see NodeAction} adds
     * `$node` / `$nodeId` / `$nodeType` injections on top of that.
     *
     * @param  array<int, Action|ActionGroup>|Closure  $actions
     */
    public function nodeActions(array|Closure $actions): static
    {
        $this->nodeActions = $actions;
        $this->cachedNodeActions = null;

        return $this;
    }

    /** @return array<int, Action|ActionGroup> */
    public function getNodeActions(): array
    {
        return $this->cachedNodeActions ??= $this->resolveNodeActions();
    }

    public function hasNodeActions(): bool
    {
        return $this->getNodeActions() !== [];
    }

    /**
     * Every action, groups flattened — what gets registered on the component so
     * `$wire.mountAction(name, ...)` can find it. Grouped actions are mounted
     * by their own name; the group is only a rendering container.
     *
     * @return array<int, Action>
     */
    public function getFlatNodeActions(): array
    {
        $flat = [];

        foreach ($this->getNodeActions() as $action) {
            if ($action instanceof ActionGroup) {
                foreach ($action->getFlatActions() as $grouped) {
                    $flat[] = $grouped;
                }

                continue;
            }

            $flat[] = $action;
        }

        return $flat;
    }

    /**
     * The rendered action bar for every node that has one, keyed by node id.
     *
     * @return array<string, string>
     */
    public function getNodeActionsHtml(): array
    {
        if (! $this->hasNodeActions()) {
            return [];
        }

        $html = [];

        foreach (Graph::fromArray($this->getState())->nodes as $node) {
            $rendered = $this->renderNodeActions($node);

            if (filled($rendered)) {
                $html[$node['id']] = $rendered;
            }
        }

        return $html;
    }

    /**
     * One node's action bar. Visibility is evaluated per node — an action
     * hidden for this node (or a group whose every action is) renders nothing.
     */
    public function renderNodeActions(array $node): ?string
    {
        $arguments = [
            'nodeId' => $node['id'] ?? null,
            'nodeType' => $node['type'] ?? null,
        ];

        $html = '';

        foreach ($this->getNodeActions() as $action) {
            $forNode = $action instanceof ActionGroup
                ? $this->prepareNodeActionGroup($action, $arguments)
                : $this->prepareNodeAction($action, $arguments);

            if (! $forNode->isVisible()) {
                continue;
            }

            $html .= $forNode->toHtml();
        }

        return $html === '' ? null : $html;
    }

    /** @return array<string, mixed>|null */
    public function getNode(?string $id): ?array
    {
        if (blank($id)) {
            return null;
        }

        return Graph::fromArray($this->getState())->nodesById()[$id] ?? null;
    }

    /** Whether this canvas refuses graph mutations — CircuitEntry always does. */
    abstract public function isCircuitReadonly(): bool;

    /** @return array<int, Action|ActionGroup> */
    protected function resolveNodeActions(): array
    {
        $actions = $this->evaluate($this->nodeActions);

        $actions = is_array($actions) ? array_values($actions) : [];

        if (! $this->isCircuitReadonly()) {
            return $actions;
        }

        // A read-only canvas has no commit path, so an action that would edit
        // the graph could only render a button that silently does nothing.
        return array_values(array_filter(array_map(
            fn (Action|ActionGroup $action): Action|ActionGroup|null => $this->withoutGraphMutations($action),
            $actions,
        )));
    }

    protected function withoutGraphMutations(Action|ActionGroup $action): Action|ActionGroup|null
    {
        if (! $action instanceof ActionGroup) {
            return ($action instanceof NodeAction && $action->isGraphMutating()) ? null : $action;
        }

        $kept = array_values(array_filter(array_map(
            fn (Action|ActionGroup $grouped): Action|ActionGroup|null => $this->withoutGraphMutations($grouped),
            $action->getActions(),
        )));

        if ($kept === []) {
            return null;
        }

        $group = clone $action;
        $group->actions($kept);

        return $group;
    }

    /** A clone of one top-level action, bound to this node and styled for node chrome. */
    protected function prepareNodeAction(Action $action, array $arguments): Action
    {
        $action = $this->bindNodeAction($action, $arguments);

        // Icon-only, and small enough for node chrome — as *defaults*, so an
        // app that picked its own rendering (->button(), ->link(), ->size())
        // keeps it: getView()/getSize() prefer an explicitly set value.
        if (filled($action->getIcon())) {
            $action->defaultView($action::ICON_BUTTON_VIEW);
        }

        $action->defaultSize(Size::Small);

        return $action;
    }

    /**
     * A clone of one group, with every action inside it bound to this node.
     * The dropdown is teleported because the canvas surface clips overflow —
     * a panel opened on a node near the edge would otherwise be cut off.
     */
    protected function prepareNodeActionGroup(ActionGroup $group, array $arguments): ActionGroup
    {
        $bound = array_map(
            fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof ActionGroup
                ? $this->prepareNodeActionGroup($action, $arguments)
                : $this->bindNodeAction($action, $arguments),
            $group->getActions(),
        );

        $group = $this->prepareActionGroup(clone $group);
        $group->actions($bound);
        $group->defaultSize(Size::Small);

        if (! $group->hasDropdownTeleport()) {
            $group->dropdownTeleport();
        }

        return $group;
    }

    /**
     * Binds one action to a node: the schema component (so `mountAction` knows
     * where to look) plus the node arguments, on a clone — invoking a Filament
     * action with arguments is how tables bind a record, and it means
     * `visible()`, `label()` and `icon()` see `$arguments` at render time
     * exactly as `action()` will when it runs.
     */
    protected function bindNodeAction(Action $action, array $arguments): Action
    {
        return $this->scopeLoadingIndicator($this->prepareAction($action)($arguments));
    }

    /**
     * Names the whole `mountAction(...)` call as the action's loading target
     * rather than leaving Filament to derive one.
     *
     * Filament infers the target from the click handler by taking everything
     * before the first bracket, so every action on the canvas resolves to a
     * bare `mountAction` — and Livewire, matching on the method name alone,
     * spins all of them whichever one was clicked. With the arguments in the
     * target too, Livewire compares those as well and only the control that
     * was actually clicked shows a spinner.
     */
    protected function scopeLoadingIndicator(Action $action): Action
    {
        if (filled($action->getLivewireTarget())) {
            return $action;
        }

        $handler = (string) $action->getLivewireClickHandler();

        return str_contains($handler, '(')
            ? $action->livewireTarget($handler)
            : $action;
    }
}
