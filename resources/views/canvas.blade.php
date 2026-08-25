<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- The editNode slide-over needs no trigger button here: the canvas
         mounts it from Alpine via $wire.mountAction(...), and the modal
         renders through the page's actions portal. --}}
    <x-circuit::canvas-inner
        :config="$getCanvasConfig()"
        :state-path="$getStatePath()"
        :height="$getHeight()"
        :resizable="$isResizable()"
        :readonly="$isDisabled()"
    />
</x-dynamic-component>
