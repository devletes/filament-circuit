<?php

namespace Devletes\Circuit\Contracts;

use Devletes\Circuit\Support\NodeResult;

/**
 * Optional runtime behaviour for a {@see \Devletes\Circuit\Support\NodeDefinition}.
 *
 * Circuit never calls this — it belongs to whatever engine the consuming
 * application runs over a saved graph. Implementing it on the definition keeps
 * a node's presentation, config schema, and behaviour in one class.
 *
 * Return {@see NodeResult::completed()} to let the walk continue, or
 * {@see NodeResult::waiting()} to suspend the run on something external —
 * pair with {@see ResumesNode} to handle the completion signal.
 */
interface ExecutesNode
{
    /**
     * @param  array{id: string, type: string, config: array}  $node  the saved node, as stored in the graph
     * @param  mixed  $context  whatever the engine carries per run (subject model, run record, …)
     */
    public function execute(array $node, mixed $context = null): NodeResult;
}
