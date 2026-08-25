<?php

namespace Devletes\Circuit\Tests\Unit;

use Devletes\Circuit\Support\NodeResult;
use Devletes\Circuit\Tests\TestCase;

class NodeResultTest extends TestCase
{
    public function test_a_completed_node_lets_the_walk_continue(): void
    {
        $result = NodeResult::completed(['approved_by' => 7]);

        $this->assertTrue($result->isCompleted());
        $this->assertFalse($result->isWaiting());
        $this->assertSame(['approved_by' => 7], $result->output);
        $this->assertNull($result->reference);
    }

    public function test_a_waiting_node_carries_the_reference_that_resumes_it(): void
    {
        $result = NodeResult::waiting('task:42', ['sent_at' => 'now']);

        $this->assertTrue($result->isWaiting());
        $this->assertFalse($result->isCompleted());
        $this->assertSame('task:42', $result->reference);
        $this->assertSame(['sent_at' => 'now'], $result->output);
    }

    public function test_waiting_without_a_reference_is_allowed(): void
    {
        $this->assertNull(NodeResult::waiting()->reference);
    }
}
