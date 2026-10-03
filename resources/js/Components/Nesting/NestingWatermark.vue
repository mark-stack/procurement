<script setup>
    /*
        Stamped across one A4 page of a nesting sheet.

        Absolutely positioned over the page rather than behind it: a watermark the content paints over
        is a watermark that disappears on exactly the dense pages worth stamping. It is kept faint and
        pointer-events none, so the drawings underneath stay readable and still clickable.

        The page it sits on has to be a positioned element - see NestingPrintFriendly's page classes.
     */

    //Props
    defineProps({
        text: String,
    });
</script>

<template>
    <div class="watermark" aria-hidden="true">
        <span class="watermark-text">{{ text }}</span>
    </div>
</template>

<style scoped>
    .watermark {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        pointer-events: none;
        z-index: 10;
    }
    .watermark-text {
        transform: rotate(-30deg);
        font-size: 2.8cm;
        font-weight: 800;
        letter-spacing: 0.08em;
        line-height: 1;
        white-space: nowrap;
        text-transform: uppercase;
        color: rgba(220, 38, 38, 0.17);
        /*
            Browsers drop colour that is "only decoration" when printing, and a watermark that prints
            as nothing is the one thing this must never be.
         */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
</style>
