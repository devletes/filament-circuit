<?php

namespace Devletes\Circuit\Tests\Feature;

use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Support\Graph;
use Devletes\Circuit\Tests\Fixtures\CanvasComponent;
use Devletes\Circuit\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * The canvas inside a real Livewire component with a real Filament schema —
 * the field wrapper, the mounted config actions, and the save-time rule.
 */
class CircuitCanvasTest extends TestCase
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

    protected function canvas(object $page): CircuitCanvas
    {
        $canvas = $page->instance()->getSchemaComponent('form.graph');

        $this->assertInstanceOf(CircuitCanvas::class, $canvas);

        return $canvas;
    }

    public function test_the_canvas_renders_inside_a_filament_schema(): void
    {
        Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])
            ->assertOk()
            ->assertSeeHtml('fi-circuit')
            // Node types reach the client with their rendered icons.
            ->assertSeeHtml('circuit-node-icon-svg');
    }

    public function test_state_hydrates_and_dehydrates_through_the_graph_object(): void
    {
        $page = Livewire::test(CanvasComponent::class, ['graph' => ['nodes' => [
            ['id' => 'lonely', 'type' => 'approval'],
        ]]]);

        $state = $page->get('data')['graph'];

        // The envelope is always complete — nodes, edges and a viewport —
        // whatever the column happened to hold. Per-node defaults are the
        // client's job, so a sparse node survives the trip untouched.
        $this->assertSame(['nodes', 'edges', 'viewport'], array_keys($state));
        $this->assertSame([], $state['edges']);
        $this->assertSame(['x' => 0, 'y' => 0, 'zoom' => 1], $state['viewport']);
        $this->assertSame(['id' => 'lonely', 'type' => 'approval'], $state['nodes'][0]);
    }

    public function test_a_live_commit_reaches_after_state_updated(): void
    {
        $graph = $this->graph();
        $graph['nodes'][0]['position'] = ['x' => 32, 'y' => 0];

        Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])
            ->assertSet('updates', 0)
            ->set('data.graph', $graph)
            ->assertSet('updates', 1)
            ->assertSet('data.graph.nodes.0.position.x', 32);
    }

    public function test_the_config_action_writes_config_and_summary_back(): void
    {
        $page = Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]);

        $action = TestAction::make('editNode')
            ->arguments(['nodeId' => 'a1'])
            ->schemaComponent('graph');

        $page->mountAction($action)
            ->assertActionMounted($action)
            ->setActionData(['approver' => 'Sam'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $node = collect($page->get('data')['graph']['nodes'])->firstWhere('id', 'a1');

        $this->assertSame('Sam', $node['config']['approver']);
        $this->assertSame('Sam', $node['summary']);
    }

    public function test_structural_connections_have_nothing_to_configure(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));

        // Leaving an initial node, or arriving at a terminal one, is structure
        // rather than a decision — neither end offers a form.
        $this->assertSame([], $canvas->getEdgeSchemaFor('start-a1'));
        $this->assertSame([], $canvas->getEdgeSchemaFor('a1-end'));
        $this->assertSame([], $canvas->getEdgeSchemaFor('no-such-edge'));
    }

    public function test_a_connection_between_two_ordinary_nodes_offers_its_outcomes(): void
    {
        $graph = $this->graph();
        $graph['nodes'][] = ['id' => 'n1', 'type' => 'notify', 'position' => ['x' => 0, 'y' => 180], 'config' => []];
        $graph['edges'] = [
            ['id' => 'start-a1', 'source' => 'start', 'target' => 'a1'],
            ['id' => 'a1-n1', 'source' => 'a1', 'target' => 'n1'],
            ['id' => 'n1-end', 'source' => 'n1', 'target' => 'end'],
        ];

        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $graph]));

        // The source type declares outcomes, so the edge gets the outcome Select.
        $this->assertNotSame([], $canvas->getEdgeSchemaFor('a1-n1'));
    }

    public function test_node_actions_are_rendered_per_node_and_hidden_where_they_do_not_apply(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));
        $html = $canvas->getNodeActionsHtml();

        // Configure hides itself where the type has no fields, and this app
        // hides delete on the entry and exit nodes — between them that leaves
        // start and end with no bar at all, so they never reach the map.
        $this->assertSame(['a1'], array_keys($html));

        $this->assertStringContainsString("editNode('a1')", $html['a1']);
        $this->assertStringContainsString("removeNode('a1')", $html['a1']);
    }

    public function test_each_node_action_targets_its_own_call_and_not_the_method_they_share(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));
        $html = html_entity_decode($canvas->getNodeActionsHtml()['a1']);

        // Filament reads a loading target off the click handler by taking
        // everything before the first bracket, so every action the canvas
        // mounts resolves to a bare `mountAction` — and Livewire, matching on
        // the method name alone, spins all of them whichever one was clicked.
        // Naming the arguments too is what keeps the spinner on the control
        // that was pressed.
        $this->assertStringContainsString($canvas->getEditNodeTarget('a1'), $html);
        $this->assertStringNotContainsString('wire:target="mountAction"', $html);
        $this->assertNotSame($canvas->getEditNodeTarget('a1'), $canvas->getEditNodeTarget('start'));
    }

    public function test_node_bodies_are_rendered_only_for_types_that_declare_an_infolist(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));
        $bodies = $canvas->getNodeBodiesHtml();

        $this->assertSame(['a1'], array_keys($bodies));

        // Entries read the node's own config, not the type's.
        $this->assertStringContainsString('Jane', $bodies['a1']);
    }

    public function test_the_post_commit_trip_carries_problems_actions_and_bodies(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));

        $refreshed = $canvas->refreshProblems($this->graph());

        $this->assertSame(['nodes', 'messages', 'actions', 'bodies'], array_keys($refreshed));
        $this->assertSame([], $refreshed['messages']);
    }

    public function test_problems_are_keyed_to_the_node_they_blame(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];
        $graph['nodes'][] = ['id' => 'stray', 'type' => 'notify', 'position' => ['x' => 400, 'y' => 0], 'config' => []];

        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $graph]));
        $problems = $canvas->refreshProblems($graph);

        // On the node, without the label the card already shows; the same
        // problem keeps its label in the list beneath the field.
        $this->assertSame('An approver must be selected.', $problems['nodes']['a1']);
        $this->assertContains('Approval: An approver must be selected.', $problems['messages']);
        $this->assertArrayHasKey('stray', $problems['nodes']);
    }

    public function test_each_half_of_validation_can_be_switched_off(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];

        $page = Livewire::test(CanvasComponent::class, ['graph' => $graph]);
        $canvas = $this->canvas($page);

        $this->assertContains('Approval: An approver must be selected.', $canvas->getProblems()['messages']);

        $page->set('validateNodeConfig', false);
        $this->assertNotContains(
            'Approval: An approver must be selected.',
            $this->canvas($page)->getProblems()['messages'],
        );

        $page->set('validateTopology', false);
        $this->assertSame(['nodes' => [], 'messages' => []], $this->canvas($page)->getProblems());
    }

    public function test_an_untouched_default_graph_reports_nothing_yet(): void
    {
        // A starting point is incomplete by definition — Start has no outgoing
        // connection and End is unreachable — and neither is the author's doing.
        $page = Livewire::test(CanvasComponent::class);
        $canvas = $this->canvas($page);

        $this->assertTrue($canvas->isPristine());
        $this->assertSame(['nodes' => [], 'messages' => []], $canvas->getProblems());
    }

    public function test_the_moment_the_graph_is_edited_its_problems_surface(): void
    {
        $page = Livewire::test(CanvasComponent::class);

        $graph = CanvasComponent::defaultGraph();
        $graph['nodes'][0]['position'] = ['x' => 40, 'y' => 0];

        $page->set('data.graph', $graph);
        $canvas = $this->canvas($page);

        $this->assertFalse($canvas->isPristine());
        $this->assertContains('Start has no outgoing connection.', $canvas->getProblems()['messages']);
    }

    public function test_a_loaded_record_is_never_pristine_so_its_problems_show_at_once(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];

        // Nothing is being seeded here — this is a saved graph, and an author
        // opening it should see what is wrong with it before touching anything.
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $graph]));

        $this->assertFalse($canvas->isPristine());
        $this->assertContains('Approval: An approver must be selected.', $canvas->getProblems()['messages']);
    }

    public function test_holding_the_highlights_back_does_not_let_a_broken_graph_save(): void
    {
        // Pristine or not, the rule runs on whatever is submitted.
        Livewire::test(CanvasComponent::class)
            ->call('save')
            ->assertHasErrors('data.graph');
    }

    public function test_a_broken_graph_fails_validation_on_save(): void
    {
        $broken = ['nodes' => [
            ['id' => 'a1', 'type' => 'approval', 'position' => ['x' => 0, 'y' => 0], 'config' => ['approver' => 'Jane']],
        ], 'edges' => []];

        Livewire::test(CanvasComponent::class, ['graph' => $broken])
            ->call('save')
            ->assertHasErrors('data.graph');

        Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);
    }

    public function test_the_client_config_describes_the_canvas(): void
    {
        $config = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]))
            ->getCanvasConfig();

        $this->assertFalse($config['readonly']);
        $this->assertTrue($config['live']);
        $this->assertSame('vertical', $config['direction']);
        $this->assertCount(4, $config['nodeTypes']);
        $this->assertArrayHasKey('nodeActions', $config);
        $this->assertArrayHasKey('nodeBodies', $config);

        // The height is a starting point the viewer can drag from, so the
        // client needs both the number and permission to change it.
        $this->assertSame(560, $config['height']);
        $this->assertTrue($config['resizable']);
    }

    public function test_the_minimap_draws_the_connections_as_well_as_the_nodes(): void
    {
        $html = Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])->html();

        // Lines first, blocks second, so each line disappears into the two
        // nodes it joins instead of crossing them.
        $this->assertStringContainsString('fi-circuit-minimap-edges', $html);
        $this->assertStringContainsString('x-html="minimapEdgesMarkup"', $html);
        $this->assertStringContainsString('x-for="node in minimapNodes"', $html);

        $this->assertLessThan(
            strpos($html, 'x-for="node in minimapNodes"'),
            strpos($html, 'fi-circuit-minimap-edges'),
        );
    }

    public function test_the_minimap_frames_what_is_currently_on_screen(): void
    {
        $html = Livewire::test(CanvasComponent::class, ['graph' => $this->graph()])->html();

        $this->assertStringContainsString('fi-circuit-minimap-viewport', $html);
        $this->assertStringContainsString('x-show="minimapViewport"', $html);

        // Last of the three layers, so the frame reads over the blocks it holds.
        $this->assertGreaterThan(
            strpos($html, 'x-for="node in minimapNodes"'),
            strpos($html, 'fi-circuit-minimap-viewport'),
        );
    }

    public function test_the_grip_along_the_bottom_edge_can_be_turned_off(): void
    {
        $page = Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]);

        $page->assertSeeHtml('fi-circuit-resizer');

        $canvas = $this->canvas($page);

        $this->assertTrue($canvas->isResizable());
        $this->assertFalse($canvas->resizable(false)->isResizable());
    }

    public function test_the_flow_direction_is_a_view_choice_the_client_makes(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));

        // The server states the starting direction; flipping it is Alpine's
        // job, so the toolbar carries both buttons and neither round-trips.
        $this->assertSame('vertical', $canvas->getCanvasConfig()['direction']);
    }

    public function test_outgoing_and_incoming_edges_are_readable_by_node(): void
    {
        $canvas = $this->canvas(Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]));

        $this->assertSame(['a1-end'], array_column($canvas->getOutgoingEdges('a1'), 'id'));
        $this->assertSame(['start-a1'], array_column($canvas->getIncomingEdges('a1'), 'id'));
        $this->assertSame([], $canvas->getOutgoingEdges('end'));
    }

    public function test_the_state_it_dehydrates_is_a_normalised_graph(): void
    {
        $page = Livewire::test(CanvasComponent::class, ['graph' => $this->graph()]);
        $state = $page->get('data')['graph'];

        $this->assertSame(
            Graph::fromArray($state)->toArray(),
            $state,
            'state on the wire should already be in the shape Graph produces',
        );
    }
}
