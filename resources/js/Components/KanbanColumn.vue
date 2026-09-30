<script setup>
    //General Imports
    import {ref, useId} from "vue";

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
        /**
         * What this stage of the board actually means. A one-word column title ("Ordering") says
         * where a batch is but not how it got there or what leaves it, and the board has no room
         * to spell that out in the header. Given one, the title picks up a dashed underline and
         * explains itself on hover.
         */
        tooltip: String,
    });

    //Variables
    const showTooltip = ref(false);
    //Ties the title to its bubble for screen readers, uniquely per column on the board
    const tooltipId = useId();
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

        Lifted above its neighbours while its tooltip is open. The bubble is as wide as the column
        and the columns sit flush in a grid, so without this the column to the right - painted
        later, at the same z-index - would cover it.
    -->
    <section
        class="relative flex min-h-0 flex-col rounded-xl border border-gray-300 bg-white shadow-sm"
        :class="showTooltip ? 'z-30' : ''"
    >
        <!-- header -->
        <header class="relative flex flex-none items-center justify-between gap-2 rounded-t-xl border-b border-gray-300 bg-white px-3 py-3">
            <div class="flex min-w-0 items-center gap-2">
                <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-blue-50 text-[11px] font-bold text-blue-800 ring-1 ring-inset ring-blue-100">
                    {{ step }}
                </span>
                <!-- The -my-1 gives the focus ring below room inside the truncate's clipping box -->
                <h2 class="-my-1 truncate py-1 text-sm font-semibold uppercase tracking-wide text-gray-700">
                    <!--
                        Focusable, so the explanation is reachable without a mouse - the same
                        reason it answers to focus as well as hover.
                    -->
                    <span
                        v-if="tooltip"
                        tabindex="0"
                        :aria-describedby="tooltipId"
                        class="cursor-help underline decoration-gray-400 decoration-dashed decoration-1 underline-offset-4 transition-colors duration-150 hover:text-gray-900 hover:decoration-gray-500 focus:outline-none focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                        @mouseenter="showTooltip = true"
                        @mouseleave="showTooltip = false"
                        @focus="showTooltip = true"
                        @blur="showTooltip = false"
                    >
                        {{ title }}
                    </span>
                    <template v-else>{{ title }}</template>
                </h2>
            </div>
            <span
                class="flex-none rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                :class="count > 0 ? 'bg-gray-200 text-gray-700' : 'bg-gray-100 text-gray-400'"
            >
                {{ count }}
            </span>

            <!--
                Hangs off the header rather than the title, because the title truncates and an
                overflow-hidden box cannot show anything outside itself. Left-aligned to the step
                badge so it reads as belonging to this column.
            -->
            <Transition
                enter-active-class="transition-opacity duration-150"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="tooltip && showTooltip"
                    :id="tooltipId"
                    role="tooltip"
                    class="pointer-events-none absolute left-3 right-3 top-full z-30 mt-2 rounded-lg bg-gray-900 px-3 py-2 text-xs font-normal normal-case leading-relaxed tracking-normal text-white shadow-lg"
                >
                    <span aria-hidden="true" class="absolute -top-1 left-5 h-2 w-2 rotate-45 bg-gray-900"></span>
                    {{ tooltip }}
                </div>
            </Transition>
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
