<?php

namespace Devletes\Circuit\Tests\Unit;

use Devletes\Circuit\Support\Graph;
use Devletes\Circuit\Support\NodeType;
use Devletes\Circuit\Tests\TestCase;

class GraphTest extends TestCase
{
    /** @return array<string, NodeType> */
    protected function types(): array
    {
        return collect([
            NodeType::make('start')->initial()->singleton(),
            NodeType::make('approval')
                ->outcomes(['approved' => 'Approved', 'rejected' => 'Rejected'])
                ->validateConfigUsing(fn (array $config): array => blank($config['approver'] ?? null)
                    ? ['An approver must be selected.']
                    : []),
            NodeType::make('end')->terminal(),
        ])->keyBy(fn (NodeType $type): string => $type->getName())->all();
    }

    protected function graph(array $overrides = []): array
    {
        return [
            'nodes' => $overrides['nodes'] ?? [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'a1', 'type' => 'approval', 'position' => ['x' => 0, 'y' => 120], 'config' => ['approver' => 'Jane']],
                ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 240], 'config' => []],
            ],
            'edges' => $overrides['edges'] ?? [
                ['id' => 'e1', 'source' => 'start', 'target' => 'a1'],
                ['id' => 'e2', 'source' => 'a1', 'target' => 'end'],
            ],
        ];
    }

    /** @return array<int, string> */
    protected function messages(array $graph): array
    {
        return Graph::fromArray($graph)->validate($this->types());
    }

    public function test_it_round_trips_through_array_form(): void
    {
        $graph = Graph::fromArray($this->graph());

        $this->assertCount(3, $graph->nodes);
        $this->assertCount(2, $graph->edges);

        $array = $graph->toArray();

        $this->assertSame(['nodes', 'edges', 'viewport'], array_keys($array));
        $this->assertSame('start', $array['nodes'][0]['id']);
    }

    public function test_a_well_formed_graph_has_nothing_to_report(): void
    {
        $this->assertSame([], $this->messages($this->graph()));
    }

    public function test_it_walks_the_graph(): void
    {
        $graph = Graph::fromArray($this->graph());

        $this->assertSame(['a1'], $graph->successors('start'));
        $this->assertSame(['end'], $graph->successors('a1'));
        $this->assertSame([], $graph->successors('end'));
        $this->assertFalse($graph->hasCycle());
    }

    public function test_an_empty_canvas_is_the_only_thing_reported(): void
    {
        $this->assertSame(['The canvas is empty.'], $this->messages(['nodes' => [], 'edges' => []]));
    }

    public function test_it_reports_a_missing_entry_or_exit(): void
    {
        $graph = $this->graph();
        $graph['nodes'] = array_values(array_filter(
            $graph['nodes'],
            fn (array $node): bool => $node['id'] !== 'start',
        ));
        $graph['edges'] = [['id' => 'e2', 'source' => 'a1', 'target' => 'end']];

        $this->assertContains('The graph needs a Start node.', $this->messages($graph));

        $graph = $this->graph();
        $graph['nodes'] = array_values(array_filter(
            $graph['nodes'],
            fn (array $node): bool => $node['id'] !== 'end',
        ));
        $graph['edges'] = [['id' => 'e1', 'source' => 'start', 'target' => 'a1']];

        $this->assertContains('The graph needs at least one End node.', $this->messages($graph));
    }

    public function test_it_blames_every_duplicate_of_a_singleton_but_the_first(): void
    {
        $graph = $this->graph();
        $graph['nodes'][] = ['id' => 'start2', 'type' => 'start', 'position' => ['x' => 200, 'y' => 0], 'config' => []];
        $graph['edges'][] = ['id' => 'e3', 'source' => 'start2', 'target' => 'a1'];

        $problems = Graph::fromArray($graph)->problems($this->types());
        $blamed = array_column(
            array_filter($problems, fn (array $p): bool => str_contains($p['message'], 'Only one')),
            'node',
        );

        $this->assertSame(['start2'], $blamed);
    }

    public function test_a_structural_problem_drops_the_node_label_for_the_card(): void
    {
        // A list of problems has to say which node it means; the card is
        // already sitting under that node's heading, so saying it again there
        // just reads the heading back.
        $graph = $this->graph();
        $graph['edges'] = [['id' => 'e1', 'source' => 'start', 'target' => 'a1']];

        $problem = collect(Graph::fromArray($graph)->problems($this->types()))
            ->firstWhere('message', 'Approval has no outgoing connection.');

        $this->assertSame('Node has no outgoing connection.', $problem['detail']);
    }

    public function test_no_problem_blamed_on_a_node_repeats_its_label_on_the_card(): void
    {
        // Guards the rule for rules added later, rather than the one message
        // that prompted it: a second Start trips the singleton check, and a
        // stray unconfigured Approval trips orphan, dead-end and config at
        // once.
        $graph = $this->graph();
        $graph['nodes'][] = ['id' => 'start2', 'type' => 'start', 'position' => ['x' => 200, 'y' => 0], 'config' => []];
        $graph['nodes'][] = ['id' => 'stray', 'type' => 'approval', 'position' => ['x' => 400, 'y' => 0], 'config' => []];

        $labels = ['start' => 'Start', 'start2' => 'Start', 'a1' => 'Approval', 'stray' => 'Approval', 'end' => 'End'];

        $blamed = array_filter(
            Graph::fromArray($graph)->problems($this->types()),
            fn (array $problem): bool => $problem['node'] !== null,
        );

        $this->assertNotEmpty($blamed);

        foreach ($blamed as $problem) {
            $this->assertStringNotContainsString(
                $labels[$problem['node']],
                $problem['detail'],
                "Card text for [{$problem['node']}] repeats the node's own label: {$problem['detail']}",
            );
        }
    }

    public function test_it_reports_cycles_orphans_and_dead_ends(): void
    {
        $cyclic = $this->graph();
        $cyclic['edges'][] = ['id' => 'e3', 'source' => 'end', 'target' => 'start'];

        $this->assertContains('The connections form a loop.', $this->messages($cyclic));

        $orphaned = $this->graph();
        $orphaned['nodes'][] = ['id' => 'stray', 'type' => 'approval', 'position' => ['x' => 400, 'y' => 0], 'config' => ['approver' => 'Sam']];
        $orphaned['edges'][] = ['id' => 'e3', 'source' => 'stray', 'target' => 'end'];

        $this->assertContains('Approval is not connected to the flow.', $this->messages($orphaned));

        $dangling = $this->graph();
        $dangling['edges'] = [['id' => 'e1', 'source' => 'start', 'target' => 'a1']];

        $this->assertContains('Approval has no outgoing connection.', $this->messages($dangling));
    }

    public function test_it_reports_a_connection_to_a_node_that_no_longer_exists(): void
    {
        $graph = $this->graph();
        $graph['edges'][] = ['id' => 'e3', 'source' => 'a1', 'target' => 'ghost'];

        $this->assertContains(
            'A connection points at a node that no longer exists (ghost).',
            $this->messages($graph),
        );
    }

    public function test_it_reports_an_unknown_node_type(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['type'] = 'wormhole';

        $this->assertContains('Unknown node type [wormhole].', $this->messages($graph));
    }

    public function test_a_terminal_type_may_end_the_flow_but_others_may_not(): void
    {
        $graph = $this->graph();

        // End has no outgoing connection and that is exactly the point.
        $this->assertNotContains('End has no outgoing connection.', $this->messages($graph));
    }

    public function test_it_reports_config_problems_against_the_node_that_owns_them(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];

        $problems = Graph::fromArray($graph)->problems($this->types());
        $config = array_values(array_filter(
            $problems,
            fn (array $p): bool => str_contains($p['message'], 'approver'),
        ));

        $this->assertCount(1, $config);
        $this->assertSame('a1', $config[0]['node']);

        // Listed with the node named, shown on the node without it.
        $this->assertSame('Approval: An approver must be selected.', $config[0]['message']);
        $this->assertSame('An approver must be selected.', $config[0]['detail']);
    }

    public function test_each_half_of_validation_can_be_switched_off(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];
        $graph['edges'] = [];

        $structural = 'Start has no outgoing connection.';
        $config = 'Approval: An approver must be selected.';

        $both = Graph::fromArray($graph)->validate($this->types());
        $this->assertContains($structural, $both);
        $this->assertContains($config, $both);

        $configOnly = Graph::fromArray($graph)->validate($this->types(), topology: false);
        $this->assertSame([$config], $configOnly);

        $topologyOnly = Graph::fromArray($graph)->validate($this->types(), config: false);
        $this->assertContains($structural, $topologyOnly);
        $this->assertNotContains($config, $topologyOnly);

        $this->assertSame([], Graph::fromArray($graph)->validate($this->types(), topology: false, config: false));
    }

    public function test_an_outcome_must_exist_on_its_source_type_and_may_be_claimed_once(): void
    {
        $unknown = $this->graph();
        $unknown['edges'][1]['outcome'] = 'maybe';

        $this->assertNotSame([], array_filter(
            $this->messages($unknown),
            fn (string $message): bool => str_contains($message, 'maybe'),
        ));

        $duplicate = $this->graph();
        $duplicate['edges'][1]['outcome'] = 'approved';
        $duplicate['nodes'][] = ['id' => 'end2', 'type' => 'end', 'position' => ['x' => 200, 'y' => 240], 'config' => []];
        $duplicate['edges'][] = ['id' => 'e3', 'source' => 'a1', 'target' => 'end2', 'outcome' => 'approved'];

        $this->assertNotSame([], array_filter(
            $this->messages($duplicate),
            fn (string $message): bool => str_contains($message, 'Approved'),
        ));
    }

    public function test_validate_flattens_and_deduplicates_problems(): void
    {
        $graph = $this->graph();
        $graph['nodes'][1]['config'] = [];
        $graph['nodes'][] = ['id' => 'a2', 'type' => 'approval', 'position' => ['x' => 0, 'y' => 180], 'config' => []];
        $graph['edges'][] = ['id' => 'e3', 'source' => 'a1', 'target' => 'a2'];
        $graph['edges'][] = ['id' => 'e4', 'source' => 'a2', 'target' => 'end'];

        $messages = $this->messages($graph);

        // Two nodes, the same complaint, one message.
        $this->assertSame(
            1,
            count(array_filter($messages, fn (string $m): bool => $m === 'Approval: An approver must be selected.')),
        );
    }
}
