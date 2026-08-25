<?php

namespace Devletes\Circuit\Tests\Unit;

use Devletes\Circuit\Support\NodeDefinition;
use Devletes\Circuit\Tests\Fixtures\Nodes\ApprovalNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\EndNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\NotifyNode;
use Devletes\Circuit\Tests\Fixtures\Nodes\StartNode;
use Devletes\Circuit\Tests\TestCase;

class NodeDefinitionTest extends TestCase
{
    public function test_the_type_name_derives_from_the_class_basename(): void
    {
        // The `Node` suffix is dropped and the rest is snake_cased.
        $this->assertSame('start', StartNode::type());
        $this->assertSame('approval', ApprovalNode::type());
        $this->assertSame('field_update', FieldUpdateNode::type());

        // …unless the definition names itself.
        $this->assertSame('custom', RenamedNode::type());
        $this->assertSame('Field Update', (new FieldUpdateNode)->label());
    }

    public function test_presentation_and_topology_carry_over_to_the_node_type(): void
    {
        $start = (new StartNode)->toNodeType();

        $this->assertSame('start', $start->getName());
        $this->assertSame('Start', $start->getLabel());
        $this->assertSame('success', $start->getColor());
        $this->assertSame('Where the flow begins', $start->getDescription());
        $this->assertTrue($start->isInitial());
        $this->assertTrue($start->isSingleton());
        $this->assertSame(0, $start->getMaxIncoming());

        $end = (new EndNode)->toNodeType();

        $this->assertTrue($end->isTerminal());
        $this->assertSame(0, $end->getMaxOutgoing());
    }

    public function test_outcomes_schema_and_summary_carry_over(): void
    {
        $type = (new ApprovalNode)->toNodeType();

        $this->assertSame(['approved' => 'Approved', 'rejected' => 'Rejected'], $type->getOutcomes());
        $this->assertCount(2, $type->getSchema());
        $this->assertSame('Jane', $type->summarise(['approver' => 'Jane']));
    }

    public function test_config_rules_carry_over(): void
    {
        $type = (new ApprovalNode)->toNodeType();

        $this->assertSame(['An approver must be selected.'], $type->validateConfig([]));
        $this->assertSame([], $type->validateConfig(['approver' => 'Jane']));

        // A definition that declares none stays silent rather than erroring.
        $this->assertSame([], (new NotifyNode)->toNodeType()->validateConfig([]));
    }

    public function test_only_a_definition_that_overrides_infolist_advertises_one(): void
    {
        $this->assertTrue((new ApprovalNode)->toNodeType()->hasInfolist());

        // Left on the cheap summary path — nothing is rendered for it at all.
        $this->assertFalse((new NotifyNode)->toNodeType()->hasInfolist());
        $this->assertFalse((new StartNode)->toNodeType()->hasInfolist());
    }
}

/** Exercises the basename-to-type derivation without joining the fixture folder. */
class FieldUpdateNode extends NodeDefinition
{
}

class RenamedNode extends NodeDefinition
{
    public static function type(): string
    {
        return 'custom';
    }
}
