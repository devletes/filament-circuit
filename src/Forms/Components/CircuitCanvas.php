<?php

namespace Devletes\Circuit\Forms\Components;

use Closure;
use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Concerns\HasCanvasOptions;
use Devletes\Circuit\Concerns\HasEdgeLabels;
use Devletes\Circuit\Concerns\HasNodeActions;
use Devletes\Circuit\Concerns\HasNodeBodies;
use Devletes\Circuit\Concerns\HasNodeTypes;
use Devletes\Circuit\Support\Graph;
use Devletes\Circuit\Support\NodeType;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Support\Js;
use Livewire\Attributes\Renderless;

/**
 * A node canvas as a Filament form field. Binds to a JSON column holding
 * `{ nodes, edges, viewport }` — see the package README for the contract.
 */
class CircuitCanvas extends Field
{
    use HasCanvasOptions;
    use HasEdgeLabels;
    use HasNodeActions;
    use HasNodeBodies;
    use HasNodeTypes;

    /**
     * Where {@see nodeSchemaSuffix()} components are state-pathed in the
     * node-config modal. Isolating them means their state can be dropped
     * wholesale before the config is written, so a suffix can never
     * accidentally store anything on the node.
     */
    public const SUFFIX_STATE_PATH = '_circuit';

    protected string $view = 'circuit::canvas';

    protected bool|Closure|null $snapToGrid = null;

    protected int|Closure|null $gridSize = null;

    protected bool|Closure $validateTopology = true;

    protected bool|Closure $validateNodeConfig = true;

    protected bool|Closure $nodeConfigInSlideOver = false;

    protected bool|Closure $edgeConfigInSlideOver = false;

    protected Width|string|Closure|null $nodeConfigModalWidth = null;

    protected Width|string|Closure|null $edgeConfigModalWidth = null;

    protected ?Closure $edgeSchema = null;

    protected ?Closure $nodeSchemaSuffix = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default(fn (): array => (new Graph)->toArray());

        $this->afterStateHydrated(static function (CircuitCanvas $component, $state): void {
            // Condition labels are recomputed on every hydration, so a saved
            // graph picks up ->edgeLabel() changes without being re-edited.
            $component->state($component->withEdgeLabels(Graph::fromArray($state))->toArray());
        });

        $this->dehydrateStateUsing(static fn ($state): array => Graph::fromArray($state)->toArray());

