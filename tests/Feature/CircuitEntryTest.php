<?php

namespace Devletes\Circuit\Tests\Feature;

use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Infolists\Components\CircuitEntry;
use Devletes\Circuit\Tests\Fixtures\CanvasComponent;
use Devletes\Circuit\Tests\Fixtures\EntryComponent;
use Devletes\Circuit\Tests\TestCase;
use Filament\Schemas\Schema;
use Livewire\Livewire;

class CircuitEntryTest extends TestCase
{
    protected function graph(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'a1', 'type' => 'approval', 'position' => ['x' => 0, 'y' => 120], 'config' => ['approver' => 'Jane'], 'summary' => 'Jane'],
                ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 240], 'config' => []],
            ],
            'edges' => [
                ['id' => 'start-a1', 'source' => 'start', 'target' => 'a1'],
                ['id' => 'a1-end', 'source' => 'a1', 'target' => 'end'],
            ],
        ];
    }

    protected function entry(object $page): CircuitEntry
    {
        $entry = $page->instance()->getSchemaComponent('infolist.graph');

        $this->assertInstanceOf(CircuitEntry::class, $entry);

        return $entry;
    }

    public function test_it_renders_the_same_canvas_without_editing_affordances(): void
    {
        $page = Livewire::test(EntryComponent::class, ['graph' => $this->graph()]);

        $page->assertOk()->assertSeeHtml('fi-circuit');

        $this->assertTrue($this->entry($page)->isCircuitReadonly());
    }

    public function test_the_graph_travels_in_the_config_rather_than_through_wire(): void
    {
        $config = $this->entry(Livewire::test(EntryComponent::class, ['graph' => $this->graph()]))
            ->getCanvasConfig();

        // Nothing here changes client-side, so the state is handed over
        // directly instead of being read back from a state path.
        $this->assertTrue($config['readonly']);
        $this->assertFalse($config['live']);
        $this->assertNull($config['componentKey']);
        $this->assertCount(3, $config['graph']['nodes']);
        $this->assertSame([], $config['problems']);

        // Read-only, but still worth being able to make taller.
        $this->assertSame(480, $config['height']);
        $this->assertTrue($config['resizable']);
    }

    public function test_the_grip_can_be_turned_off_on_an_entry_too(): void
    {
        $entry = CircuitEntry::make('graph')->nodeTypes(CanvasComponent::registry());

        $this->assertTrue($entry->isResizable());
        $this->assertFalse($entry->resizable(false)->isResizable());
    }

    public function test_it_drops_node_actions_that_would_edit_the_graph(): void
    {
        $entry = CircuitEntry::make('graph')
            ->nodeActions([
                CircuitCanvas::configureNodeAction(),
                CircuitCanvas::deleteNodeAction(),
                NodeAction::make('viewDetails')->label('View details'),
            ]);

        Schema::make()->components([$entry]);

        $this->assertSame(
            ['viewDetails'],
            array_map(fn ($action): string => $action->getName(), $entry->getFlatNodeActions()),
        );
    }

    public function test_a_read_only_canvas_with_only_mutating_actions_renders_none(): void
    {
        $entry = $this->entry(Livewire::test(EntryComponent::class, ['graph' => $this->graph()]));

        $this->assertSame([], $entry->getNodeActionsHtml());
    }

    public function test_node_infolists_still_render_on_a_read_only_canvas(): void
    {
        $bodies = $this->entry(Livewire::test(EntryComponent::class, ['graph' => $this->graph()]))
            ->getNodeBodiesHtml();

        $this->assertSame(['a1'], array_keys($bodies));
        $this->assertStringContainsString('Jane', $bodies['a1']);
    }

    public function test_the_toolbar_keeps_the_tools_that_only_change_how_the_graph_is_looked_at(): void
    {
        $page = Livewire::test(EntryComponent::class, ['graph' => $this->graph()]);

        $page->assertSeeHtml('fi-circuit-toolbar')
            ->assertSeeHtml('Zoom in')
            ->assertSeeHtml('Zoom out')
            ->assertSeeHtml('Fit to view')
            ->assertSeeHtml('Switch to a left-to-right flow');

        // Everything else on the bar edits the graph, and a read-only canvas
        // has no commit path to run an edit through. ("Configure connection"
        // is not checked here: it also labels the edge-midpoint control, whose
        // x-for template is emitted either way and stays empty on an entry.)
        $page->assertDontSeeHtml('Add node')
            ->assertDontSeeHtml('Undo')
            ->assertDontSeeHtml('Tidy up')
            ->assertDontSeeHtml('Delete selection');
    }

    public function test_an_entry_with_both_view_tools_off_has_no_toolbar_at_all(): void
    {
        Livewire::test(EntryComponent::class, ['graph' => $this->graph(), 'bare' => true])
            ->assertSeeHtml('fi-circuit-surface')
            ->assertDontSeeHtml('fi-circuit-toolbar');
    }

    public function test_direction_flips_the_flow_for_the_client(): void
    {
        $entry = CircuitEntry::make('graph')->nodeTypes(CanvasComponent::registry());

        $this->assertSame('vertical', $entry->getDirection());
        $this->assertSame('horizontal', $entry->horizontal()->getDirection());
    }
}
