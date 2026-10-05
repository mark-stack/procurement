<script setup>
    /**
     * Where something has got to in the pipeline, worded as the screen asking words it.
     *
     * One definition of the labels, because two screens print them - the dashboard's table of live
     * jobs and the Nesting page's cards. A step worded one way on one screen and another way on the
     * next reads as two different pipelines.
     *
     * The two screens ask different questions of that pipeline, so there are two sets of words:
     *
     *  - The dashboard says the step the work is ON, which is App\Services\BatchStages plus the two
     *    columns it does not cover: NESTING, where a batch is before it exists, and COMPLETED, where it
     *    goes after - a past batch.
     *  - The Nesting page says the last step the batch has PASSED, and uses both wordings to do it: the
     *    ing-word while that step is half done (QUOTING is a batch still missing a price from one of
     *    its merchants, ORDERING one with material nobody has bought yet) and the ed-word once it is
     *    finished. Not the same answer as the column: a batch whose steel is all ordered and still on a
     *    lorry is in the Delivering column, and has only got as far as ORDERED. See
     *    NestingIndexController::milestoneOf().
     *
     * Every step is drawn the same quiet blue, whichever of the two wordings it is in - see the
     * classes below for why the colour is not the thing carrying the meaning here.
     */
    //Props
    const props = defineProps({
        //NESTING | QUOTING | QUOTED | ORDERING | ORDERED | DELIVERING | DELIVERED | CUT | COMPLETED
        stage: String,
    });

    //Methods
    function label(stage) {
        return {
            NESTING: 'Nesting',
            QUOTING: 'Quoting',
            QUOTED: 'Quoted',
            ORDERING: 'Ordering',
            ORDERED: 'Ordered',
            DELIVERING: 'Delivering',
            DELIVERED: 'Delivered',
            CUT: 'Cut',
            COMPLETED: 'Completed',
        }[stage] ?? stage;
    }

    /*
     * One colour for every step, and the word is what says which step it is.
     *
     * This used to run a palette down the pipeline - grey for Nesting, blue for quoting, indigo for
     * ordering, teal for delivering, emerald for Cut - so that a column could be read by colour
     * without reading the words. What that actually produced was a page where nothing stood out,
     * because everything was coloured: five hues down a list of cards is decoration, and a reader
     * learning which of them means trouble has to learn five.
     *
     * There is one thing on these cards worth a colour, and it is not where the batch has got to -
     * it is whether the batch is going to make its date, which the pill beside this one says in
     * green, amber and red (RequiredByPill). Those three only carry while they are the only colours
     * on the row. So the step is drawn in one quiet blue on every card and says its piece in words.
     */
    const PILL_CLASSES = 'bg-blue-50 text-blue-800 ring-blue-200';
</script>

<template>
    <span
        :class="PILL_CLASSES"
        class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full ring-1"
    >
        {{ label(stage) }}
    </span>
</template>
