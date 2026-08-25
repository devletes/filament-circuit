<?php

namespace Devletes\Circuit\Tests\Feature;

use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Infolists\Components\CircuitEntry;
use Devletes\Circuit\Tests\Fixtures\CanvasComponent;
use Devletes\Circuit\Tests\TestCase;
use Filament\Schemas\Schema;

/**
 * The published config is the default for every canvas that has not said
 * otherwise, and a per-field method always wins over it.
 */
class CanvasOptionsTest extends TestCase
{
    protected function canvas(): CircuitCanvas
    {
        $canvas = CircuitCanvas::make('graph')->nodeTypes(CanvasComponent::registry());

        Schema::make()->components([$canvas]);

        return $canvas;
    }

    protected function entry(): CircuitEntry
    {
        $entry = CircuitEntry::make('graph')->nodeTypes(CanvasComponent::registry());

        Schema::make()->components([$entry]);

        return $entry;
    }

    public function test_the_shipped_defaults_are_what_a_canvas_opens_with(): void
    {
        $canvas = $this->canvas();

        $this->assertSame(560, $canvas->getHeight());
        $this->assertSame('vertical', $canvas->getDirection());
        $this->assertSame(50, $canvas->getHistoryLimit());
        $this->assertSame(240, $canvas->getMinHeight());
        $this->assertSame(2400, $canvas->getMaxHeight());
        $this->assertSame([160, 110], [$canvas->getMinimapWidth(), $canvas->getMinimapHeight()]);

        foreach (['isResizable', 'isOrientable', 'isUndoable', 'isTidyable', 'isZoomable', 'shouldShowMinimap'] as $tool) {
            $this->assertTrue($canvas->{$tool}(), "{$tool} should be on by default");
        }

        // A read-only view reasonably opens shorter than an editor.
        $this->assertSame(480, $this->entry()->getHeight());
    }

    public function test_config_moves_the_default_for_every_canvas_that_did_not_say_otherwise(): void
    {
        config([
            'circuit.height.canvas' => 700,
            'circuit.height.entry' => 300,
            'circuit.height.min' => 100,
            'circuit.height.max' => 900,
            'circuit.direction' => 'horizontal',
            'circuit.history_limit' => 5,
            'circuit.tools.resizable' => false,
            'circuit.tools.orientable' => false,
            'circuit.tools.undoable' => false,
            'circuit.tools.tidyable' => false,
            'circuit.tools.zoomable' => false,
            'circuit.tools.minimap' => false,
            'circuit.grid.snap' => false,
            'circuit.grid.size' => 24,
            'circuit.minimap.width' => 220,
            'circuit.minimap.height' => 140,
        ]);

        $canvas = $this->canvas();

        $this->assertSame(700, $canvas->getHeight());
        $this->assertSame(100, $canvas->getMinHeight());
        $this->assertSame(900, $canvas->getMaxHeight());
        $this->assertSame('horizontal', $canvas->getDirection());
        $this->assertSame(5, $canvas->getHistoryLimit());
        $this->assertSame(24, $canvas->getGridSize());
        $this->assertFalse($canvas->shouldSnapToGrid());
        $this->assertSame([220, 140], [$canvas->getMinimapWidth(), $canvas->getMinimapHeight()]);

        foreach (['isResizable', 'isOrientable', 'isUndoable', 'isTidyable', 'isZoomable', 'shouldShowMinimap'] as $tool) {
            $this->assertFalse($canvas->{$tool}(), "{$tool} should follow the config");
        }

        $this->assertSame(300, $this->entry()->getHeight());
        $this->assertFalse($this->entry()->isResizable());
    }

    public function test_a_field_always_outranks_the_config(): void
    {
        config([
            'circuit.height.canvas' => 700,
            'circuit.direction' => 'horizontal',
            'circuit.history_limit' => 5,
            'circuit.tools.undoable' => false,
            'circuit.tools.zoomable' => false,
        ]);

        $canvas = $this->canvas()
            ->height(320)
            ->vertical()
            ->historyLimit(200)
            ->undoable()
            ->zoomable(fn (): bool => true);

        $this->assertSame(320, $canvas->getHeight());
        $this->assertSame('vertical', $canvas->getDirection());
        $this->assertSame(200, $canvas->getHistoryLimit());
        $this->assertTrue($canvas->isUndoable());
        $this->assertTrue($canvas->isZoomable());
    }

