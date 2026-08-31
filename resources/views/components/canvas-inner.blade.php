@props([
    'config',
    'statePath' => null,
    'height' => 560,
    'readonly' => false,
    'resizable' => false,
])

@php
    $orientable = $config['orientable'] ?? true;
    $zoomable = $config['zoomable'] ?? true;

    // Read-only keeps the tools that only change how the graph is LOOKED at —
    // zoom, fit and the orientation flip, whose re-layout never leaves the
    // browser. Everything else on the bar edits, so a preview has no bar of its
    // own unless at least one of those two is left on.
    $showsToolbar = ! $readonly || $orientable || $zoomable;
@endphp

<div
    wire:ignore
    x-data="circuitCanvas(@js($config), @js($statePath))"
    x-on:keydown.delete.window="onKeydown($event)"
    x-on:keydown.backspace.window="onKeydown($event)"
    x-on:keydown.window="onHistoryKeydown($event)"
    x-on:circuit-update-node.window="updateNodeConfig($event.detail.id, $event.detail.config)"
    x-on:circuit-update-edge.window="updateEdgeConfig($event.detail.id, $event.detail)"
    x-on:circuit-reload.window="reloadFromState($event.detail)"
    class="fi-circuit {{ ($config['direction'] ?? 'vertical') === 'horizontal' ? 'fi-circuit-dir-horizontal' : '' }}"
    :class="{ 'fi-circuit-dir-horizontal': isHorizontal }"
    style="height: {{ $height }}px"
    :style="`height: ${height}px`"
