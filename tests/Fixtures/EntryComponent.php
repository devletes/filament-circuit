<?php

namespace Devletes\Circuit\Tests\Fixtures;

use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Infolists\Components\CircuitEntry;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/** The read-only counterpart of {@see CanvasComponent}, as a View page would use it. */
class EntryComponent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public array $graph = [];

    /** Both view tools off — the one combination that leaves an entry no toolbar. */
    public bool $bare = false;

    public function mount(array $graph = [], bool $bare = false): void
    {
        $this->graph = $graph;
        $this->bare = $bare;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            CircuitEntry::make('graph')
                ->state(fn (): array => $this->graph)
                ->nodeTypes(CanvasComponent::registry())
                ->zoomable(! $this->bare)
                ->orientable(! $this->bare)
                ->nodeActions([
                    // Both built-ins mutate the graph, so a read-only canvas
                    // must drop them rather than render buttons that cannot work.
                    CircuitCanvas::configureNodeAction(),
                    CircuitCanvas::deleteNodeAction(),
                ]),
        ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>{{ $this->infolist }}</div>
        BLADE;
    }
}