    public function test_the_minimap_can_be_sized_per_field(): void
    {
        config(['circuit.minimap.width' => 220, 'circuit.minimap.height' => 140]);

        $canvas = $this->canvas()->minimapSize(300, 200);

        $this->assertSame([300, 200], [$canvas->getMinimapWidth(), $canvas->getMinimapHeight()]);

        // One argument squares it off rather than leaving the other axis on
        // whatever the config happened to say.
        $square = $this->canvas()->minimapSize(180);

        $this->assertSame([180, 180], [$square->getMinimapWidth(), $square->getMinimapHeight()]);

        // Too small to project a graph into is not a size worth honouring.
        $this->assertSame([60, 60], [
            $this->canvas()->minimapSize(4)->getMinimapWidth(),
            $this->canvas()->minimapSize(4)->getMinimapHeight(),
        ]);
    }

    public function test_the_minimap_size_reaches_the_markup(): void
    {
        config(['circuit.minimap.width' => 240, 'circuit.minimap.height' => 150]);

        $html = \Livewire\Livewire::test(CanvasComponent::class)->html();

        $this->assertStringContainsString('--fi-circuit-minimap-w: 240px', $html);
        $this->assertStringContainsString('--fi-circuit-minimap-h: 150px', $html);
    }

    public function test_an_option_set_back_to_null_falls_through_to_the_config_again(): void
    {
        config(['circuit.tools.tidyable' => false]);

        $canvas = $this->canvas()->tidyable(true);

        $this->assertTrue($canvas->isTidyable());
        $this->assertFalse($canvas->tidyable(null)->isTidyable());
    }

    public function test_an_unknown_direction_is_treated_as_vertical(): void
    {
        $this->assertSame('vertical', $this->canvas()->direction('sideways')->getDirection());
        $this->assertSame('horizontal', $this->canvas()->horizontal()->getDirection());
    }

    public function test_the_history_limit_never_drops_below_one_state(): void
    {
        // A zero-length stack would make pushHistory() drop the state it just
        // recorded, so undo would silently never work.
        $this->assertSame(1, $this->canvas()->historyLimit(0)->getHistoryLimit());
    }

    public function test_every_option_reaches_the_client(): void
    {
        // Through a real component: the client config reads state, which needs
        // a container to read it from.
        $page = \Livewire\Livewire::test(CanvasComponent::class);
        $config = $page->instance()->getSchemaComponent('form.graph')->getCanvasConfig();

        foreach ([
            'height', 'minHeight', 'maxHeight', 'direction', 'resizable',
            'orientable', 'undoable', 'tidyable', 'zoomable', 'showMinimap',
            'minimapWidth', 'minimapHeight', 'historyLimit', 'snapToGrid', 'gridSize',
        ] as $key) {
            $this->assertArrayHasKey($key, $config, "{$key} should reach the client");
        }
    }

    public function test_a_disabled_tool_leaves_no_button_behind(): void
    {
        $html = \Livewire\Livewire::test(CanvasComponent::class)->html();

        $this->assertStringContainsString('Tidy up', $html);
        $this->assertStringContainsString('Undo', $html);

        config([
            'circuit.tools.tidyable' => false,
            'circuit.tools.undoable' => false,
            'circuit.tools.orientable' => false,
            'circuit.tools.zoomable' => false,
            'circuit.tools.resizable' => false,
        ]);

        $html = \Livewire\Livewire::test(CanvasComponent::class)->html();

        $this->assertStringNotContainsString('Tidy up', $html);
        $this->assertStringNotContainsString('Undo', $html);
        $this->assertStringNotContainsString('Zoom in', $html);
        $this->assertStringNotContainsString('fi-circuit-resizer', $html);
    }
}