>
    @if ($showsToolbar)
        <div class="fi-circuit-toolbar">
            @unless ($readonly)
            <x-filament::dropdown placement="bottom-start" width="xs">
                <x-slot name="trigger">
                    <x-filament::button icon="heroicon-m-plus" size="sm">
                        {{ __('Add node') }}
                    </x-filament::button>
                </x-slot>

                <x-filament::dropdown.list>
                    <template x-for="type in availableTypes" :key="type.name">
                        <button
                            type="button"
                            class="fi-dropdown-list-item fi-circuit-palette-item"
                            :data-color="type.color"
                            {{-- close() comes from the <x-filament::dropdown>'s own
                                 Alpine data, which this button sits inside. --}}
                            x-on:click="addNode(type.name); close()"
                        >
                            <span class="fi-circuit-palette-icon" x-html="type.iconHtml" x-show="type.iconHtml"></span>

                            <span class="fi-circuit-palette-text">
                                <span class="fi-dropdown-list-item-label" x-text="type.label"></span>
                                <span class="fi-circuit-palette-description" x-text="type.description" x-show="type.description"></span>
                            </span>
                        </button>
                    </template>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
            @endunless

            <div class="fi-circuit-toolbar-spacer"></div>

            @unless ($readonly)
            <span class="fi-circuit-toolbar-status" x-show="errorMessages.length" x-cloak>
                <x-filament::badge color="danger" size="sm">
                    <span x-text="`${errorMessages.length} ${errorMessages.length === 1 ? 'problem' : 'problems'}`"></span>
                </x-filament::badge>
            </span>
            @endunless

            @if ($orientable)
            {{-- Two buttons rather than one with a swapped icon: Filament
                 renders the icon server-side, so the state it shows has to be
                 a choice between two pre-rendered ones. --}}
            <span x-show="! isHorizontal">
                <x-filament::icon-button
                    icon="heroicon-m-arrows-right-left"
                    :label="__('Switch to a left-to-right flow')"
                    size="sm"
                    color="gray"
                    x-on:click="toggleDirection()"
                />
            </span>

            <span x-show="isHorizontal" x-cloak>
                <x-filament::icon-button
                    icon="heroicon-m-arrows-up-down"
                    :label="__('Switch to a top-down flow')"
                    size="sm"
                    color="gray"
                    x-on:click="toggleDirection()"
                />
            </span>

            @endif

            @if (! $readonly && ($config['undoable'] ?? true))
            <x-filament::icon-button
                icon="heroicon-m-arrow-uturn-left"
                :label="__('Undo')"
                size="sm"
                color="gray"
                x-on:click="undo()"
                x-bind:disabled="! canUndo"
                x-bind:class="canUndo ? '' : 'fi-disabled'"
            />

            <x-filament::icon-button
                icon="heroicon-m-arrow-uturn-right"
                :label="__('Redo')"
                size="sm"
                color="gray"
                x-on:click="redo()"
                x-bind:disabled="! canRedo"
                x-bind:class="canRedo ? '' : 'fi-disabled'"
            />
            @endif

            @if ($zoomable)
            <x-filament::icon-button
                icon="heroicon-m-magnifying-glass-plus"
                :label="__('Zoom in')"
                size="sm"
                color="gray"
                x-on:click="zoomBy(1.2)"
            />

            <x-filament::icon-button
                icon="heroicon-m-magnifying-glass-minus"
                :label="__('Zoom out')"
                size="sm"
                color="gray"
                x-on:click="zoomBy(0.8)"
            />

            <x-filament::icon-button
                icon="heroicon-m-viewfinder-circle"
                :label="__('Fit to view')"
                size="sm"
                color="gray"
                x-on:click="fitView()"
            />
            @endif

            @unless ($readonly)
            @if ($config['tidyable'] ?? true)
            <x-filament::icon-button
                icon="heroicon-m-squares-2x2"
                :label="__('Tidy up')"
                size="sm"
                color="gray"
                x-on:click="autoLayout()"
            />
            @endif

            <x-filament::icon-button
                icon="heroicon-m-adjustments-horizontal"
                :label="__('Configure connection')"
                size="sm"
                color="gray"
                x-show="selectedEdgeId && canConfigureEdge(selectedEdgeId)"
                x-cloak
                x-on:click="configureEdge(selectedEdgeId)"
            />

            <x-filament::icon-button
                icon="heroicon-m-trash"
                :label="__('Delete selection')"
                size="sm"
                color="danger"
                x-show="selectedNodeId || selectedEdgeId"
                x-cloak
                x-on:click="removeSelected()"
            />
            @endunless
        </div>
    @endif

    <div
        class="fi-circuit-surface"
        x-ref="canvas"
        role="application"
        :aria-label="`Workflow canvas, ${nodes.length} nodes, ${edges.length} connections`"
        x-on:wheel.prevent="onWheel($event)"
        x-on:pointerdown="onCanvasPointerDown($event)"
        :class="{ 'fi-circuit-surface-panning': panning, 'fi-circuit-surface-connecting': connecting }"
    >
        <div
            class="fi-circuit-grid"
            :style="`background-size: ${gridSize * viewport.zoom}px ${gridSize * viewport.zoom}px; background-position: ${viewport.x}px ${viewport.y}px`"
        ></div>

        {{-- Edges live OUTSIDE the transformed layer: the svg spans the surface
             and carries the viewport transform on an inner <g>, because Chrome
             won't hit-test SVG geometry outside the element's CSS box. Markup,
             not an x-for: a <template> inside <svg> is parsed into the SVG
             namespace and has no .content for Alpine to clone. --}}
        <svg
            class="fi-circuit-edges"
            aria-hidden="true"
            x-html="edgesMarkup"
            x-on:pointerdown="onEdgePointerDown($event)"
            x-on:dblclick="onEdgeDoubleClick($event)"
        ></svg>

        <div class="fi-circuit-layer" :style="`transform: ${transform}`">
            {{-- The edge midpoint, in three variants. All of them live in the
                 transformed layer so they scale with zoom like node chrome, and
                 render before the nodes so a control on a short edge never
                 covers one.

                 The midpoint is where a connection says what it does — and,
                 when there is something to configure, the only place it can say
                 that it *can* be configured. So: a plain pill when there is
                 nothing to open (a read-only entry, or a source type offering
                 neither outcomes nor an edgeSchema), that same pill as a real
                 button when there is, and a small "+" when a configurable
                 connection has no label to show yet. --}}
            <template x-for="edge in staticEdgeLabels" :key="edge.id">
                <span
                    class="fi-circuit-edge-label"
                    :class="{ 'fi-circuit-edge-label-selected': selectedEdgeId === edge.id }"
                    :style="`transform: translate(${edgeMidpoint(edge).x}px, ${edgeMidpoint(edge).y}px) translate(-50%, -50%)`"
                    aria-hidden="true"
                    x-text="edgeLabelOf(edge)"
                    x-on:pointerdown.stop="selectEdge(edge.id)"
                    x-on:dblclick.stop="configureEdge(edge.id)"
                ></span>
            </template>

            {{-- Pointer events are stopped so a click on the control never
                 starts a pan and never reaches the edge delegation underneath;
                 keydown is stopped so the window-level Delete/Backspace binding
                 can't fire while the control has focus. The control
                 deliberately does NOT select the edge — being a real button it
                 opens the slide-over immediately, and leaving the edge selected
                 would arm Delete on a connection the user only meant to
                 configure. --}}
            <template x-for="edge in configurableEdgeLabels" :key="edge.id">
                <button
                    type="button"
                    class="fi-circuit-edge-label fi-circuit-edge-label-action"
                    :class="{
                        'fi-circuit-edge-label-selected': selectedEdgeId === edge.id,
                        'fi-circuit-edge-busy': pendingEdgeId === edge.id,
                    }"
                    :style="`transform: translate(${edgeMidpoint(edge).x}px, ${edgeMidpoint(edge).y}px) translate(-50%, -50%)`"
                    :title="@js(__('Configure connection')) + ': ' + edgeLabelOf(edge)"
                    :aria-label="@js(__('Configure connection')) + ': ' + edgeLabelOf(edge)"
                    :disabled="pendingEdgeId === edge.id"
                    x-on:pointerdown.stop
                    x-on:keydown.stop
                    x-on:dblclick.stop
                    x-on:click.stop="onEdgeControlClick($event, edge.id)"
                >
                    <span x-text="edgeLabelOf(edge)" x-show="! isEdgeLoading(edge.id)"></span>

                    <span x-show="isEdgeLoading(edge.id)" x-cloak>
                        <x-filament::loading-indicator />
                    </span>
                </button>
            </template>

            <template x-for="edge in bareConfigurableEdges" :key="edge.id">
                <button
                    type="button"
                    class="fi-circuit-edge-add"
                    :class="{
                        'fi-circuit-edge-add-selected': selectedEdgeId === edge.id,
                        'fi-circuit-edge-busy': pendingEdgeId === edge.id,
                    }"
                    :style="`transform: translate(${edgeMidpoint(edge).x}px, ${edgeMidpoint(edge).y}px) translate(-50%, -50%)`"
                    title="{{ __('Configure connection') }}"
                    aria-label="{{ __('Configure connection') }}"
                    :disabled="pendingEdgeId === edge.id"
                    x-on:pointerdown.stop
                    x-on:keydown.stop
                    x-on:dblclick.stop
                    x-on:click.stop="onEdgeControlClick($event, edge.id)"
                >
                    <span class="fi-circuit-edge-add-glyph" aria-hidden="true" x-show="! isEdgeLoading(edge.id)">+</span>

                    <span x-show="isEdgeLoading(edge.id)" x-cloak>
                        <x-filament::loading-indicator />
                    </span>
                </button>
            </template>

            <template x-for="node in nodes" :key="node.id">
                <div
                    class="fi-circuit-node"
                    :data-node-id="node.id"
                    :data-color="nodeColor(node)"
                    :class="{
                        'fi-circuit-node-selected': selectedNodeId === node.id,
                        'fi-circuit-node-droppable': isConnectable(node.id),
                        'fi-circuit-node-invalid': hasError(node.id),
                    }"
                    :style="`transform: translate(${node.position.x}px, ${node.position.y}px)`"
                    :tabindex="readonly ? -1 : 0"
                    role="button"
                    :aria-label="ariaLabel(node)"
                    :aria-invalid="hasError(node.id) ? 'true' : 'false'"
                    :aria-describedby="hasError(node.id) ? `${node.id}-error` : null"
                    x-on:pointerdown="onNodePointerDown($event, node.id)"
                    x-on:dblclick="editNode(node.id)"
                    x-on:keydown="onNodeKeydown($event, node.id)"
                    x-on:focus="selectedNodeId = node.id"
                >
                    <span
                        class="fi-circuit-handle fi-circuit-handle-target"
                        :class="{ 'fi-circuit-handle-hot': isDropTarget(node.id) }"
                        x-show="! typeOf(node).initial"
                    ></span>

                    <div class="fi-circuit-node-body">
                        <span class="fi-circuit-node-icon" x-html="typeOf(node).iconHtml" x-show="typeOf(node).iconHtml"></span>

                        <span class="fi-circuit-node-text">
                            <span class="fi-circuit-node-label" x-text="nodeLabel(node)"></span>

                            {{-- The one-line summary is the fallback: a type
                                 that declares an ->infolist() says the same
                                 thing below, at more length. --}}
                            <span
                                class="fi-circuit-node-summary"
                                x-text="summaryOf(node)"
                                x-show="summaryOf(node) && ! nodeBodiesHtml[node.id]"
                            ></span>
                        </span>
                    </div>

                    {{-- The node type's own infolist, rendered server-side from
                         the node's live config — see the node-actions comment
                         below for why it arrives as markup rather than as Blade
                         inside this template. Pointer events are deliberately
                         NOT stopped: the whole card is a drag surface, and a
                         read-only body that swallowed drags would leave a node
                         with a large dead zone. --}}
                    <div
                        class="fi-circuit-node-infolist"
                        x-show="nodeBodiesHtml[node.id]"
                        x-html="nodeBodiesHtml[node.id]"
                        x-cloak
                    ></div>

                    {{-- Developer-supplied actions for this node, rendered
                         server-side with Filament's own action markup and
                         injected here — x-html runs initTree(), so wire:click,
                         dropdowns and tooltips wire themselves up normally
                         despite the surrounding wire:ignore. Living inside the
                         transformed layer, they scale with zoom like the edge
                         label pills. Every pointer/keyboard event is stopped so
                         a click never starts a drag, opens node config, or
                         changes the selection. --}}
                    <div
                        class="fi-circuit-node-actions"
                        x-show="nodeActionsHtml[node.id]"
                        x-html="nodeActionsHtml[node.id]"
                        x-on:pointerdown.stop
                        x-on:click.stop
                        x-on:dblclick.stop
                        x-on:keydown.stop
                        x-cloak
                    ></div>

                    <span
                        class="fi-circuit-node-error"
                        :id="`${node.id}-error`"
                        x-show="hasError(node.id)"
                        x-text="errorFor(node.id)"
                        x-cloak
                    ></span>

                    <span
                        class="fi-circuit-handle fi-circuit-handle-source"
                        x-show="! typeOf(node).terminal && ! readonly"
                        role="button"
                        aria-label="Drag to connect"
                        x-on:pointerdown="onHandlePointerDown($event, node.id)"
                    ></span>
                </div>
            </template>
        </div>

        <div
            class="fi-circuit-minimap"
            style="--fi-circuit-minimap-w: {{ $config['minimapWidth'] ?? 160 }}px; --fi-circuit-minimap-h: {{ $config['minimapHeight'] ?? 110 }}px"
            x-show="showMinimap && nodes.length"
            aria-hidden="true"
            x-cloak
        >
            {{-- Before the blocks, so the lines run underneath them. --}}
            {{-- No viewBox: one user unit is one pixel, which is what the
                 projection already works in. --}}
            <svg class="fi-circuit-minimap-edges" x-html="minimapEdgesMarkup"></svg>

            <template x-for="node in minimapNodes" :key="node.id">
                <span
                    class="fi-circuit-minimap-node"
                    :data-color="node.color"
                    :style="`left: ${node.left}px; top: ${node.top}px; width: ${node.width}px; height: ${node.height}px`"
                ></span>
            </template>

            {{-- Last, so the frame reads over the blocks it contains. --}}
            <span
                class="fi-circuit-minimap-viewport"
                x-cloak
                x-show="minimapViewport"
                :style="minimapViewport && `left: ${minimapViewport.left}px; top: ${minimapViewport.top}px; width: ${minimapViewport.width}px; height: ${minimapViewport.height}px`"
            ></span>
        </div>

        <div class="fi-circuit-empty" x-show="! nodes.length" x-cloak>
            <x-filament::empty-state
                icon="heroicon-o-share"
                :heading="__('Nothing on the canvas yet')"
                :description="$readonly ? __('No workflow has been built.') : __('Add a node to start building.')"
            />
        </div>
    </div>

    @if ($resizable)
        {{-- The bottom edge, draggable. Focusable and arrow-key operable too,
             because a drag handle that only answers to a mouse is a feature
             half the keyboard users on the page cannot reach. --}}
        <div
            class="fi-circuit-resizer"
            role="separator"
            aria-orientation="horizontal"
            aria-label="{{ __('Drag to resize the canvas') }}"
            tabindex="0"
            x-on:pointerdown="onResizeStart($event)"
            x-on:pointermove="onResizeMove($event)"
            x-on:pointerup="onResizeEnd()"
            x-on:pointercancel="onResizeEnd()"
            x-on:keydown="onResizeKeydown($event)"
        >
            <span class="fi-circuit-resizer-grip" aria-hidden="true"></span>
        </div>
    @endif
</div>
