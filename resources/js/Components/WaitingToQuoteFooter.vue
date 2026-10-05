<script setup>
    /**
     * Nothing to do about this batch yet, under the open card that is filling up.
     *
     * The opposite number of ActionRequiredFooter, and drawn in the same strip: that one says a card
     * has lost time somebody has to go and win back, this one says a card has time in hand and that
     * spending it is the right thing to do. Both are conclusions the page draws so the reader does
     * not have to - the facts behind this one are that the open batch is the only batch an upload can
     * still join, that a bigger batch nests better and buys cheaper, and that nothing is lost by
     * waiting until the ordering deadline. Put together they say "leave it", which is the thing
     * somebody standing over a half-full card wants to be told.
     *
     * Green, because it is the one footer that is not a job. A card carrying it is a card to walk
     * past, and it has to read that way at a glance beside the amber and red ones - a page where
     * every footer is a warning is a page where the warnings stop being read.
     *
     * Only before the deadline, which is what the day count below decides: on the day itself and
     * after it, waiting is not the cheaper choice any more - it is the choice that puts the earliest
     * job on this batch behind its fabrication date. The same line startQuotingDialog draws, and for
     * the same reason: neither may advise waiting on a day the deadline has already arrived.
     *
     * The email is not a promise this component makes up. On the ordering deadline, and every day it
     * goes on waiting after, App\Services\FabricationDeadlineQuoting mails and bells the manager of
     * the job whose fabrication date forced it (BatchReadyToQuoteEmail) and the manager of every
     * other job waiting with it (ColleagueOrderingBatchTodayEmail). That sweep is why waiting here is
     * safe rather than merely cheap - the batch cannot be quietly forgotten in the column - so the
     * footer says so, in the same breath as the advice it is the condition for.
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        /*
         * The day this batch has to stop waiting and be quoted - KanbanFormatter::orderingTriggerDate,
         * five days before the earliest fabrication start on the card.
         *
         * Null when no job waiting here names a fabrication date. There is no deadline to be early
         * for and no sweep that will ever chase it, so there is no advice to give and the footer is
         * not drawn at all.
         */
        deadline: String,
    });

    //Variables
    /*
     * How much longer it is allowed to sit there, counted the way the "Start quoting" dialog counts
     * it (startQuotingDialog) - calendar days to the deadline, which is what the sweep that sends the
     * email measures too. Deliberately not the working days the critical path is counted in: this is
     * not a card's progress against the work it owes, it is the date a schedule fires on.
     */
    const daysToDeadline = computed(() => props.deadline
        ? moment(props.deadline).startOf('day').diff(moment().startOf('day'), 'days')
        : null);

    const deadlineLabel = computed(() => props.deadline
        ? moment(props.deadline).format('D MMM')
        : null);

    const daysLabel = computed(() => daysToDeadline.value === 1
        ? 'tomorrow'
        : `${daysToDeadline.value} days away`);
</script>

<template>
    <div
        v-if="daysToDeadline > 0"
        class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 text-xs border-t rounded-b-xl bg-emerald-50 border-emerald-100 text-emerald-900"
    >
        <i class="fa-solid fa-hourglass-half text-[11px] opacity-80"></i>

        <span class="font-semibold">
            Nothing to do yet: let this batch keep filling
        </span>

        <!--
            And why leaving it alone is the right call, quieter than the advice itself - the same
            shape ActionRequiredFooter uses, where the instruction is read first and the evidence for
            it is there for somebody who wants to argue with the card.
        -->
        <span class="opacity-80">
            &middot; quote it by {{ deadlineLabel }}, {{ daysLabel }}. Jobs nested together share bars
            and offcuts, so each one costs less in material - and everyone with work waiting here is
            emailed when the batch has to go out.
        </span>
    </div>
</template>
