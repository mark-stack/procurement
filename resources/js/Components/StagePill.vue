<script setup>
    /**
     * Where something has got to in the pipeline, worded as the screen asking words it.
     *
     * One definition of the labels and their colours, because two screens print them - the dashboard's
     * table of live jobs and the Nesting page's cards. A step that reads one colour on one screen and
     * another colour on the next reads as two different pipelines.
     *
     * The two screens ask different questions of that pipeline, so there are two sets of words:
     *
     *  - The dashboard says the step the work is ON, which is App\Services\BatchStages plus the two
     *    columns it does not cover: NESTING, where a batch is before it exists, and COMPLETED, where it
     *    goes after - a past project.
     *  - The Nesting page says the last step the batch has PASSED - QUOTED, ORDERED, DELIVERED. Not the
     *    same answer: a batch whose steel is all ordered and still on a lorry is in the Delivering
     *    column, and has only got as far as ORDERED. See NestingIndexController::milestoneOf().
     *
     * A step keeps its colour across both wordings, so the two still read as the one pipeline.
     */
    //Props
    const props = defineProps({
        //NESTING | QUOTING | QUOTED | ORDERING | ORDERED | DELIVERING | DELIVERED | COMPLETED
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
            COMPLETED: 'Completed',
        }[stage] ?? stage;
    }

    /*
     * One colour per step, kept in the same order the board's columns run in so the screens read as
     * the same pipeline - and shared by the two wordings of a step, which are the same place on it.
     */
    function classes(stage) {
        return {
            NESTING: 'bg-gray-100 text-gray-700 ring-gray-300',
            QUOTING: 'bg-blue-50 text-blue-800 ring-blue-200',
            QUOTED: 'bg-blue-50 text-blue-800 ring-blue-200',
            ORDERING: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
            ORDERED: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
            DELIVERING: 'bg-teal-50 text-teal-800 ring-teal-200',
            DELIVERED: 'bg-teal-50 text-teal-800 ring-teal-200',
            //Closed, so it is deliberately the quietest of them - it is history, not work
            COMPLETED: 'bg-gray-50 text-gray-500 ring-gray-200',
        }[stage] ?? 'bg-gray-100 text-gray-700 ring-gray-300';
    }
</script>

<template>
    <span
        :class="classes(stage)"
        class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full ring-1"
    >
        {{ label(stage) }}
    </span>
</template>
