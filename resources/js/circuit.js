/**
 * Circuit — a node canvas for Filament. (rev 30)
 *
 * Interaction state lives entirely in Alpine; Livewire is written to only at
 * commit points (drag end, connect, delete, config change), never during a
 * pointer move. The canvas element carries `wire:ignore` so Livewire never
 * re-renders the DOM underneath us — which is also why the server has to tell
 * it to re-read state after a node-config action (see `reloadFromState`).
 */

const NODE_WIDTH = 220
const DEFAULT_NODE_HEIGHT = 72
// What the minimap's frame spends on its border and on the inset its contents
// sit within — subtracted from the configured size to get the box the graph is
// projected into. Blocks, connection lines and the viewport frame all use that
// box, so the three agree on where a node is.
const MINIMAP_CHROME = (1 + 10) * 2
// How much of the graph has to stay on screen. Pan until only this much of it
// is left and the canvas stops going — enough to see where you came from, and
// still leaving most of the surface empty to work in.
const PAN_MARGIN = 96
const MIN_ZOOM = 0.25
const MAX_ZOOM = 2
// Clear space the layout leaves between neighbours, per axis. Node extents
// are measured and added on top: a type with an ->infolist() body is taller
// than DEFAULT_NODE_HEIGHT, and a pitch with that height baked in would drop
// the next level on top of it.
const LAYOUT_X_GAP = 60
const LAYOUT_Y_GAP = 58
const NUDGE = 16
// Clear space a newly placed node keeps from the ones already there, and how
// far each attempt moves when the spot it wanted is taken.
const NODE_GAP = 24
const PLACEMENT_STEP = 64
// How long a round trip has to be in flight before its control swaps its icon
// for a spinner. Matches Filament's own `wire:loading.delay.default`, so a
// modal that arrives quickly never flashes one.
const LOADING_DELAY = 200
const EDGE_CORNER_RADIUS = 12
// Handle radius (6) plus a hair of breathing room for the arrowhead.
const ARROW_GAP = 8
// How far one arrow key moves the canvas's bottom edge. The band it may move
// within, and how deep undo goes, come from config/circuit.php.
const RESIZE_STEP = 32
// How far a connection runs straight out of a handle before it may turn.
const EDGE_STUB = 22
// Breathing room around a card when testing whether a route would cross it.
const EDGE_NODE_PADDING = 8
// How far a detouring connection clears the cards it routes around, and how
// far apart two detours down the same side sit.
const EDGE_LANE_CLEARANCE = 28
const EDGE_LANE_STEP = 32

// Which connections currently detour, memoised across one render pass so lane
// ranking does not re-test every edge against every card once per edge. Kept
// at module scope on purpose: it is a derived cache, and writing it inside
// Alpine's reactive object from a getter would schedule a needless re-render.
let blockedMemo = { key: null, ids: [] }

const clamp = (value, min, max) => Math.min(Math.max(value, min), max)

const uid = (prefix) => `${prefix}_${Math.random().toString(36).slice(2, 9)}`

