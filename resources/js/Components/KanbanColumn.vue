<script setup>
    //General Imports
    //...

    //Props
    const props = defineProps({
        step: [String, Number],
        title: String,
        count: {
            type: Number,
            default: 0,
        },
    });
</script>

<template>
    <!--
        One board column. The header stays put while the body scrolls, so the
        stage you are looking at is always labelled.
    -->
    <section class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-sm">
        <!-- header -->
        <header class="flex flex-none items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-3">
            <div class="flex min-w-0 items-center gap-2">
                <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-blue-50 text-[11px] font-bold text-blue-800 ring-1 ring-inset ring-blue-100">
                    {{ step }}
                </span>
                <h2 class="truncate text-sm font-semibold uppercase tracking-wide text-gray-700">
                    {{ title }}
                </h2>
            </div>
            <span
                class="flex-none rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                :class="count > 0 ? 'bg-gray-200 text-gray-700' : 'bg-gray-100 text-gray-400'"
            >
                {{ count }}
            </span>
        </header>

        <!-- body -->
        <div class="kanban-scroll min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
            <slot/>
        </div>
    </section>
</template>

<style scoped>
    /* Slim, unobtrusive scrollbar - only shows against the column body */
    .kanban-scroll {
        scrollbar-width: thin;
        scrollbar-color: #d0d0d0 transparent;
    }

    .kanban-scroll::-webkit-scrollbar {
        width: 8px;
    }

    .kanban-scroll::-webkit-scrollbar-track {
        background: transparent;
    }

    .kanban-scroll::-webkit-scrollbar-thumb {
        background: #d4d4d4;
        border: 2px solid transparent;
        background-clip: content-box;
        border-radius: 9999px;
    }

    .kanban-scroll::-webkit-scrollbar-thumb:hover {
        background: #b0b0b0;
        background-clip: content-box;
    }
</style>