        $this->rule(static function (CircuitCanvas $component): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                if (! ($component->shouldValidateTopology() || $component->shouldValidateNodeConfig())) {
                    return;
                }

                $errors = Graph::fromArray($value)->validate(
                    $component->getNodeTypes(),
                    topology: $component->shouldValidateTopology(),
                    config: $component->shouldValidateNodeConfig(),
                );

                if ($errors !== []) {
                    $fail($errors[0]);
                }
            };
        });

        $this->registerActions([
            fn (CircuitCanvas $component): Action => $component->getEditNodeAction(),
            fn (CircuitCanvas $component): Action => $component->getEditEdgeAction(),
            fn (CircuitCanvas $component): array => $component->getFlatNodeActions(),
        ]);
    }

    /**
     * Mounted from the canvas by node id. Renders that node type's own schema
     * in a modal, so node config is edited with real Filament components and
     * real validation rather than a bespoke form. Opt into a slide-over with
     * {@see nodeConfigInSlideOver()}.
     */
    public function getEditNodeAction(): Action
    {
        return Action::make('editNode')
            ->label(__('Configure node'))
            ->slideOver(fn (CircuitCanvas $component): bool => $component->shouldOpenNodeConfigInSlideOver())
            ->modalWidth(fn (CircuitCanvas $component): Width|string|null => $component->getNodeConfigModalWidth())
            ->fillForm(function (array $arguments, CircuitCanvas $component): array {
                return $component->getNode($arguments['nodeId'] ?? null)['config'] ?? [];
            })
            ->schema(function (array $arguments, CircuitCanvas $component): array {
                return $component->getNodeSchemaFor($arguments['nodeId'] ?? null);
            })
            ->modalHeading(function (array $arguments, CircuitCanvas $component): string {
                return $component->getNodeTypeFor($arguments['nodeId'] ?? null)?->getLabel() ?? __('Node');
            })
            ->action(function (array $arguments, array $data, CircuitCanvas $component, $livewire): void {
                // Whatever ->nodeSchemaSuffix() contributed is context, not
                // config. It lives under its own state path precisely so it can
                // be dropped here in one line, whatever it turned out to be.
                unset($data[CircuitCanvas::SUFFIX_STATE_PATH]);

                $component->writeNodeConfig($arguments['nodeId'] ?? null, $data);

                // The canvas is wire:ignore'd, so it has to be told to re-read.
                // Node actions ride along: a label or a visible() can depend on
                // the config that just changed.
                $livewire->dispatch(
                    'circuit-reload',
                    statePath: $component->getStatePath(),
                    nodeActions: $component->getNodeActionsHtml(),
                    nodeBodies: $component->getNodeBodiesHtml(),
                );
            });
    }

    /**
     * Mounted from the canvas by edge id. Offers an outcome Select when the
     * source node's type declares outcomes, plus whatever condition
     * components the app contributed through {@see edgeSchema()}. Modal by
     * default; opt into a slide-over with {@see edgeConfigInSlideOver()}.
     */
    public function getEditEdgeAction(): Action
    {
        return Action::make('editEdge')
            ->label(__('Configure connection'))
            ->slideOver(fn (CircuitCanvas $component): bool => $component->shouldOpenEdgeConfigInSlideOver())
            ->modalWidth(fn (CircuitCanvas $component): Width|string|null => $component->getEdgeConfigModalWidth())
            ->fillForm(function (array $arguments, CircuitCanvas $component): array {
                $edge = $component->getEdge($arguments['edgeId'] ?? null) ?? [];

                return [
                    'outcome' => $edge['outcome'] ?? null,
                    'condition' => (array) ($edge['condition'] ?? []),
                ];
            })
            ->schema(function (array $arguments, CircuitCanvas $component): array {
                return $component->getEdgeSchemaFor($arguments['edgeId'] ?? null);
            })
            ->modalHeading(function (array $arguments, CircuitCanvas $component): string {
                $edge = $component->getEdge($arguments['edgeId'] ?? null) ?? [];

                $source = $component->getNodeTypeFor($edge['source'] ?? null)?->getLabel();
                $target = $component->getNodeTypeFor($edge['target'] ?? null)?->getLabel();

                return ($source && $target) ? "{$source} → {$target}" : __('Connection');
            })
            ->action(function (array $arguments, array $data, CircuitCanvas $component, $livewire): void {
                $component->writeEdgeConfig($arguments['edgeId'] ?? null, $data);

                // The canvas is wire:ignore'd, so it has to be told to re-read.
                $livewire->dispatch('circuit-reload', statePath: $component->getStatePath());
            });
    }

    /**
     * The modal contents for one node: that node type's own fields, followed by
     * whatever {@see nodeSchemaSuffix()} contributes — wrapped in a Group with
     * its own state path, so the suffix's state stays out of the node's config.
     *
     * @return array<int, mixed>
     */
    public function getNodeSchemaFor(?string $id): array
    {
        $components = $this->getNodeTypeFor($id)?->getSchema() ?? [];

        $suffix = $this->getNodeSchemaSuffixFor($id);

        if ($suffix !== []) {
            $components[] = Group::make($suffix)->statePath(static::SUFFIX_STATE_PATH);
        }

        return $components;
    }

    /**
     * The app's extra components for one node's config modal, if any.
     *
     * @return array<int, mixed>
     */
    public function getNodeSchemaSuffixFor(?string $id): array
    {
        if (! $this->nodeSchemaSuffix) {
            return [];
        }

        $node = $this->getNode($id);

        if (! $node) {
            return [];
        }

        $suffix = $this->evaluate($this->nodeSchemaSuffix, [
            'node' => $node,
            'nodeId' => $id,
            'nodeType' => $this->getNodeTypeFor($id),
            'outgoing' => $this->getOutgoingEdges($id),
            'incoming' => $this->getIncomingEdges($id),
        ]);

        return is_array($suffix) ? array_values($suffix) : (blank($suffix) ? [] : [$suffix]);
    }

    /**
     * The slide-over contents for one edge: an outcome Select when the source
     * node's type declares outcomes, then the app's condition components,
     * state-pathed under `condition` so the payload stays a single opaque
     * array on the edge.
     *
     * @return array<int, mixed>
     */
    public function getEdgeSchemaFor(?string $id): array
    {
        $edge = $this->getEdge($id);

        if (! $edge) {
            return [];
        }

        // Mirrors canConfigureEdge() on the client: nothing gates the way out
        // of an initial node, or the way in to a terminal one, so a connection
        // touching either has no form to open.
        if ($this->getNodeTypeFor($edge['source'] ?? null)?->isInitial()) {
            return [];
        }

        if ($this->getNodeTypeFor($edge['target'] ?? null)?->isTerminal()) {
            return [];
        }

        $sourceType = $this->getNodeTypeFor($edge['source'] ?? null);

        $components = [];

        $outcomes = $sourceType?->getOutcomes() ?? [];

        if ($outcomes !== []) {
            $components[] = Select::make('outcome')
                ->label(__('Outcome'))
                ->options($outcomes)
                ->placeholder(__('Always'))
                ->helperText(__('Follow this connection only when :source ends with this outcome.', ['source' => $sourceType->getLabel()]));
        }

        if ($this->edgeSchema) {
            $condition = $this->evaluate($this->edgeSchema, [
                'edge' => $edge,
                'source' => $this->getNode($edge['source'] ?? null),
                'target' => $this->getNode($edge['target'] ?? null),
                'condition' => (array) ($edge['condition'] ?? []),
                'sourceType' => $sourceType,
                'targetType' => $this->getNodeTypeFor($edge['target'] ?? null),
            ]);

            if (filled($condition)) {
                $components[] = Group::make($condition)->statePath('condition');
            }
        }

        return $components;
    }

    /**
     * A ready-made node action that opens the built-in node-config modal —
     * the same one a double-click mounts. Handled entirely on the client
     * (it calls the canvas's own `editNode()`), so it costs no round-trip.
     */
    public static function configureNodeAction(): NodeAction
    {
        return NodeAction::make('configureNode')
            ->label(__('Configure node'))
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            // A structural type — start, end — declares no fields, so this has
            // nothing to open; the canvas ignores the click either way, and a
            // button that does nothing is worse than no button. An app that
            // wants different rules calls ->hidden() on the returned action.
            ->hidden(fn (CircuitCanvas $component, ?string $nodeId): bool => ($component->getNodeTypeFor($nodeId)?->getSchema() ?? []) === [])
            // Opening the modal is a round trip, but the click is an Alpine
            // handler rather than wire:click, so Filament has nothing to infer
            // a loading target from. Naming the call restores the native
            // behaviour — the icon swaps for a spinner and the button disables
            // itself — and naming it in full keeps that spinner off every other
            // action mounted through the same method.
            ->livewireTargetUsing(fn (CircuitCanvas $component, ?string $nodeId): string => $component->getEditNodeTarget($nodeId))
            ->mutatesGraph()
            ->alpineClickHandler(fn (?string $nodeId): string => 'editNode('.Js::from($nodeId).')');
    }

    /**
     * A ready-made node action that removes the node and every edge touching
     * it. Runs through the canvas's own `removeNode()` — the same graph
     * mutation the toolbar's *Delete selection* button performs — so there is
     * one implementation of "drop a node", not two.
     */
    public static function deleteNodeAction(): NodeAction
    {
        return NodeAction::make('deleteNode')
            ->label(__('Delete node'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->mutatesGraph()
            ->alpineClickHandler(fn (?string $nodeId): string => 'removeNode('.Js::from($nodeId).')');
    }

    /** Open node config in a slide-over instead of the default modal. */
    public function nodeConfigInSlideOver(bool|Closure $condition = true): static
    {
        $this->nodeConfigInSlideOver = $condition;

        return $this;
    }

    /** Open edge config in a slide-over instead of the default modal. */
    public function edgeConfigInSlideOver(bool|Closure $condition = true): static
    {
        $this->edgeConfigInSlideOver = $condition;

        return $this;
    }

    public function nodeConfigModalWidth(Width|string|Closure|null $width): static
    {
        $this->nodeConfigModalWidth = $width;

        return $this;
    }

    public function edgeConfigModalWidth(Width|string|Closure|null $width): static
    {
        $this->edgeConfigModalWidth = $width;

        return $this;
    }

    public function shouldOpenNodeConfigInSlideOver(): bool
    {
        return (bool) $this->evaluate($this->nodeConfigInSlideOver);
    }

    public function shouldOpenEdgeConfigInSlideOver(): bool
    {
        return (bool) $this->evaluate($this->edgeConfigInSlideOver);
    }

    public function getNodeConfigModalWidth(): Width|string|null
    {
        return $this->evaluate($this->nodeConfigModalWidth);
    }

    public function getEdgeConfigModalWidth(): Width|string|null
    {
        return $this->evaluate($this->edgeConfigModalWidth);
    }

    public function isCircuitReadonly(): bool
    {
        return $this->isDisabled();
    }

    /**
     * The `schemaComponent` context Filament expects when mounting this
     * component's actions — editNode and editEdge share it, since Filament
     * keys both by the same container. Resolved from a live action so it
     * always matches whatever Filament would have produced for a rendered
     * action button.
     */
    public function getEditNodeActionContext(): ?string
    {
        try {
            $action = $this->getAction('editNode');
        } catch (\Throwable) {
            return $this->getKey();
        }

        return $action?->getContext()['schemaComponent'] ?? $this->getKey();
    }

    /**
     * The exact `mountAction` call the canvas makes to open one node's config,
     * written the way Filament writes its own click handlers. Used as a
     * Livewire loading target, where the arguments are compared too — so it
     * has to match what `editNode()` sends down to the argument order.
     */
    public function getEditNodeTarget(?string $nodeId): string
    {
        $arguments = Js::from(['nodeId' => $nodeId]);
        $context = Js::from(['schemaComponent' => $this->getEditNodeActionContext()]);

        return "mountAction('editNode', {$arguments}, {$context})";
    }

    public function getNodeTypeFor(?string $id): ?NodeType
    {
        $node = $this->getNode($id);

        return $this->getNodeTypes()[$node['type'] ?? ''] ?? null;
    }

    public function writeNodeConfig(?string $id, array $config): void
    {
        if (blank($id)) {
            return;
        }

        $graph = Graph::fromArray($this->getState());
        $summary = $this->getNodeTypeFor($id)?->summarise($config);

        $nodes = array_map(
            fn (array $node): array => $node['id'] === $id
                ? [...$node, 'config' => $config, 'summary' => $summary]
                : $node,
            $graph->nodes,
        );

        $this->state((new Graph($nodes, $graph->edges, $graph->viewport))->toArray());
    }

    /** @return array<string, mixed>|null */
    public function getEdge(?string $id): ?array
    {
        if (blank($id)) {
            return null;
        }

        foreach (Graph::fromArray($this->getState())->edges as $edge) {
            if (($edge['id'] ?? null) === $id) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * The edges leaving a node, in graph order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOutgoingEdges(?string $id): array
    {
        return $this->getEdgesTouching($id, 'source');
    }

    /**
     * The edges arriving at a node, in graph order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIncomingEdges(?string $id): array
    {
        return $this->getEdgesTouching($id, 'target');
    }

    public function writeEdgeConfig(?string $id, array $data): void
    {
        if (blank($id)) {
            return;
        }

        $graph = Graph::fromArray($this->getState());

        $edges = array_map(function (array $edge) use ($id, $data): array {
            if (($edge['id'] ?? null) !== $id) {
                return $edge;
            }

            // Only touch what the slide-over actually offered: a source type
            // without outcomes mounts no Select, so `outcome` stays as-is.
            if (array_key_exists('outcome', $data)) {
                $edge['outcome'] = filled($data['outcome']) ? $data['outcome'] : null;
            }

            if (array_key_exists('condition', $data)) {
                $edge['condition'] = $this->conditionIsFilled($data['condition']) ? $data['condition'] : null;
            }

            return [...$edge, 'label' => $this->getEdgeConditionLabel($edge)];
        }, $graph->edges);

        $this->state((new Graph($graph->nodes, $edges, $graph->viewport))->toArray());
    }

    /**
     * Lets the app contribute Filament components for an edge's `condition`
     * payload — Circuit stores the payload verbatim and never interprets it.
     * The closure receives `$edge`, `$source` / `$target` (node arrays),
     * `$condition` (current payload), and `$sourceType` / `$targetType`
     * (NodeType instances); it returns an array of schema components, which
     * are state-pathed under `condition` automatically.
     *
     * @param  Closure(...): array  $callback
     */
    public function edgeSchema(?Closure $callback): static
    {
        $this->edgeSchema = $callback;

        return $this;
    }

    public function hasEdgeSchema(): bool
    {
        return $this->edgeSchema !== null;
    }

    /**
     * Appends the app's own components to every node-config modal, after the
     * node type's fields — for context the type cannot know about, such as
     * what this node connects to.
     *
     * The closure receives `$node` (the full node array), `$nodeId`,
     * `$nodeType` (a {@see NodeType}), and `$outgoing` / `$incoming` (the edge
     * arrays touching that node); it returns an array of schema components.
     * Return an empty array to add nothing for that node.
     *
     * The components are state-pathed under {@see SUFFIX_STATE_PATH} and that
     * whole branch is discarded on submit, so a suffix is display-only whatever
     * it puts on screen — it can never write to the node's `config`.
     *
     * @param  Closure(...): array  $callback
     */
    public function nodeSchemaSuffix(?Closure $callback): static
    {
        $this->nodeSchemaSuffix = $callback;

        return $this;
    }

    public function hasNodeSchemaSuffix(): bool
    {
        return $this->nodeSchemaSuffix !== null;
    }

    protected function circuitHeightKey(): string
    {
        return 'canvas';
    }

    public function snapToGrid(bool|Closure|null $condition = true): static
    {
        $this->snapToGrid = $condition;

        return $this;
    }

    public function gridSize(int|Closure|null $size): static
    {
        $this->gridSize = $size;

        return $this;
    }

    /** Skip save-time topology checks — useful for free-form diagrams. */
    public function validateTopology(bool|Closure $condition = true): static
    {
        $this->validateTopology = $condition;

        return $this;
    }

    public function shouldSnapToGrid(): bool
    {
        return (bool) ($this->evaluate($this->snapToGrid) ?? config('circuit.grid.snap', true));
    }

    public function getGridSize(): int
    {
        return (int) ($this->evaluate($this->gridSize) ?? config('circuit.grid.size', 16));
    }

    public function shouldValidateTopology(): bool
    {
        return (bool) $this->evaluate($this->validateTopology);
    }

    /**
     * Whether the canvas is still showing exactly the graph `default()` seeded
     * it with — a new record, opened and not yet touched.
     *
     * A starting point is incomplete by definition: a Start and an End with
     * nothing between them has no outgoing connection and an unreachable exit,
     * both true and neither the author's doing. Painting a form red before it
     * has been used trains people to ignore the colour, so the live highlights
     * hold off until the graph is actually theirs. Nothing is skipped at save
     * time — the validation rule runs on whatever is submitted.
     */
    public function isPristine(): bool
    {
        if (! $this->hasDefaultGraph()) {
            return false;
        }

        return Graph::fromArray($this->getState())->toArray()
            === Graph::fromArray($this->evaluate($this->getDefaultState()))->toArray();
    }

    protected function hasDefaultGraph(): bool
    {
        return filled($this->getDefaultState());
    }

    /**
     * Skip save-time config checks — each node type's own
     * {@see NodeType::validateConfigUsing()} — leaving structure the only rule.
     */
    public function validateNodeConfig(bool|Closure $condition = true): static
    {
        $this->validateNodeConfig = $condition;

        return $this;
    }

    public function shouldValidateNodeConfig(): bool
    {
        return (bool) $this->evaluate($this->validateNodeConfig);
    }

    /**
     * Called from the canvas via $wire.callSchemaComponentMethod after each
     * commit, so node-level error highlights track the live graph instead of
     * the last full render (the canvas is wire:ignore'd).
     *
     * The graph is passed explicitly rather than read from staged Livewire
     * updates — whether a deferred $wire.set has been applied by the time an
     * exposed method runs is an ordering detail not worth depending on.
     * Writing it into state is safe: the client can already write this state
     * through $wire.set on any enabled field, and disabled fields are guarded.
     *
     * Node actions ride along on the same trip: they are rendered server-side
     * per node, so a node added on the client has no action bar until the
     * canvas hears back — this is where it does.
     *
     * @return array{nodes: array<string, string>, messages: array<int, string>, actions: array<string, string>, bodies: array<string, string>}
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function refreshProblems(?array $graph = null): array
    {
        if ($graph !== null && ! $this->isDisabled()) {
            $this->state(Graph::fromArray($graph)->toArray());
        }

        return [
            ...$this->getProblems(),
            'actions' => $this->getNodeActionsHtml(),
            'bodies' => $this->getNodeBodiesHtml(),
        ];
    }

    /**
     * Topology problems keyed by node id, so the canvas can mark the offending
     * node instead of only printing a message under the field.
     *
     * @return array{nodes: array<string, string>, messages: array<int, string>}
     */
    public function getProblems(): array
    {
        if ($this->isPristine() || ! ($this->shouldValidateTopology() || $this->shouldValidateNodeConfig())) {
            return ['nodes' => [], 'messages' => []];
        }

        $problems = Graph::fromArray($this->getState())->problems(
            $this->getNodeTypes(),
            topology: $this->shouldValidateTopology(),
            config: $this->shouldValidateNodeConfig(),
        );

        $nodes = [];

        foreach ($problems as $problem) {
            if ($problem['node'] === null || isset($nodes[$problem['node']])) {
                continue;
            }

            // On the node, without the label the card is already showing.
            $nodes[$problem['node']] = $problem['detail'] ?? $problem['message'];
        }

        return [
            'nodes' => $nodes,
            'messages' => array_values(array_unique(array_column($problems, 'message'))),
        ];
    }

    /**
     * @param  'source'|'target'  $end
     * @return array<int, array<string, mixed>>
     */
    protected function getEdgesTouching(?string $id, string $end): array
    {
        if (blank($id)) {
            return [];
        }

        return array_values(array_filter(
            Graph::fromArray($this->getState())->edges,
            fn (array $edge): bool => ($edge[$end] ?? null) === $id,
        ));
    }

    /** Config handed to the Alpine component. */
    public function getCanvasConfig(): array
    {
        $problems = $this->getProblems();

        return [
            'nodeTypes' => collect($this->getNodeTypes())
                ->map(fn (NodeType $type): array => $type->toArray())
                ->values()
                ->all(),
            ...$this->getCanvasOptions(),
            'snapToGrid' => $this->shouldSnapToGrid(),
            'gridSize' => $this->getGridSize(),
            'readonly' => $this->isCircuitReadonly(),
            // Developer-supplied per-node actions, rendered server-side with
            // Filament's own markup and injected into each node card.
            'nodeActions' => $this->getNodeActionsHtml(),
            // Developer-supplied per-node infolists, rendered the same way and
            // injected into the card below the label.
            'nodeBodies' => $this->getNodeBodiesHtml(),
            // Lets the canvas mount this component's own actions from Alpine.
            // Taken from the action itself rather than computed here: Filament
            // keys schema-component actions by their *container*, not the field.
            'componentKey' => $this->getEditNodeActionContext(),
            // Whether the app contributed condition components — with none,
            // and no outcomes on a source type, edge config has nothing to
            // offer and the canvas skips mounting an empty slide-over.
            'hasEdgeSchema' => $this->hasEdgeSchema(),
            // When the field is live, commits re-render so afterStateUpdated fires.
            'live' => $this->isLive(),
            'problems' => $problems['nodes'],
            'messages' => $problems['messages'],
        ];
    }
}
