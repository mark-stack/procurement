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
        /**
         * Draws the arrow into the next column, in the gutter to the right of this one. Off on the
         * last column, which leads nowhere.
         */
        flowsOn: Boolean,
    });
</script>

<template>
    <!--
        One board column. The header stays put while the body scrolls, so the stage you are looking
        at is always labelled.

        Four surfaces, each a real step from the one it sits on: the page is gray-50, this shell is
        white, its body is a gray-200 well, and the cards in that well are white again. All four
        used to be within 2% of each other - shell, well and page all gray-50 under a white card -
        so the column, the batch and the project inside it read as one continuous sheet of white.

        No overflow-hidden on the shell: the flow arrow below hangs into the gutter, and clipping
        the corners is the body's own job now (it scrolls, so it clips anyway).
    -->
    <section class="relative flex min-h-0 flex-col rounded-xl border border-gray-300 bg-white shadow-sm">
        <!-- header -->
        <header class="flex flex-none items-center justify-between gap-2 rounded-t-xl border-b border-gray-300 bg-white px-3 py-3">
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
        <div class="kanban-scroll min-h-0 flex-1 space-y-3 overflow-y-auto rounded-b-xl bg-gray-200 p-3">
            <slot/>
        </div>

        <!--
            The board is a pipeline and nothing said so - four columns of equal weight, in an order
            you had to already know. The arrow sits in the gutter between this column and the next,
            halfway down, so the eye is carried left to right from Nesting through to Delivering.

            Only on the wide layout. Below xl the columns wrap to a 2x2 grid, where the second
            column's neighbour is not the third but the edge of the screen, and an arrow there
            would point the wrong way.
        -->
        <span
            v-if="flowsOn"
            aria-hidden="true"
            class="pointer-events-none absolute left-full top-1/2 z-10 ml-3 hidden -translate-x-1/2 -translate-y-1/2 items-center justify-center text-gray-400 xl:flex"
        >
            <i class="fa-solid fa-chevron-right text-base"></i>
        </span>
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
