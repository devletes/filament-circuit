<?php

namespace Devletes\Circuit\Tests\Unit;

use Devletes\Circuit\Support\NodeRegistry;
use Devletes\Circuit\Support\NodeType;
use Devletes\Circuit\Tests\Fixtures\Nodes\ApprovalNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\EndNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\StartNode;
use Devletes\Circuit\Tests\TestCase;
use InvalidArgumentException;

class NodeRegistryTest extends TestCase
{
    public function test_it_registers_definitions_by_instance_or_class_string(): void
    {
        $registry = NodeRegistry::make()->register(StartNode::class, new EndNode);

        $this->assertTrue($registry->has('start'));
        $this->assertTrue($registry->has('end'));
        $this->assertFalse($registry->has('approval'));

        $this->assertInstanceOf(StartNode::class, $registry->get('start'));
    }

    public function test_asking_for_an_unregistered_type_says_so(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No node definition registered for type [ghost].');

        NodeRegistry::make()->get('ghost');
    }

    public function test_registering_the_same_type_twice_replaces_it(): void
    {
        $registry = NodeRegistry::make()->register(StartNode::class, StartNode::class);

        $this->assertCount(1, $registry->all());
    }

    public function test_definitions_come_back_ordered_by_sort_then_name(): void
    {
        // Registered out of order on purpose.
        $registry = NodeRegistry::make()->register(EndNode::class, ApprovalNode::class, StartNode::class);

        $this->assertSame(['start', 'approval', 'end'], array_keys($registry->all()));
    }

    public function test_it_discovers_definitions_in_a_folder(): void
    {
        $registry = NodeRegistry::make()->discoverIn(
            __DIR__.'/../Fixtures/Nodes',
            'Devletes\\Circuit\\Tests\\Fixtures\\Nodes',
        );

        $this->assertSame(['start', 'approval', 'notify', 'end'], array_keys($registry->all()));
    }

    public function test_discovering_a_folder_that_is_not_there_changes_nothing(): void
    {
        $registry = NodeRegistry::make()
            ->register(StartNode::class)
            ->discoverIn(__DIR__.'/nowhere', 'Nope');

        $this->assertSame(['start'], array_keys($registry->all()));
    }

    public function test_it_hands_the_canvas_node_types_in_the_same_order(): void
    {
        $types = NodeRegistry::make()
            ->register(EndNode::class, StartNode::class)
            ->nodeTypes();

        $this->assertContainsOnlyInstancesOf(NodeType::class, $types);
        $this->assertSame(['start', 'end'], array_map(fn (NodeType $type): string => $type->getName(), $types));
    }
}
