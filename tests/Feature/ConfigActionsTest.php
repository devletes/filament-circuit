<?php

namespace Devletes\Circuit\Tests\Feature;

use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Tests\Fixtures\CanvasComponent;
use Devletes\Circuit\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Livewire\Livewire;

/**
 * The built-in node and edge config dialogs are ordinary Filament actions:
 * they stack over whatever modal the canvas is already in, and the app can
 * reconfigure either of them.
 */
class ConfigActionsTest extends TestCase
{
    protected function canvas(): CircuitCanvas
    {
        $canvas = CircuitCanvas::make('graph')->nodeTypes(CanvasComponent::registry());

        Schema::make()->components([$canvas]);

        return $canvas;
    }

    /** @return array<string, mixed> */
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

    /** A canvas in a modal is the whole screen; a dialog that closed it would hide the node being edited. */
    public function test_the_config_dialogs_stack_over_a_parent_modal_rather_than_closing_it(): void
    {
        $canvas = $this->canvas();

        $this->assertTrue($canvas->getEditNodeAction()->shouldOverlayParentActions());
        $this->assertTrue($canvas->getEditEdgeAction()->shouldOverlayParentActions());
    }

    /** The flag has to reach the client, where Filament decides what to do with the parent modal. */
    public function test_mounting_a_dialog_tells_the_client_to_keep_the_parent_open(): void
    {
        $overlays = fn (string $name, array $params): bool => $params['shouldOverlayParentActions'] === true;

        Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])
            ->mountAction(TestAction::make('editNode')->arguments(['nodeId' => 'a1'])->schemaComponent('graph'))
            ->assertDispatched('sync-action-modals', $overlays);

        Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])
            ->mountAction(TestAction::make('editEdge')->arguments(['edgeId' => 'a1-end'])->schemaComponent('graph'))
            ->assertDispatched('sync-action-modals', $overlays);
    }

    public function test_either_dialog_can_be_reconfigured_by_the_app(): void
    {
        $canvas = $this->canvas()
            ->editNodeAction(fn (Action $action): Action => $action->modalHeading('Step')->overlayParentActions(false))
            ->editEdgeAction(fn (Action $action): Action => $action->modalWidth(Width::Large));

        $node = $canvas->getEditNodeAction();

        $this->assertFalse($node->shouldOverlayParentActions());
        $this->assertSame('Step', $node->getModalHeading());
        $this->assertSame(Width::Large, $canvas->getEditEdgeAction()->getModalWidth());

        // A closure that returns nothing keeps the action it was handed.
        $this->assertSame('editEdge', $this->canvas()->editEdgeAction(fn (Action $action) => null)->getEditEdgeAction()->getName());
    }
}
