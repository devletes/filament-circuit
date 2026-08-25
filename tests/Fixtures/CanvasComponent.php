<?php

namespace Devletes\Circuit\Tests\Fixtures;

use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Support\NodeRegistry;
use Devletes\Circuit\Tests\Fixtures\Nodes\ApprovalNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\EndNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\NotifyNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\StartNode;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * The smallest real host for the canvas: a Livewire component with a Filament
 * schema. Tests drive this rather than calling the component's methods
 * directly, so the field wrapper, the mounted actions and the validation rule
 * are all exercised the way an application would hit them.
 */
class CanvasComponent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public ?array $data = [];

    /** Set by a test before mount to exercise the opt-outs. */
    public bool $validateTopology = true;

    public bool $validateNodeConfig = true;

    public int $updates = 0;

    public bool $saved = false;

    public function mount(?array $graph = null): void
    {
        // No graph means a new record, which is what fill() with no arguments
        // means to Filament: apply the field defaults.
        $graph === null
            ? $this->form->fill()
            : $this->form->fill(['graph' => $graph]);
    }

    /** What an application's save button does: read validated state back. */
    public function save(): void
    {
        $this->form->getState();

        $this->saved = true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                CircuitCanvas::make('graph')
                    ->nodeTypes(static::registry())
                    ->default(fn (): array => static::defaultGraph())
                    ->validateTopology(fn (): bool => $this->validateTopology)
                    ->validateNodeConfig(fn (): bool => $this->validateNodeConfig)
                    ->nodeActions([
                        CircuitCanvas::configureNodeAction(),
                        CircuitCanvas::deleteNodeAction()
                            ->hidden(function (CircuitCanvas $component, ?string $nodeId): bool {
                                $type = $component->getNodeTypeFor($nodeId);

                                return (bool) $type?->isInitial() || (bool) $type?->isTerminal();
                            }),
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->updates++),
            ])
            ->statePath('data');
    }

    /** What a new record opens on: the two ends, nothing between them. */
    public static function defaultGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 256], 'config' => []],
            ],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ];
    }

    public static function registry(): NodeRegistry
    {
        return NodeRegistry::make()->register(
            StartNode::class,
            ApprovalNode::class,
            NotifyNode::class,
            EndNode::class,
        );
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->form }}
                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}
