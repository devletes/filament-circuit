<?php

namespace Devletes\Circuit\Concerns;

use Closure;

/**
 * The presentation and interaction options both CircuitCanvas and CircuitEntry
 * accept.
 *
 * Every one of them starts as `null` rather than a literal, so an unset option
 * falls through to `config('circuit.*')` at read time. That is what makes the
 * published config file a real default rather than a suggestion — change it and
 * every canvas that never said otherwise follows, including ones rendered
 * before the change.
 *
 * Turning a tool off hides its toolbar button *and* disables the behaviour
 * behind it, so a canvas never has a capability its interface does not show.
 */
trait HasCanvasOptions
{
    protected int|Closure|null $height = null;

    protected string|Closure|null $direction = null;

    protected bool|Closure|null $resizable = null;

    protected bool|Closure|null $orientable = null;

    protected bool|Closure|null $undoable = null;

    protected bool|Closure|null $tidyable = null;

    protected bool|Closure|null $zoomable = null;

    protected bool|Closure|null $showMinimap = null;

    protected int|Closure|null $minimapWidth = null;

    protected int|Closure|null $minimapHeight = null;

    protected int|Closure|null $historyLimit = null;

    /**
     * Which `circuit.height.*` key this component takes its starting height
     * from — a read-only view reasonably wants a shorter box than an editor.
     */
    abstract protected function circuitHeightKey(): string;

    /** The height the canvas opens at; the viewer may drag from there. */
    public function height(int|Closure|null $height): static
    {
        $this->height = $height;

        return $this;
    }

    /** 'vertical' (top-down, default) or 'horizontal' (left-to-right). */
    public function direction(string|Closure|null $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function horizontal(): static
    {
        return $this->direction('horizontal');
    }

    public function vertical(): static
    {
        return $this->direction('vertical');
    }

    /** Offer a grip on the bottom edge for dragging the canvas taller. */
    public function resizable(bool|Closure|null $condition = true): static
    {
        $this->resizable = $condition;

        return $this;
    }

    /** Offer a toolbar toggle between a top-down and a left-to-right flow. */
    public function orientable(bool|Closure|null $condition = true): static
    {
        $this->orientable = $condition;

        return $this;
    }

    /** Offer undo/redo — the toolbar buttons and the keyboard shortcuts. */
    public function undoable(bool|Closure|null $condition = true): static
    {
        $this->undoable = $condition;

        return $this;
    }

    /** Offer the tidy-up button that re-runs the automatic layout. */
    public function tidyable(bool|Closure|null $condition = true): static
    {
        $this->tidyable = $condition;

        return $this;
    }

    /** Offer zoom in/out and fit-to-view, and let the wheel zoom the surface. */
    public function zoomable(bool|Closure|null $condition = true): static
    {
        $this->zoomable = $condition;

        return $this;
    }

    public function showMinimap(bool|Closure|null $condition = true): static
    {
        $this->showMinimap = $condition;

        return $this;
    }

    /**
     * How big the minimap is, in pixels. The graph is scaled to fit inside it,
     * so a bigger one shows the same graph in more detail, not more of it.
     */
    public function minimapSize(int|Closure|null $width, int|Closure|null $height = null): static
    {
        $this->minimapWidth = $width;
        $this->minimapHeight = $height ?? $width;

        return $this;
    }

    /** How many graph states undo remembers. */
    public function historyLimit(int|Closure|null $limit): static
    {
        $this->historyLimit = $limit;

        return $this;
    }

    public function getHeight(): int
    {
        return (int) ($this->evaluate($this->height)
            ?? config('circuit.height.'.$this->circuitHeightKey(), 560));
    }

    public function getMinHeight(): int
    {
        return (int) config('circuit.height.min', 240);
    }

    public function getMaxHeight(): int
    {
        return (int) config('circuit.height.max', 2400);
    }

    public function getDirection(): string
    {
        $direction = $this->evaluate($this->direction) ?? config('circuit.direction', 'vertical');

        return $direction === 'horizontal' ? 'horizontal' : 'vertical';
    }

    public function isResizable(): bool
    {
        return $this->resolveTool('resizable');
    }

    public function isOrientable(): bool
    {
        return $this->resolveTool('orientable');
    }

    public function isUndoable(): bool
    {
        return $this->resolveTool('undoable');
    }

    public function isTidyable(): bool
    {
        return $this->resolveTool('tidyable');
    }

    public function isZoomable(): bool
    {
        return $this->resolveTool('zoomable');
    }

    public function shouldShowMinimap(): bool
    {
        return (bool) ($this->evaluate($this->showMinimap) ?? config('circuit.tools.minimap', true));
    }

    public function getMinimapWidth(): int
    {
        return max(60, (int) ($this->evaluate($this->minimapWidth) ?? config('circuit.minimap.width', 160)));
    }

    public function getMinimapHeight(): int
    {
        return max(60, (int) ($this->evaluate($this->minimapHeight) ?? config('circuit.minimap.height', 110)));
    }

    public function getHistoryLimit(): int
    {
        return max(1, (int) ($this->evaluate($this->historyLimit) ?? config('circuit.history_limit', 50)));
    }

    /**
     * The options the client needs to know which affordances it may offer.
     *
     * @return array<string, mixed>
     */
    protected function getCanvasOptions(): array
    {
        return [
            'height' => $this->getHeight(),
            'minHeight' => $this->getMinHeight(),
            'maxHeight' => $this->getMaxHeight(),
            'direction' => $this->getDirection(),
            'resizable' => $this->isResizable(),
            'orientable' => $this->isOrientable(),
            'undoable' => $this->isUndoable(),
            'tidyable' => $this->isTidyable(),
            'zoomable' => $this->isZoomable(),
            'showMinimap' => $this->shouldShowMinimap(),
            'minimapWidth' => $this->getMinimapWidth(),
            'minimapHeight' => $this->getMinimapHeight(),
            'historyLimit' => $this->getHistoryLimit(),
        ];
    }

    protected function resolveTool(string $name): bool
    {
        return (bool) ($this->evaluate($this->{$name}) ?? config("circuit.tools.{$name}", true));
    }
}
