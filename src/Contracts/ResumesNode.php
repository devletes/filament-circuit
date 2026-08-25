<?php

namespace Devletes\Circuit\Contracts;

use Devletes\Circuit\Support\NodeResult;

/**
 * Companion to {@see ExecutesNode} for nodes whose execute() returned
 * {@see NodeResult::waiting()}: the engine calls resume() when the external
 * thing (task, timer, webhook) completes, passing whatever payload the
 * completion signal carried. Return completed() to let the walk continue, or
 * waiting() again to keep the run suspended.
 */
interface ResumesNode
{
    /**
     * @param  array{id: string, type: string, config: array}  $node  the saved node, as stored in the graph
     * @param  mixed  $context  whatever the engine carries per run
     * @param  array<string, mixed>  $payload  data from the completion signal
     */
    public function resume(array $node, mixed $context = null, array $payload = []): NodeResult;
}