document.addEventListener('alpine:init', () => {
    window.Alpine.data('circuitCanvas', (config = {}, statePath = null) => ({
        nodeTypes: config.nodeTypes ?? [],
        snapToGrid: config.snapToGrid ?? true,
        gridSize: config.gridSize ?? 16,
        showMinimap: config.showMinimap ?? true,
        direction: config.direction ?? 'vertical',
        readonly: config.readonly ?? false,
        componentKey: config.componentKey ?? null,
        hasEdgeSchema: config.hasEdgeSchema ?? false,
        live: config.live ?? false,
        problems: config.problems ?? {},
        errorMessages: config.messages ?? [],

        // node id => rendered action-bar markup, built server-side from the
        // app's ->nodeActions() and injected with x-html on the node card.
        nodeActionsHtml: config.nodeActions ?? {},

        // node id => rendered infolist markup, built server-side from the node
        // type's ->infolist() and injected with x-html inside the node card.
        nodeBodiesHtml: config.nodeBodies ?? {},

        statePath,

        // Presentation, not graph: which way the flow reads and how tall the
        // box is. Both are the viewer's to change and neither touches what is
        // saved, so they are remembered client-side per field.
        height: config.height ?? 560,
        minHeight: config.minHeight ?? 240,
        maxHeight: config.maxHeight ?? 2400,
        historyLimit: config.historyLimit ?? 50,

        // Which affordances this canvas offers. Each hides a control AND gates
        // the behaviour behind it, so a disabled tool cannot be reached by
        // shortcut either.
        resizable: config.resizable ?? false,
        orientable: config.orientable ?? false,
        undoable: config.undoable ?? false,
        tidyable: config.tidyable ?? false,
        zoomable: config.zoomable ?? false,

        // Serialised graph states, oldest first, with historyIndex pointing at
        // the one on screen. Undo walks the pointer back rather than popping,
        // so redo is just walking it forward again — until the next edit,
        // which truncates whatever was in front of it.
        history: [],
        historyIndex: -1,

        // Set while restoreHistory() is applying a state, so the commit it makes
        // is not mistaken for a new edit.
        _restoringHistory: false,

        // The edge whose config modal is being fetched, and whether it has
        // been in flight long enough to say so. Node actions get both for free
        // from `wire:loading` — but that keys off the request, not the control,
        // and every edge control is on screen at once, so ours are told apart
        // by id here instead.
        pendingEdgeId: null,
        showingEdgeSpinner: false,
        _edgeSpinnerTimer: null,

        minimapWidth: config.minimapWidth ?? 160,
        minimapHeight: config.minimapHeight ?? 110,

        // The visible area of the surface, in CSS pixels. Kept in state
        // because the minimap's viewport rectangle is derived from it and a
        // bounding rect is not something Alpine can react to.
        surface: { w: 0, h: 0 },

        nodes: [],
        edges: [],
        viewport: { x: 0, y: 0, zoom: 1 },

        sizes: {},
        selectedNodeId: null,
        selectedEdgeId: null,

        dragging: null,
        panning: null,
        connecting: null,

        // ── lifecycle ────────────────────────────────────────────────────────

        init() {
            this.restorePreferences()

            const initial = this.readState()

            this.hydrate(initial)

            // Rendered server-side for this graph, so the two already agree.
            this._syncedSignature = this.graphSignature()

            if (this.nodes.length && this.nodes.every((node) => !node.position)) {
                // Its commit seeds history with the laid-out graph — undoing
                // back to a pile of nodes stacked at the origin helps nobody.
                this.autoLayout()
            } else {
                this.pushHistory()
            }

            // A canvas born inside a modal, a collapsed section or a hidden tab
            // measures 0×0, and fitting against that would poison the viewport
            // with garbage — so fitView() declines, and the opening fit stays
            // an intent rather than a single attempt: every resize gets another
            // go until one actually lands.
            this._fitted = false
            this._viewportTouched = false

            this.$nextTick(() => this.fitOnOpen())

            this._resizeObserver = new ResizeObserver((entries) => {
                const rect = entries[0]?.contentRect

                if (!rect || rect.width === 0) {
                    return
                }

                // Every resize, not just the first: the minimap's viewport
                // rectangle is only right while this is current.
                this.measureSurface()

                if (this._fitted) {
                    return
                }

                this.$nextTick(() => this.fitOnOpen())
            })

            this._resizeObserver.observe(this.$refs.canvas)

            // Cards resize themselves (error text showing/hiding); re-measure
            // so the edges stay pinned to their handles.
            this._nodeResizeObserver = new ResizeObserver(() => {
                cancelAnimationFrame(this._measureFrame)
                this._measureFrame = requestAnimationFrame(() => this.measure())
            })

            this.onPointerMove = this.onPointerMove.bind(this)
            this.onPointerUp = this.onPointerUp.bind(this)

            window.addEventListener('pointermove', this.onPointerMove)
            window.addEventListener('pointerup', this.onPointerUp)

            this.$watch('nodes', () => this.$nextTick(() => this.measure()))
            this.$watch('problems', () => this.$nextTick(() => this.measure()))
        },

        destroy() {
            window.removeEventListener('pointermove', this.onPointerMove)
            window.removeEventListener('pointerup', this.onPointerUp)
            this._resizeObserver?.disconnect()
            this._nodeResizeObserver?.disconnect()
            cancelAnimationFrame(this._measureFrame)
            clearTimeout(this._commitTimer)
        },

        /**
         * The server-side Graph value object normalises a missing viewport to
         * the identity, so "exactly the default" is the only reliable signal
         * that no viewport was ever saved or touched.
         */
        hasDefaultViewport() {
            const v = this.viewport

            return !v || (v.x === 0 && v.y === 0 && v.zoom === 1)
        },

        /**
         * Whether the canvas may still centre itself on the graph.
         *
         * An editor only does so when no viewport was ever saved: a saved pan
         * is the author's, and stomping it every time the field re-opens would
         * be worse than not fitting at all. A read-only canvas has no such
         * claim — the viewport it was handed belongs to whoever last edited the
         * graph, laid out against a box of a different size, so a preview
         * fits every time it opens. Either way, once the *viewer* has moved the
         * view, nothing re-fits underneath them.
         */
        shouldAutoFit() {
            if (this._viewportTouched) {
                return false
            }

            return this.readonly || this.hasDefaultViewport()
        },

        /**
         * Measure, then centre — the pair every "the canvas just became
         * visible" path needs, and the one place that records whether the
         * opening fit is finally done with.
         *
         * @returns {boolean} whether the canvas may stop trying to fit.
         */
        fitOnOpen() {
            this.measure()

            this._fitted = this.shouldAutoFit() ? this.fitView() : true

            return this._fitted
        },

        hydrate(graph) {
            // Deep-clone: $wire.get returns Livewire's own data objects, and
            // rendering those directly would let a later response reconciliation
            // mutate the canvas state from under us (see graphSnapshot()).
            const base = JSON.parse(JSON.stringify({
                nodes: graph.nodes ?? [],
                edges: graph.edges ?? [],
                viewport: graph.viewport ?? this.viewport ?? { x: 0, y: 0, zoom: 1 },
            }))

            this.nodes = base.nodes.map((node) => ({
                config: {},
                position: { x: 0, y: 0 },
                ...node,
            }))
            this.edges = base.edges
            this.viewport = base.viewport
        },

        // ── state bridge ─────────────────────────────────────────────────────

        readState() {
            // Read-only renders hand the graph over in the config — there is no
            // Livewire state path to read from.
            if (config.graph) {
                return config.graph
            }

            if (!this.statePath || !this.$wire) {
                return {}
            }

            return this.$wire.get(this.statePath) ?? {}
        },

        /**
         * A deep clone of the graph for handing to Livewire. Passing the live
         * Alpine objects by reference lets Livewire's data store alias them —
         * and when an in-flight round-trip resolves, its reconciliation writes
         * the STALE server values back into the very objects the canvas
         * renders, snapping nodes back to their last committed position.
         */
        /**
         * Everything the server's answer depends on — and nothing else.
         *
         * `refreshProblems` comes back with three things: validation problems,
         * the per-node action bars, and the node infolist bodies. All three are
         * functions of which nodes exist, what type they are, how they are
         * configured and how they are connected. None of them depends on where
         * a node sits. Positions are excluded so that moving one can be
         * recognised as a change the server has nothing new to say about.
         */
        graphSignature() {
            return JSON.stringify({
                nodes: this.nodes.map((node) => [node.id, node.type, node.config, node.summary]),
                edges: this.edges.map((edge) => [edge.id, edge.source, edge.target, edge.outcome, edge.condition]),
            })
        },

        graphSnapshot() {
            return JSON.parse(JSON.stringify({
                nodes: this.nodes,
                edges: this.edges,
                viewport: this.viewport,
            }))
        },

        /**
         * Push to Livewire. The graph is always STAGED immediately (deferred,
         * no request) so a save mid-debounce still carries the latest state.
         *
         * Viewport-only commits (pan, zoom, fit) stop at staging — zero
         * requests; the viewport rides along with the next real one. Graph
         * commits schedule ONE debounced, serialized round-trip that both
         * syncs the staged state (so afterStateUpdated fires on live fields —
         * Livewire sends queued updates with any request) and re-validates
         * topology in the same trip.
         */
        commit(graphChanged = true) {
            // Every graph mutation funnels through here, so this is the one
            // place history has to be recorded — and it is recorded before the
            // Livewire guard below, so a bare Alpine mount is undoable too.
            if (graphChanged && !this._restoringHistory) {
                this.pushHistory()
            }

            if (this.readonly || !this.statePath || !this.$wire) {
                return
            }

            this.$wire.set(this.statePath, this.graphSnapshot(), false)

            if (!graphChanged) {
                return
            }

            this._dirty = true
            clearTimeout(this._commitTimer)
            this._commitTimer = setTimeout(() => this.flushCommit(), 400)
        },

        async flushCommit() {
            if (this._committing || !this._dirty) {
                return
            }

            this._dirty = false
            this._committing = true

            const signature = this.graphSignature()

            // Dragging a node, tidying up, flipping the direction — all of them
            // move nodes and change nothing else, so problems, action bars and
            // bodies would all come back byte-identical. The new coordinates
            // are already staged on $wire and ride along with the next real
            // request, so nothing is lost by not asking.
            //
            // A live() field is the exception: it asked for afterStateUpdated
            // to fire promptly, and that only happens on a request.
            if (signature === this._syncedSignature && this.live !== true) {
                this._committing = false

                return
            }

            const seq = (this._flushSeq = (this._flushSeq ?? 0) + 1)

            try {
                if (this.componentKey && this.$wire?.callSchemaComponentMethod) {
                    // One request: the graph travels as an explicit argument
                    // (staged-update apply order is not guaranteed relative to
                    // exposed method calls), and the server validates it.
                    const problems = await this.$wire.callSchemaComponentMethod(this.componentKey, 'refreshProblems', [this.graphSnapshot()])

                    // A slow response must not overwrite a newer flush's result.
                    if (problems && seq === this._flushSeq) {
                        this._syncedSignature = signature
                        this.applyProblems(problems)
                        this.applyNodeActions(problems.actions)
                        this.applyNodeBodies(problems.bodies)
                    }
                } else if (this.live === true) {
                    // No Filament schema host — plain live sync.
                    await Promise.resolve(
                        this.$wire.set(this.statePath, this.graphSnapshot(), true),
                    )
                }
            } finally {
                this._committing = false

                // Edits made while the request was in flight — send them now.
                if (this._dirty) {
                    this.flushCommit()
                }
            }
        },

        /**
         * Called after the server mutates state behind the wire:ignore. The
         * detail may carry freshly rendered node actions — a label or a
         * visible() can depend on the config that just changed.
         */
        async reloadFromState(detail) {
            const statePath = typeof detail === 'string' ? detail : detail?.statePath

            if (statePath && statePath !== this.statePath) {
                return
            }

            if (!this.statePath || !this.$wire) {
                return
            }

            const fresh = await this.$wire.get(this.statePath)

            if (fresh) {
                this.hydrate(fresh)

                if (detail?.problems) {
                    // Computed by the server from the state just hydrated, so
                    // the canvas is genuinely up to date and owes no follow-up.
                    this._syncedSignature = this.graphSignature()
                    this.applyProblems(detail.problems)
                } else {
                    /*
                     * Nothing authoritative about validity came back, so the
                     * errors on screen are now of unknown age. Marking the
                     * signature synced here would strand them: flushCommit
                     * short-circuits whenever the signature matches, so no
                     * later edit would ever ask again. Leave it unset and go
                     * and ask.
                     */
                    this._syncedSignature = null
                    this._dirty = true
                    this.flushCommit()
                }

                this.applyNodeActions(detail?.nodeActions)
                this.applyNodeBodies(detail?.nodeBodies)

                // Config written through a modal never passes commit(), so
                // this is where those edits join the undo stack.
                this.pushHistory()

                this.$nextTick(() => this.measure())
            }
        },

        /**
         * Validation from the server, in the shape `refreshProblems` returns.
         *
         * Both paths that can resolve an error route through here: the
         * debounced flush after a graph edit, and the reload after a config
         * modal saves. The modal is the one that matters — picking the user an
         * approval node was missing is precisely how it stops being invalid,
         * and the canvas cannot know that on its own.
         */
        applyProblems(problems) {
            if (!problems) {
                return
            }

            this.problems = problems.nodes ?? {}
            this.errorMessages = problems.messages ?? []
        },

        /**
         * Swap in a new node id => markup map. Guarded on the serialised value
         * because assigning it re-runs every node's x-html — which re-inits the
         * injected Alpine, closing any dropdown that happens to be open.
         */
        applyNodeActions(map) {
            if (!map) {
                return
            }

            if (JSON.stringify(map) === JSON.stringify(this.nodeActionsHtml)) {
                return
            }

            this.nodeActionsHtml = map
        },

        /**
         * Same swap for the node-card infolists. A body changes the card's
         * height, so the edges anchored to it have to be re-measured once the
         * new markup has laid out.
         */
        applyNodeBodies(map) {
            if (!map) {
                return
            }

            if (JSON.stringify(map) === JSON.stringify(this.nodeBodiesHtml)) {
                return
            }

            this.nodeBodiesHtml = map
            this.$nextTick(() => this.measure())
        },

        // ── node config ──────────────────────────────────────────────────────

        /**
         * Mount this component's own Filament action, so node config is edited
         * in a real slide-over built from the node type's schema.
         */
        /**
         * Whether a node has anything to configure. A structural type — start,
         * end — declares no fields of its own and stays inert: double-clicking
         * it does nothing rather than opening an empty modal. A schema suffix
         * adds to a node's modal, it is not a reason to open one.
         */
        canConfigureNode(id) {
            const node = this.nodeById(id)

            return node ? Boolean(this.typeOf(node)?.configurable) : false
        },

        editNode(id) {
            if (this.readonly || !this.canConfigureNode(id)) {
                return
            }

            const node = this.nodeById(id)

            if (!node) {
                return
            }

            if (this.componentKey && this.$wire?.mountAction) {
                this.$wire.mountAction('editNode', { nodeId: id }, { schemaComponent: this.componentKey })

                return
            }

            // No Filament host (e.g. a bare Alpine mount) — fall back to the event.
            this.$dispatch('circuit-node-edit', { id: node.id, type: node.type, config: node.config })
        },

        updateNodeConfig(id, config) {
            const node = this.nodeById(id)

            if (!node) {
                return
            }

            node.config = config
            this.commit()
        },

        // ── edge config ──────────────────────────────────────────────────────

        /**
         * Whether the slide-over has anything to offer for this edge: an
         * outcome Select (source type declares outcomes) or app-contributed
         * condition components. With neither, configuring is a silent no-op —
         * the edge still selects and deletes normally.
         */
        canConfigureEdge(id) {
            const edge = this.edgeById(id)

            if (!edge) {
                return false
            }

            const sourceType = this.typeOf(this.nodeById(edge.source))

            // An initial node is where the flow begins, not a decision it
            // makes — there is nothing to gate on the way out of it. A terminal
            // node is the same story at the other end: reaching it IS the flow
            // finishing, so there is nothing to gate on the way in.
            if (sourceType?.initial) {
                return false
            }

            if (this.typeOf(this.nodeById(edge.target))?.terminal) {
                return false
            }

            return this.hasEdgeSchema
                || Object.keys(sourceType?.outcomes ?? {}).length > 0
        },

        /** Whether this edge's control should be showing a spinner right now. */
        isEdgeLoading(id) {
            return this.pendingEdgeId === id && this.showingEdgeSpinner
        },

        /** Same machinery as editNode, keyed by edge id. */
        configureEdge(id) {
            if (this.readonly || !this.canConfigureEdge(id)) {
                return
            }

            const edge = this.edgeById(id)

            if (this.componentKey && this.$wire?.mountAction) {
                // Disabled at once so the control cannot be clicked twice, but
                // the icon only swaps once the request has outlived the delay —
                // the order Filament's own buttons do it in.
                this.pendingEdgeId = id
                clearTimeout(this._edgeSpinnerTimer)
                this._edgeSpinnerTimer = setTimeout(() => {
                    this.showingEdgeSpinner = true
                }, LOADING_DELAY)

                Promise.resolve(
                    this.$wire.mountAction('editEdge', { edgeId: id }, { schemaComponent: this.componentKey }),
                ).finally(() => {
                    // Only clear our own: a second control clicked while this
                    // request was in flight owns the state now.
                    if (this.pendingEdgeId === id) {
                        clearTimeout(this._edgeSpinnerTimer)
                        this.pendingEdgeId = null
                        this.showingEdgeSpinner = false
                    }
                })

                return
            }

            // No Filament host (e.g. a bare Alpine mount) — fall back to the event.
            this.$dispatch('circuit-edge-edit', {
                id: edge.id,
                source: edge.source,
                target: edge.target,
                outcome: edge.outcome ?? null,
                condition: edge.condition ?? null,
            })
        },

        updateEdgeConfig(id, data) {
            const edge = this.edgeById(id)

            if (!edge) {
                return
            }

            edge.outcome = data.outcome ?? null
            edge.condition = data.condition ?? null
            edge.label = data.label ?? null
            this.commit()
        },

        // ── problems ─────────────────────────────────────────────────────────

        hasError(id) {
            return Boolean(this.problems?.[id])
        },

        errorFor(id) {
            return this.problems?.[id] ?? ''
        },

        // ── geometry ─────────────────────────────────────────────────────────

        get canvasRect() {
            return this.$refs.canvas.getBoundingClientRect()
        },

        /** A coordinate rounded to the nearest grid line, or left alone. */
        snapToLine(value) {
            return this.snapToGrid
                ? Math.round(value / this.gridSize) * this.gridSize
                : value
        },

        /**
         * A node's top-left, placed so that its CENTRE lands on the grid.
         *
         * Handles hang off the middle of a card and connections are drawn
         * between handles, so snapping the corner puts every run half a card
         * away from the grid line it should sit on — 110px for a 220px card,
         * which is not a multiple of anything. Snapping the centre costs
         * nothing and puts the lines where the grid says they are. Tidy-up
         * already worked this way; this is drag and add catching up.
         */
        snapPosition(x, y, id) {
            if (!this.snapToGrid) {
                return { x, y }
            }

            const size = this.sizeOf(id)

            return {
                x: this.snapToLine(x + size.w / 2) - size.w / 2,
                y: this.snapToLine(y + size.h / 2) - size.h / 2,
            }
        },

        screenToFlow(clientX, clientY) {
            const rect = this.canvasRect

            return {
                x: (clientX - rect.left - this.viewport.x) / this.viewport.zoom,
                y: (clientY - rect.top - this.viewport.y) / this.viewport.zoom,
            }
        },

        /** @returns {boolean} whether the surface changed size. */
        measureSurface() {
            const rect = this.$refs.canvas?.getBoundingClientRect()

            if (!rect || (rect.width === this.surface.w && rect.height === this.surface.h)) {
                return false
            }

            this.surface = { w: rect.width, h: rect.height }

            return true
        },

        measure() {
            this.measureSurface()

            const sizes = {}

            this._nodeResizeObserver?.disconnect()

            this.nodes.forEach((node) => {
                const el = this.$refs.canvas.querySelector(`[data-node-id="${node.id}"]`)

                sizes[node.id] = el
                    ? { w: el.offsetWidth, h: el.offsetHeight }
                    : { w: NODE_WIDTH, h: DEFAULT_NODE_HEIGHT }

                // A card grows and shrinks on its own — a validation message
                // appearing, a summary wrapping — without the node list ever
                // changing. Watch each card so edge anchors follow its height
                // instead of pointing at where it used to end.
                if (el) {
                    this._nodeResizeObserver?.observe(el)
                }
            })

            // Assigning unconditionally would re-enter through the observer
            // on every measure and spin.
            if (!this.sizesChanged(sizes)) {
                return
            }

            this.sizes = sizes
        },

        /** @param {Record<string, {w: number, h: number}>} next */
        sizesChanged(next) {
            const ids = Object.keys(next)

            if (ids.length !== Object.keys(this.sizes).length) {
                return true
            }

            return ids.some((id) => {
                const current = this.sizes[id]

                return !current || current.w !== next[id].w || current.h !== next[id].h
            })
        },

        sizeOf(id) {
            return this.sizes[id] ?? { w: NODE_WIDTH, h: DEFAULT_NODE_HEIGHT }
        },

        nodeById(id) {
            return this.nodes.find((node) => node.id === id)
        },

        edgeById(id) {
            return this.edges.find((edge) => edge.id === id)
        },

        typeOf(node) {
            return this.nodeTypes.find((type) => type.name === node?.type) ?? {}
        },

        get isHorizontal() {
            return this.direction === 'horizontal'
        },

        anchor(id, end) {
            const node = this.nodeById(id)

            if (!node) {
                return { x: 0, y: 0 }
            }

            const size = this.sizeOf(id)

            if (this.isHorizontal) {
                return {
                    x: end === 'source' ? node.position.x + size.w : node.position.x,
                    y: node.position.y + size.h / 2,
                }
            }

            return {
                x: node.position.x + size.w / 2,
                y: end === 'source' ? node.position.y + size.h : node.position.y,
            }
        },

        /**
         * The corner points a connection turns at, source first, target last.
         *
         * A connection always LEAVES its node along the flow axis and ARRIVES
         * head-on — never doubling back through the card it starts from. When
         * the target sits far enough downstream that is two turns at the
         * halfway line. When it sits level with, or behind, the source (a
         * branch that loops back, or two nodes side by side) the halfway line
         * is behind the source handle, so the route takes a longer way round:
         * a short stub out of the source, across a lane clear of both cards,
         * then a stub into the target — four turns, all of them outward.
         */
        waypoints(from, to, edge = null) {
            const alongKey = this.isHorizontal ? 'x' : 'y'
            const crossKey = this.isHorizontal ? 'y' : 'x'

            const at = (along, cross) => this.isHorizontal
                ? { x: along, y: cross }
                : { x: cross, y: along }

            const fromAlong = from[alongKey]
            const fromCross = from[crossKey]
            const toAlong = to[alongKey]
            const toCross = to[crossKey]

            // A lane clear of whatever the direct route would have crossed.
            // Only skip connections ever need one: 1 to 3 in a 1 -> 2 -> 3
            // chain runs straight down over node 2 and over both of the edges
            // beside it, and the three become one indistinguishable line.
            const detour = edge ? this.detourLane(edge, from) : null

            if (detour !== null) {
                return [
                    at(fromAlong, fromCross),
                    at(fromAlong + EDGE_STUB, fromCross),
                    at(fromAlong + EDGE_STUB, detour),
                    at(toAlong - EDGE_STUB, detour),
                    at(toAlong - EDGE_STUB, toCross),
                    at(toAlong, toCross),
                ]
            }

            // Anywhere downstream: out, across at the halfway line, in. The
            // sideways detour below is for targets that are level with or
            // behind the source; a short gap is still a gap, and bowing a
            // connection out around nothing reads as a fault in the diagram.
            if (toAlong > fromAlong) {
                // On a grid line, so two connections turning at roughly the
                // same place turn at exactly the same place and their runs
                // merge instead of sitting a few pixels apart. Kept between
                // the two ends, or the route would double back to reach it.
                const mid = clamp(
                    this.snapToLine((fromAlong + toAlong) / 2),
                    fromAlong,
                    toAlong,
                )

                return [
                    at(fromAlong, fromCross),
                    at(mid, fromCross),
                    at(mid, toCross),
                    at(toAlong, toCross),
                ]
            }

            // Level or behind: leave and enter on stubs, and cross over on a
            // lane that clears both cards rather than cutting through them.
            const exit = fromAlong + EDGE_STUB
            const entry = toAlong - EDGE_STUB
            const spread = Math.abs(toCross - fromCross)
            const clearance = (this.isHorizontal ? DEFAULT_NODE_HEIGHT : NODE_WIDTH) / 2 + EDGE_STUB

            // Side by side, the gap between the two is already a clear lane;
            // stacked, there is none, so bow out past the wider card edge.
            const lane = this.snapToLine(spread > clearance
                ? (fromCross + toCross) / 2
                : Math.max(fromCross, toCross) + clearance)

            return [
                at(fromAlong, fromCross),
                at(exit, fromCross),
                at(exit, lane),
                at(entry, lane),
                at(entry, toCross),
                at(toAlong, toCross),
            ]
        },

        /** Whether a card placed here would land on top of an existing one. */
        overlapsAnyNode(x, y, size, ignoreId = null) {
            return this.nodes.some((node) => {
                if (node.id === ignoreId) {
                    return false
                }

                const other = this.sizeOf(node.id)

                return x < node.position.x + other.w + NODE_GAP
                    && x + size.w + NODE_GAP > node.position.x
                    && y < node.position.y + other.h + NODE_GAP
                    && y + size.h + NODE_GAP > node.position.y
            })
        },

        /**
         * The requested spot, or the nearest free one to it.
         *
         * Dropping a new node on top of an existing one hides both, and the
         * author has to notice and drag it off before they can even read what
         * they just added. Dragging is left alone — a node has to be allowed
         * to travel across the canvas, and a drag that fights back is worse
         * than an overlap the author chose. This only covers placement, where
         * nothing was chosen.
         */
        freePosition(x, y, size) {
            if (!this.overlapsAnyNode(x, y, size)) {
                return { x, y }
            }

            // Along the flow first — that is where the next node belongs — then
            // sideways, then back against the flow as a last resort.
            const directions = this.isHorizontal
                ? [[1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 0]]
                : [[0, 1], [1, 0], [-1, 0], [1, 1], [-1, 1], [0, -1]]

            for (let ring = 1; ring <= 16; ring++) {
                for (const [dx, dy] of directions) {
                    const candidate = {
                        x: x + dx * ring * PLACEMENT_STEP,
                        y: y + dy * ring * PLACEMENT_STEP,
                    }

                    if (!this.overlapsAnyNode(candidate.x, candidate.y, size)) {
                        return candidate
                    }
                }
            }

            return { x, y }
        },

        /** A node's box on the canvas, padded so lines never graze the card. */
        rectOf(node) {
            const size = this.sizeOf(node.id)

            return {
                left: node.position.x - EDGE_NODE_PADDING,
                top: node.position.y - EDGE_NODE_PADDING,
                right: node.position.x + size.w + EDGE_NODE_PADDING,
                bottom: node.position.y + size.h + EDGE_NODE_PADDING,
            }
        },

        /**
         * Whether an axis-aligned segment touches a box. Both are axis-aligned,
         * so the segment's bounding box overlapping the rect IS the answer --
         * no clipping needed.
         */
        segmentHitsRect(a, b, rect) {
            return Math.min(a.x, b.x) <= rect.right
                && Math.max(a.x, b.x) >= rect.left
                && Math.min(a.y, b.y) <= rect.bottom
                && Math.max(a.y, b.y) >= rect.top
        },

        /** The cards a connection's direct route would be drawn straight over. */
        blockedBy(edge) {
            const direct = this.waypoints(
                this.anchor(edge.source, 'source'),
                this.anchor(edge.target, 'target'),
            )

            return this.nodes.filter((node) => {
                if (node.id === edge.source || node.id === edge.target) {
                    return false
                }

                const rect = this.rectOf(node)

                return direct.some((point, index) => index > 0
                    && this.segmentHitsRect(direct[index - 1], point, rect))
            })
        },

        /**
         * Every connection whose direct route crosses a card, in graph order —
         * the ordering lanes fan out along. Recomputed only when the geometry
         * it depends on changes; panning and zooming leave it alone.
         */
        blockedEdgeIds() {
            const key = JSON.stringify([
                this.statePath,
                this.nodes.map((node) => {
                    const size = this.sizeOf(node.id)

                    return [node.id, node.position.x, node.position.y, size.w, size.h]
                }),
                this.edges.map((edge) => [edge.id, edge.source, edge.target]),
            ])

            if (blockedMemo.key !== key) {
                blockedMemo = {
                    key,
                    ids: this.edges
                        .filter((edge) => this.blockedBy(edge).length)
                        .map((edge) => edge.id),
                }
            }

            return blockedMemo.ids
        },

        /**
         * The cross-axis lane a connection has to bow out to, or null when its
         * direct route is already clear.
         *
         * Anything the direct route crosses is a card the reader cannot see the
         * line pass behind, so the connection is pushed out past that card --
         * to whichever side is nearer -- and one step further for each earlier
         * connection already detouring, so two skips down the same side stay
         * apart instead of stacking on each other.
         */
        detourLane(edge, from) {
            const crossKey = this.isHorizontal ? 'y' : 'x'
            const blocking = this.blockedBy(edge)

            if (!blocking.length) {
                return null
            }

            const rects = blocking.map((node) => this.rectOf(node))
            const near = this.isHorizontal
                ? Math.min(...rects.map((rect) => rect.top))
                : Math.min(...rects.map((rect) => rect.left))
            const far = this.isHorizontal
                ? Math.max(...rects.map((rect) => rect.bottom))
                : Math.max(...rects.map((rect) => rect.right))

            const origin = from[crossKey]
            const forward = far + EDGE_LANE_CLEARANCE
            const backward = near - EDGE_LANE_CLEARANCE

            const offset = EDGE_LANE_STEP * Math.max(0, this.blockedEdgeIds().indexOf(edge.id))

            // Snapped, and stepped by a grid multiple, so stacked detours land
            // on grid lines and still stay a clear step apart from each other.
            return this.snapToLine(Math.abs(forward - origin) <= Math.abs(origin - backward)
                ? forward + offset
                : backward - offset)
        },

        /**
         * Straight runs joined by rounded corners, the way flowchart tools
         * draw. Each corner's radius is clamped to half of the shorter leg it
         * joins, so a tight turn bends proportionally instead of overshooting.
         */
        roundedPath(points) {
            const pts = points.filter((point, index) => index === 0
                || Math.abs(point.x - points[index - 1].x) > 0.01
                || Math.abs(point.y - points[index - 1].y) > 0.01)

            if (pts.length < 2) {
                return ''
            }

            let d = `M ${pts[0].x},${pts[0].y}`

            for (let i = 1; i < pts.length - 1; i++) {
                const previous = pts[i - 1]
                const corner = pts[i]
                const next = pts[i + 1]

                const inLength = Math.hypot(corner.x - previous.x, corner.y - previous.y)
                const outLength = Math.hypot(next.x - corner.x, next.y - corner.y)
                const radius = Math.min(EDGE_CORNER_RADIUS, inLength / 2, outLength / 2)

                const inUnit = { x: (corner.x - previous.x) / inLength, y: (corner.y - previous.y) / inLength }
                const outUnit = { x: (next.x - corner.x) / outLength, y: (next.y - corner.y) / outLength }

                d += ` L ${corner.x - inUnit.x * radius},${corner.y - inUnit.y * radius}`
                d += ` Q ${corner.x},${corner.y} ${corner.x + outUnit.x * radius},${corner.y + outUnit.y * radius}`
            }

            const last = pts[pts.length - 1]

            return `${d} L ${last.x},${last.y}`
        },

        edgePath(from, to, edge = null) {
            return this.roundedPath(this.waypoints(from, to, edge))
        },

        /**
         * The two ends a connection is actually DRAWN between. The target end
         * stops short of its handle so the arrowhead sits in front of the dot
         * rather than over it (the dot is 12px across and the marker's tip
         * lands on the path's end point). Anything anchoring to the line —
         * the label, the configure button — has to measure this same route,
         * or it lands a few pixels past what the eye sees.
         */
        routeEnds(edge) {
            const from = this.anchor(edge.source, 'source')
            const to = this.anchor(edge.target, 'target')

            return {
                from,
                to: this.isHorizontal
                    ? { x: to.x - ARROW_GAP, y: to.y }
                    : { x: to.x, y: to.y - ARROW_GAP },
            }
        },

        pathFor(edge) {
            const { from, to } = this.routeEnds(edge)

            return this.edgePath(from, to, edge)
        },

        get connectingPath() {
            if (!this.connecting) {
                return ''
            }

            return this.edgePath(this.anchor(this.connecting.source, 'source'), this.connecting.cursor)
        },

        /**
         * Edges are built as markup rather than `<template x-for>` because a
         * <template> inside an <svg> is parsed into the SVG namespace — it is
         * not an HTMLTemplateElement, so it has no `.content` for Alpine to
         * clone. Selection is handled by delegation in `onEdgePointerDown`.
         *
         * The svg spans the whole surface with the viewport transform applied
         * to an inner <g> — NOT a 1×1 box with overflow: visible, because
         * Chrome refuses to hit-test SVG geometry outside the element's CSS
         * box, which made edges unclickable.
         */
        get edgesMarkup() {
            const escape = (value) => String(value).replace(/[&<>"]/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
            })[char])

            // userSpaceOnUse, not the default strokeWidth: the head keeps one
            // size whether the line is 1.75 (idle) or 2.5 (selected) wide, so
            // selecting an edge no longer inflates its arrow. refX/refY put
            // the tip exactly on the path's end point and centre the head on
            // the line; the stroke with a round join is what softens the three
            // corners of the triangle.
            const defs =
                '<defs><marker id="fi-circuit-arrow" viewBox="0 0 12 12" refX="9" refY="6"' +
                ' markerUnits="userSpaceOnUse" markerWidth="12" markerHeight="12"' +
                ' orient="auto-start-reverse">' +
                '<path d="M 3.4 3 L 9 6 L 3.4 9 Z" fill="currentColor" stroke="currentColor"' +
                ' stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round" /></marker></defs>'

            const edges = this.edges.map((edge) => {
                const d = escape(this.pathFor(edge))
                const selected = this.selectedEdgeId === edge.id ? ' fi-circuit-edge-selected' : ''

                return `<g class="fi-circuit-edge${selected}" data-edge-id="${escape(edge.id)}">` +
                    `<path class="fi-circuit-edge-hit" d="${d}"></path>` +
                    `<path class="fi-circuit-edge-line" d="${d}" marker-end="url(#fi-circuit-arrow)"></path>` +
                    '</g>'
            }).join('')

            const pending = this.connecting
                ? `<path class="fi-circuit-edge-pending" d="${escape(this.connectingPath)}"></path>`
                : ''

            const { x, y, zoom } = this.viewport

            return `${defs}<g transform="translate(${x} ${y}) scale(${zoom})">${edges}${pending}</g>`
        },

        onEdgePointerDown(event) {
            const target = event.target.closest('[data-edge-id]')

            if (!target) {
                return
            }

            event.stopPropagation()
            this.selectEdge(target.dataset.edgeId)
        },

        onEdgeDoubleClick(event) {
            const target = event.target.closest('[data-edge-id]')

            if (!target) {
                return
            }

            this.configureEdge(target.dataset.edgeId)
        },

        // ── viewport ─────────────────────────────────────────────────────────

        get transform() {
            return `translate(${this.viewport.x}px, ${this.viewport.y}px) scale(${this.viewport.zoom})`
        },

        /**
         * Keep the graph from being panned off the screen entirely.
         *
         * Nothing about the canvas says how far it goes, so panning into empty
         * space has no natural stop — and a graph you can no longer see is a
         * graph you cannot navigate back to. The visible area is required to
         * keep overlapping the graph's bounding box by PAN_MARGIN, which is
         * the same thing as the minimap's viewport frame never quite leaving
         * the blocks it belongs to.
         *
         * The margin is capped at the graph's own size, so a single small node
         * cannot demand more overlap than it has to give.
         */
        clampViewport() {
            if (!this.nodes.length || !this.surface.w) {
                return
            }

            const bounds = this.bounds()
            const { zoom } = this.viewport

            const clampAxis = (value, min, max, surface) => {
                const keep = Math.min(PAN_MARGIN, (max - min) * zoom)

                // Left/top-most the viewport may sit, and right/bottom-most.
                const lower = keep - max * zoom
                const upper = surface - keep - min * zoom

                // A graph wider than the surface makes these cross over; then
                // any value between them is fine and clamping is a no-op.
                return upper < lower ? value : clamp(value, lower, upper)
            }

            this.viewport.x = clampAxis(this.viewport.x, bounds.minX, bounds.maxX, this.surface.w)
            this.viewport.y = clampAxis(this.viewport.y, bounds.minY, bounds.maxY, this.surface.h)
        },

        onWheel(event) {
            if (!this.zoomable) {
                return
            }

            const rect = this.canvasRect
            const mx = event.clientX - rect.left
            const my = event.clientY - rect.top

            const next = clamp(this.viewport.zoom * (1 - event.deltaY * 0.0015), MIN_ZOOM, MAX_ZOOM)
            const ratio = next / this.viewport.zoom

            this.viewport.x = mx - (mx - this.viewport.x) * ratio
            this.viewport.y = my - (my - this.viewport.y) * ratio
            this.viewport.zoom = next
            this.clampViewport()
            this._viewportTouched = true
        },

        zoomBy(factor) {
            if (!this.zoomable) {
                return
            }

            const rect = this.canvasRect
            const mx = rect.width / 2
            const my = rect.height / 2

            const next = clamp(this.viewport.zoom * factor, MIN_ZOOM, MAX_ZOOM)
            const ratio = next / this.viewport.zoom

            this.viewport.x = mx - (mx - this.viewport.x) * ratio
            this.viewport.y = my - (my - this.viewport.y) * ratio
            this.viewport.zoom = next
            this.clampViewport()
            this._viewportTouched = true

            this.commit(false)
        },

        /** @returns {boolean} whether there was anything to fit, and room to fit it in. */
        fitView() {
            if (!this.nodes.length) {
                return true
            }

            const rect = this.canvasRect

            // A hidden canvas (modal, collapsed section, inactive tab) measures
            // 0×0; fitting against that poisons the viewport with garbage.
            // Leave it default — the ResizeObserver refits on first real size.
            if (rect.width === 0 || rect.height === 0) {
                return false
            }

            const bounds = this.bounds()
            const padding = 48

            const zoom = clamp(
                Math.min(
                    (rect.width - padding * 2) / Math.max(1, bounds.maxX - bounds.minX),
                    (rect.height - padding * 2) / Math.max(1, bounds.maxY - bounds.minY),
                ),
                MIN_ZOOM,
                1,
            )

            this.viewport = {
                zoom,
                x: (rect.width - (bounds.maxX - bounds.minX) * zoom) / 2 - bounds.minX * zoom,
                y: (rect.height - (bounds.maxY - bounds.minY) * zoom) / 2 - bounds.minY * zoom,
            }

            this.commit(false)

            return true
        },

        bounds() {
            return this.nodes.reduce((acc, node) => {
                const size = this.sizeOf(node.id)

                return {
                    minX: Math.min(acc.minX, node.position.x),
                    minY: Math.min(acc.minY, node.position.y),
                    maxX: Math.max(acc.maxX, node.position.x + size.w),
                    maxY: Math.max(acc.maxY, node.position.y + size.h),
                }
            }, { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity })
        },

        // ── pointer routing ──────────────────────────────────────────────────

        onCanvasPointerDown(event) {
            if (event.target.closest('[data-node-id]') || event.target.closest('[data-edge-id]')) {
                return
            }

            this.select(null)

            this.panning = {
                originX: event.clientX,
                originY: event.clientY,
                startX: this.viewport.x,
                startY: this.viewport.y,
            }
        },

        onNodePointerDown(event, id) {
            if (event.button !== 0) {
                return
            }

            event.stopPropagation()
            this.select(id)

            if (this.readonly) {
                return
            }

            const node = this.nodeById(id)
            const start = this.screenToFlow(event.clientX, event.clientY)

            this.dragging = {
                id,
                offsetX: start.x - node.position.x,
                offsetY: start.y - node.position.y,
                moved: false,
            }
        },

        onHandlePointerDown(event, id) {
            if (this.readonly) {
                return
            }

            event.stopPropagation()
            event.preventDefault()

            this.connecting = {
                source: id,
                cursor: this.screenToFlow(event.clientX, event.clientY),
            }
        },

        onPointerMove(event) {
            if (this.panning) {
                this.viewport.x = this.panning.startX + (event.clientX - this.panning.originX)
                this.viewport.y = this.panning.startY + (event.clientY - this.panning.originY)
                this.clampViewport()
                this._viewportTouched = true

                return
            }

            if (this.dragging) {
                const point = this.screenToFlow(event.clientX, event.clientY)
                const node = this.nodeById(this.dragging.id)

                node.position = this.snapPosition(
                    point.x - this.dragging.offsetX,
                    point.y - this.dragging.offsetY,
                    node.id,
                )
                this.dragging.moved = true

                return
            }

            if (this.connecting) {
                this.connecting.cursor = this.screenToFlow(event.clientX, event.clientY)
                this.connecting.hover = event.target?.closest?.('[data-node-id]')?.dataset?.nodeId ?? null
            }
        },

        onPointerUp(event) {
            if (this.panning) {
                this.panning = null
                this.commit(false)
            }

            if (this.dragging) {
                const moved = this.dragging.moved
                this.dragging = null

                if (moved) {
                    this.commit()
                }
            }

            if (this.connecting) {
                const target = event.target.closest('[data-node-id]')

                if (target) {
                    this.connect(this.connecting.source, target.dataset.nodeId)
                }

                this.connecting = null
            }
        },

        // ── keyboard ─────────────────────────────────────────────────────────

        /**
         * Delete/Backspace is bound at the window so the canvas doesn't need
         * focus — which means it must ignore keystrokes aimed at any field on
         * the page, or typing elsewhere in the form would silently delete the
         * selected node.
         */
        onKeydown(event) {
            if (this.readonly || (!this.selectedNodeId && !this.selectedEdgeId)) {
                return
            }

            if (this.isTypingTarget(event.target)) {
                return
            }

            event.preventDefault()
            this.removeSelected()
        },

        isTypingTarget(target) {
            const tag = target?.tagName?.toLowerCase()

            return tag === 'input' || tag === 'textarea' || tag === 'select' || target?.isContentEditable
        },

        /** Node-level keys: Enter/Space configures, arrows nudge. */
        onNodeKeydown(event, id) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                this.editNode(id)

                return
            }

            if (this.readonly || !event.key.startsWith('Arrow')) {
                return
            }

            const node = this.nodeById(id)

            if (!node) {
                return
            }

            event.preventDefault()

            const step = event.shiftKey ? NUDGE * 4 : NUDGE
            const delta = {
                ArrowUp: { x: 0, y: -step },
                ArrowDown: { x: 0, y: step },
                ArrowLeft: { x: -step, y: 0 },
                ArrowRight: { x: step, y: 0 },
            }[event.key]

            node.position = { x: node.position.x + delta.x, y: node.position.y + delta.y }
            this.commit()
        },

        ariaLabel(node) {
            const parts = [this.nodeLabel(node)]
            const summary = this.summaryOf(node)

            if (summary) {
                parts.push(summary)
            }

            if (this.hasError(node.id)) {
                parts.push(this.errorFor(node.id))
            }

            return parts.join('. ')
        },

        // ── graph mutation ───────────────────────────────────────────────────

        canConnect(sourceId, targetId) {
            if (sourceId === targetId) {
                return false
            }

            if (this.edges.some((edge) => edge.source === sourceId && edge.target === targetId)) {
                return false
            }

            const sourceType = this.typeOf(this.nodeById(sourceId))
            const targetType = this.typeOf(this.nodeById(targetId))

            if (sourceType.terminal || targetType.initial) {
                return false
            }

            const outgoing = this.edges.filter((edge) => edge.source === sourceId).length
            const incoming = this.edges.filter((edge) => edge.target === targetId).length

            if (sourceType.maxOutgoing !== null && sourceType.maxOutgoing !== undefined && outgoing >= sourceType.maxOutgoing) {
                return false
            }

            if (targetType.maxIncoming !== null && targetType.maxIncoming !== undefined && incoming >= targetType.maxIncoming) {
                return false
            }

            return !this.wouldCycle(sourceId, targetId)
        },

        wouldCycle(sourceId, targetId) {
            const seen = new Set()
            const queue = [targetId]

            while (queue.length) {
                const id = queue.shift()

                if (id === sourceId) {
                    return true
                }

                if (seen.has(id)) {
                    continue
                }

                seen.add(id)

                this.edges
                    .filter((edge) => edge.source === id)
                    .forEach((edge) => queue.push(edge.target))
            }

            return false
        },

        connect(sourceId, targetId) {
            if (!this.canConnect(sourceId, targetId)) {
                return
            }

            this.edges.push({
                id: uid('e'),
                source: sourceId,
                target: targetId,
                outcome: null,
                condition: null,
            })

            this.commit()
        },

        addNode(typeName) {
            const type = this.nodeTypes.find((candidate) => candidate.name === typeName)

            if (!type || this.readonly) {
                return
            }

            if (type.singleton && this.nodes.some((node) => node.type === typeName)) {
                return
            }

            const rect = this.canvasRect
            const centre = this.screenToFlow(rect.left + rect.width / 2, rect.top + rect.height / 3)

            const size = this.sizeOf(null)
            const spot = this.freePosition(centre.x, centre.y, size)

            const node = {
                id: uid('n'),
                type: typeName,
                position: this.snapPosition(spot.x, spot.y, null),
                config: {},
            }

            this.nodes.push(node)
            this.select(node.id)
            this.commit()
        },

        /**
         * Drop one node and every edge touching it. The toolbar's *Delete
         * selection* button and the built-in `deleteNode` node action both come
         * through here, so there is one implementation of the mutation.
         */
        removeNode(id) {
            if (this.readonly || !this.nodeById(id)) {
                return
            }

            this.nodes = this.nodes.filter((node) => node.id !== id)
            this.edges = this.edges.filter((edge) => edge.source !== id && edge.target !== id)

            if (this.selectedNodeId === id) {
                this.selectedNodeId = null
            }

            this.commit()
        },

        removeEdge(id) {
            if (this.readonly || !this.edgeById(id)) {
                return
            }

            this.edges = this.edges.filter((edge) => edge.id !== id)

            if (this.selectedEdgeId === id) {
                this.selectedEdgeId = null
            }

            this.commit()
        },

        removeSelected() {
            if (this.readonly) {
                return
            }

            if (this.selectedEdgeId) {
                this.removeEdge(this.selectedEdgeId)

                return
            }

            if (this.selectedNodeId) {
                this.removeNode(this.selectedNodeId)
            }
        },

        select(id) {
            this.selectedNodeId = id
            this.selectedEdgeId = null

            const node = id ? this.nodeById(id) : null

            this.$dispatch('circuit-node-selected', node
                ? { id: node.id, type: node.type, config: node.config }
                : null)
        },

        selectEdge(id) {
            this.selectedEdgeId = id
            this.selectedNodeId = null
        },

        // ── presentation preferences ─────────────────────────────────────────

        /**
         * Where the chosen height is kept. Keyed by state path, so two canvases
         * on one page keep their own; a read-only entry has no state path and
         * simply does not persist — it is a view, not a workspace.
         */
        preferenceKey(kind) {
            return this.statePath ? `circuit:${kind}:${this.statePath}` : null
        },

        restorePreferences() {
            // Storage can be unavailable (private mode, a strict policy) and
            // the defaults are perfectly serviceable, so never let it throw.
            try {
                const height = parseInt(localStorage.getItem(this.preferenceKey('height')), 10)

                if (Number.isFinite(height)) {
                    this.height = this.clampHeight(height)
                }
            } catch (error) {
                //
            }
        },

        remember(kind, value) {
            const key = this.preferenceKey(kind)

            if (!key) {
                return
            }

            try {
                localStorage.setItem(key, String(value))
            } catch (error) {
                //
            }
        },

        /**
         * Flip the flow between top-down and left-to-right.
         *
         * The positions on the graph were authored for the other axis, so the
         * flip is followed by a tidy-up: handles that used to be on the bottom
         * are now on the side, and without re-laying out, every connection
         * would leave and re-enter across the cards. That does move nodes —
         * it is one undo away.
         *
         * Offered on a read-only canvas too: the re-layout happens entirely
         * client-side (commit() is a no-op there), so flipping a preview is
         * looking at the same graph another way round, not editing it.
         *
         * Deliberately NOT remembered across reloads, unlike the height. The
         * direction is only coherent alongside positions laid out for it, and
         * those live in the saved graph — a remembered direction meeting the
         * positions someone else saved is the one combination that reads worse
         * than either default.
         */
        toggleDirection() {
            if (!this.orientable) {
                return
            }

            this.direction = this.isHorizontal ? 'vertical' : 'horizontal'

            this.autoLayout()
        },

        // ── height ───────────────────────────────────────────────────────────

        clampHeight(value) {
            return Math.min(this.maxHeight, Math.max(this.minHeight, Math.round(value)))
        },

        setHeight(value) {
            this.height = this.clampHeight(value)

            // The surface just changed size under the drag.
            this.$nextTick(() => this.measureSurface())
        },

        onResizeStart(event) {
            if (!this.resizable) {
                return
            }

            event.preventDefault()

            this._resizing = { y: event.clientY, height: this.height }

            // Capture, so a fast drag that outruns the 12px grip keeps
            // resizing instead of dropping the pointer on the page behind.
            event.currentTarget.setPointerCapture?.(event.pointerId)
        },

        onResizeMove(event) {
            if (!this._resizing) {
                return
            }

            this.setHeight(this._resizing.height + (event.clientY - this._resizing.y))
        },

        onResizeEnd() {
            if (!this._resizing) {
                return
            }

            this._resizing = null
            this.remember('height', this.height)
            this.$nextTick(() => this.measure())
        },

        /** The grip is focusable, so the arrow keys have to do the same job. */
        onResizeKeydown(event) {
            const delta = { ArrowUp: -RESIZE_STEP, ArrowDown: RESIZE_STEP }[event.key]

            if (!delta || !this.resizable) {
                return
            }

            event.preventDefault()
            this.setHeight(this.height + delta)
            this.remember('height', this.height)
            this.$nextTick(() => this.measure())
        },

        // ── history ──────────────────────────────────────────────────────────

        /**
         * The graph as one comparable string. The viewport is left out on
         * purpose: panning is not an edit, and undo should not throw the view
         * back to where it happened to be three changes ago.
         */
        historySnapshot() {
            return JSON.stringify({ nodes: this.nodes, edges: this.edges })
        },

        pushHistory() {
            if (this.readonly || !this.undoable) {
                return
            }

            const snapshot = this.historySnapshot()

            // A drag that ends where it started, a commit that changed only the
            // viewport — nothing to remember.
            if (this.history[this.historyIndex] === snapshot) {
                return
            }

            // Editing after an undo abandons the states that were in front.
            this.history = [...this.history.slice(0, this.historyIndex + 1), snapshot]

            if (this.history.length > this.historyLimit) {
                this.history = this.history.slice(this.history.length - this.historyLimit)
            }

            this.historyIndex = this.history.length - 1
        },

        get canUndo() {
            return !this.readonly && this.undoable && this.historyIndex > 0
        },

        get canRedo() {
            return !this.readonly && this.undoable && this.historyIndex < this.history.length - 1
        },

        restoreHistory(index) {
            const entry = this.history[index]

            if (entry === undefined) {
                return
            }

            const graph = JSON.parse(entry)

            // The flag keeps the commit below from recording the restore as a
            // fresh edit, which would append the state we just walked back to.
            this._restoringHistory = true
            this.hydrate({ ...graph, viewport: this.viewport })
            this.historyIndex = index

            // Whatever was selected may no longer exist.
            this.selectedNodeId = null
            this.selectedEdgeId = null

            this.commit()
            this._restoringHistory = false

            this.$nextTick(() => this.measure())
        },

        undo() {
            if (this.canUndo) {
                this.restoreHistory(this.historyIndex - 1)
            }
        },

        redo() {
            if (this.canRedo) {
                this.restoreHistory(this.historyIndex + 1)
            }
        },

        /**
         * Ctrl/Cmd+Z and Ctrl/Cmd+Shift+Z (plus Ctrl+Y, which Windows editors
         * use for redo), bound at the window so the canvas need not hold focus
         * — which is exactly why it has to bow out of anything the keystroke
         * more plausibly belongs to: a text field, or an open modal, where
         * undo means "undo my typing".
         */
        onHistoryKeydown(event) {
            if (this.readonly || !(event.ctrlKey || event.metaKey) || event.altKey) {
                return
            }

            const key = event.key?.toLowerCase()

            if (key !== 'z' && key !== 'y') {
                return
            }

            if (this.isTypingTarget(event.target) || event.target?.closest?.('[role="dialog"]')) {
                return
            }

            event.preventDefault()

            if (key === 'y' || event.shiftKey) {
                this.redo()

                return
            }

            this.undo()
        },

        // ── layout ───────────────────────────────────────────────────────────

        /**
         * Deterministic layered layout — the fallback when positions are
         * absent, and the "tidy up" button.
         *
         * Levels come from longest-path BFS. Horizontal placement follows the
         * branches instead of centring every row independently: a node's
         * desired x is the average of, per parent, that parent's x plus a
         * spread offset when the parent fans out to several children. Fan-outs
         * therefore open sideways, joins pull back to centre, and a skip edge
         * (parent connecting past an intermediate node) keeps its through-lane
         * instead of being flattened into a single overlapping column.
         */
        autoLayout() {
            const entries = this.nodes
                .filter((node) => this.typeOf(node).initial)
                .map((node) => node.id)

            const roots = entries.length ? entries : this.nodes.slice(0, 1).map((node) => node.id)

            const level = {}
            const queue = roots.map((id) => [id, 0])

            while (queue.length) {
                const [id, depth] = queue.shift()

                if (level[id] !== undefined && level[id] >= depth) {
                    continue
                }

                level[id] = depth

                this.edges
                    .filter((edge) => edge.source === id)
                    .forEach((edge) => queue.push([edge.target, depth + 1]))
            }

            // Gaps between neighbours, swapped when the flow runs
            // left-to-right. Node extents come from the last measure().
            const crossGap = this.isHorizontal ? LAYOUT_Y_GAP : LAYOUT_X_GAP
            const mainGap = this.isHorizontal ? LAYOUT_X_GAP : LAYOUT_Y_GAP

            const crossExtent = (id) => {
                const size = this.sizeOf(id)

                return this.isHorizontal ? size.h : size.w
            }

            const mainExtent = (id) => {
                const size = this.sizeOf(id)

                return this.isHorizontal ? size.w : size.h
            }

            // Nominal sibling pitch, used only to spread a fan-out around its
            // parent; real collisions are settled against measured extents below.
            const column = (this.isHorizontal ? DEFAULT_NODE_HEIGHT : NODE_WIDTH) + crossGap

            // Children per parent, in edge order — the fan-out spread.
            const childrenOf = {}
            const parentsOf = {}

            this.edges.forEach((edge) => {
                if (level[edge.source] === undefined || level[edge.target] === undefined) {
                    return
                }

                ;(childrenOf[edge.source] ??= []).push(edge.target)
                ;(parentsOf[edge.target] ??= []).push(edge.source)
            })

            const rows = {}

            this.nodes.forEach((node) => {
                const depth = level[node.id] ?? 0

                ;(rows[depth] ??= []).push(node)
            })

            // Cross positions are worked out as CENTRES and converted to the
            // top-left corner at the end. Left-to-right, cards differ in height,
            // and aligning their top edges leaves every centre — and so every
            // handle — a few pixels apart, which puts a small kink in what
            // should be a straight connection.
            const x = {}

            const depths = Object.keys(rows)
                .map(Number)
                .sort((a, b) => a - b)

            // Where each level starts along the main axis: the running sum of
            // the levels before it, each as deep as its deepest node.
            const mainAt = {}
            let cursor = 0

            depths.forEach((depth) => {
                mainAt[depth] = cursor
                cursor += Math.max(...rows[depth].map((node) => mainExtent(node.id))) + mainGap
            })

            depths.forEach((depth) => {
                const row = rows[depth]

                row.forEach((node) => {
                    const parents = parentsOf[node.id] ?? []

                    if (!parents.length) {
                        // Roots (and orphans) spread around zero.
                        const index = row.indexOf(node)

                        x[node.id] = (index - (row.length - 1) / 2) * column

                        return
                    }

                    const desired = parents.map((parent) => {
                        const siblings = childrenOf[parent]
                        const index = siblings.indexOf(node.id)

                        return (x[parent] ?? 0) + (index - (siblings.length - 1) / 2) * column
                    })

                    x[node.id] = desired.reduce((sum, value) => sum + value, 0) / desired.length
                })

                // Resolve overlaps within the row, preserving order.
                row.sort((a, b) => (x[a.id] ?? 0) - (x[b.id] ?? 0))

                row.forEach((node, index) => {
                    if (index > 0) {
                        const previous = row[index - 1]

                        // Centre to centre: half of each neighbour, plus the gap.
                        x[node.id] = Math.max(
                            x[node.id],
                            x[previous.id] + (crossExtent(previous.id) + crossExtent(node.id)) / 2 + crossGap,
                        )
                    }
                })

                row.forEach((node) => {
                    // Snap the CENTRE, then derive the corner. Snapping the
                    // corner instead would put cards of different sizes back
                    // out of line, which is the kink this alignment removes.
                    const cross = this.snapToLine(x[node.id]) - crossExtent(node.id) / 2
                    const main = this.snapToLine(mainAt[depth])

                    node.position = this.isHorizontal
                        ? { x: main, y: cross }
                        : { x: cross, y: main }
                })
            })

            this.commit()

            this.$nextTick(() => {
                this.measure()
                this.fitView()
            })
        },

        // ── minimap ──────────────────────────────────────────────────────────

        /**
         * Graph coordinates to the minimap's 140×90 inner box. Shared, so the
         * blocks and the lines between them agree on where things are.
         */
        get minimapProjection() {
            const bounds = this.bounds()
            const width = Math.max(1, bounds.maxX - bounds.minX)
            const height = Math.max(1, bounds.maxY - bounds.minY)

            const boxWidth = Math.max(1, this.minimapWidth - MINIMAP_CHROME)
            const boxHeight = Math.max(1, this.minimapHeight - MINIMAP_CHROME)

            const scale = Math.min(boxWidth / width, boxHeight / height)

            return {
                minX: bounds.minX,
                minY: bounds.minY,
                scale,
                // Fitting to the tighter axis leaves slack on the other one.
                // Split it, so the graph sits in the middle of the minimap the
                // way fit-to-view puts it in the middle of the canvas —
                // otherwise everything hugs one corner and the viewport frame
                // around it looks permanently off to one side.
                offsetX: (boxWidth - width * scale) / 2,
                offsetY: (boxHeight - height * scale) / 2,
            }
        },

        get minimapNodes() {
            if (!this.nodes.length) {
                return []
            }

            const { minX, minY, scale, offsetX, offsetY } = this.minimapProjection

            return this.nodes.map((node) => {
                const size = this.sizeOf(node.id)

                return {
                    id: node.id,
                    color: this.typeOf(node).color ?? 'gray',
                    left: (node.position.x - minX) * scale + offsetX,
                    top: (node.position.y - minY) * scale + offsetY,
                    width: Math.max(3, size.w * scale),
                    height: Math.max(3, size.h * scale),
                }
            })
        },

        /**
         * Where the visible area of the surface sits within the whole graph.
         *
         * The layer is drawn at `translate(viewport) scale(zoom)`, so the flow
         * coordinate at the surface's top-left corner is `-viewport / zoom`,
         * and the flow-space width of what is on screen is `surface / zoom`.
         * Projected into minimap space, that is the box the viewer is looking
         * through — and when it covers the whole minimap, nothing is off screen.
         */
        get minimapViewport() {
            if (!this.nodes.length || !this.surface.w) {
                return null
            }

            const { minX, minY, scale, offsetX, offsetY } = this.minimapProjection
            const { x, y, zoom } = this.viewport

            return {
                left: (-x / zoom - minX) * scale + offsetX,
                top: (-y / zoom - minY) * scale + offsetY,
                width: (this.surface.w / zoom) * scale,
                height: (this.surface.h / zoom) * scale,
            }
        },

        /**
         * The connections, as straight centre-to-centre lines.
         *
         * Deliberately not the orthogonal routing the canvas draws: at a
         * fifteenth of the size those bends collapse into noise, and what the
         * minimap is for is the shape of the graph — which parts are a chain
         * and which fan out. The lines are drawn under the blocks, so each one
         * disappears into the two nodes it joins instead of crossing them.
         *
         * Built as markup for the same reason the main edges are: a <template>
         * inside an <svg> is parsed into the SVG namespace and has no .content
         * for Alpine to clone.
         */
        get minimapEdgesMarkup() {
            if (!this.nodes.length) {
                return ''
            }

            const { minX, minY, scale, offsetX, offsetY } = this.minimapProjection

            const centre = (id) => {
                const node = this.nodeById(id)

                if (!node) {
                    return null
                }

                const size = this.sizeOf(id)

                return {
                    x: (node.position.x - minX + size.w / 2) * scale + offsetX,
                    y: (node.position.y - minY + size.h / 2) * scale + offsetY,
                }
            }

            return this.edges.map((edge) => {
                const from = centre(edge.source)
                const to = centre(edge.target)

                if (!from || !to) {
                    return ''
                }

                return `<line x1="${from.x}" y1="${from.y}" x2="${to.x}" y2="${to.y}"></line>`
            }).join('')
        },

        // ── presentation helpers ─────────────────────────────────────────────

        nodeLabel(node) {
            return this.typeOf(node).label ?? node.type
        },

        nodeColor(node) {
            return this.typeOf(node).color ?? 'gray'
        },

        summaryOf(node) {
            return node.summary ?? node.config?.summary ?? ''
        },

        /**
         * The pill text for an edge. Outcome labels resolve client-side from
         * the source type's declared outcomes; condition summaries arrive
         * baked into `edge.label` by the server (the payload is opaque here).
         */
        edgeLabelOf(edge) {
            if (edge.outcome) {
                return this.typeOf(this.nodeById(edge.source)).outcomes?.[edge.outcome] ?? edge.outcome
            }

            return edge.label ?? null
        },

        get labelledEdges() {
            return this.edges.filter((edge) => this.edgeLabelOf(edge))
        },

        /**
         * Whether this edge's midpoint affordance is a live control rather than
         * a static pill: the canvas has to be editable AND the slide-over has
         * to have something to offer, or the button would be a lie.
         */
        edgeIsConfigurable(id) {
            return !this.readonly && this.canConfigureEdge(id)
        },

        /** Labelled edges with nothing to open — read-only entries, mostly. */
        get staticEdgeLabels() {
            return this.labelledEdges.filter((edge) => !this.edgeIsConfigurable(edge.id))
        },

        /** Labelled edges whose pill doubles as the configure button. */
        get configurableEdgeLabels() {
            return this.labelledEdges.filter((edge) => this.edgeIsConfigurable(edge.id))
        },

        /**
         * Configurable edges with no label yet. Without a pill there is nothing
         * at the midpoint to click, and the connection reads as inert — these
         * get the small "+" instead, so *every* configurable connection
         * advertises itself.
         */
        get bareConfigurableEdges() {
            return this.edges.filter((edge) => !this.edgeLabelOf(edge) && this.edgeIsConfigurable(edge.id))
        },

        /**
         * The midpoint control opens edge config on a single click. A
         * double-click on it fires two of those, and mounting the action twice
         * would stack two identical modals — `detail` counts the clicks in the
         * sequence (0 for a keyboard activation), so the repeat is dropped.
         */
        onEdgeControlClick(event, id) {
            if ((event.detail ?? 0) > 1) {
                return
            }

            this.configureEdge(id)
        },

        /**
         * Where the pill and the configure button sit: halfway ALONG the route,
         * not halfway between the anchors. A loop-back route bows out on a lane
         * of its own, and anchoring to the straight-line midpoint would leave
         * its label floating in space away from the line it belongs to.
         */
        edgeMidpoint(edge) {
            const { from, to } = this.routeEnds(edge)
            const points = this.waypoints(from, to, edge)

            const lengths = []
            let total = 0

            for (let i = 1; i < points.length; i++) {
                const length = Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y)

                lengths.push(length)
                total += length
            }

            let travelled = 0

            for (let i = 0; i < lengths.length; i++) {
                if (travelled + lengths[i] >= total / 2) {
                    const into = lengths[i] === 0 ? 0 : (total / 2 - travelled) / lengths[i]

                    return {
                        x: points[i].x + (points[i + 1].x - points[i].x) * into,
                        y: points[i].y + (points[i + 1].y - points[i].y) * into,
                    }
                }

                travelled += lengths[i]
            }

            return points[points.length - 1]
        },

        isConnectable(id) {
            return Boolean(this.connecting) && this.canConnect(this.connecting.source, id)
        },

        /** The valid target currently hovered during a connection drag. */
        isDropTarget(id) {
            return this.connecting?.hover === id && this.isConnectable(id)
        },

        get availableTypes() {
            return this.nodeTypes.filter(
                (type) => type.addable !== false
                    && (!type.singleton || !this.nodes.some((node) => node.type === type.name)),
            )
        },
    }))
})
