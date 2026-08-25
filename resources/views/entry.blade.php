<x-filament-infolists::entry-wrapper :entry="$entry">
    <x-circuit::canvas-inner
        :config="$getCanvasConfig()"
        :height="$getHeight()"
        :resizable="$isResizable()"
        :readonly="true"
    />
</x-filament-infolists::entry-wrapper>
