<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Shared Imports
    import useSavings from "@/Shared/useSavings.js";

    //Props
    const props = defineProps({
        //Reactive slider values, owned by the page so the hero headline reads the same numbers
        inputs: Object,
        //Term and pricing for the page, see Welcome.vue
        plan: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    const {
        scrapRefundPct,
        savingsDisplay,
        termDisplay,
        roiDisplay,
    } = useSavings(props.inputs, props.plan);

    //Methods
    //...
</script>

<template>
    <!-- Pricing slider -->
    <div>
        <!-- Material spend ($m) -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                id="calculator-annual-spend"
                v-model.number="inputs.annualSpendMillions"
                type="range"
                min="0.5"
                max="6"
                step="0.1"
                :aria-valuetext="'$' + inputs.annualSpendMillions + ' million per year'"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <label for="calculator-annual-spend" class="mt-2 text-gray-800 font-semibold">
                Steel sections spend: ${{inputs.annualSpendMillions}}m/year
            </label>
        </div>

        <!--
            What the shop wastes on the saw today, as a share of what it buys.

            This is the reader's own number, not ours: they know what their yield runs at, and the
            default sits a little under what the trade does, not over it - see Welcome.vue.

            Range 5-15. Below five nobody is nesting anything by hand that well; above fifteen the
            reader is describing a shop working off a whiteboard, where the saving stops being
            credible because it is too large rather than too small.
        -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                id="calculator-current-waste"
                v-model.number="inputs.currentWastePct"
                type="range"
                min="5"
                max="15"
                step="1"
                :aria-valuetext="inputs.currentWastePct + '% of steel wasted today'"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <label for="calculator-current-waste" class="mt-2 text-gray-800 font-semibold">
                Steel you waste today: {{inputs.currentWastePct}}% of what you buy
            </label>
        </div>

        <!--
            How much of that waste nesting takes away - the headline claim, left open to the reader.

            Defaults to the 50% the page is sold on: manual nesting on sections at about 88% yield
            against good 1D nesting at about 94%, so twelve points of waste become six. Range 30-70,
            because a shop already nesting carefully is at the bottom of it and a shop working off a
            whiteboard is at the top, and either of them should be able to find their own case on the
            page rather than be told ours.

            The hero headline reads this slider too, so the claim and the dollar figure under it can
            never be two different assumptions on one screen. What this does to the waste in figures -
            10% becoming 5% at the defaults - is drawn on the page itself rather than here, under
            "Where the 50% comes from", which is why nestedWastePct and materialRecoveredPct are not
            read in this component.
        -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                id="calculator-waste-reduction"
                v-model.number="inputs.wasteReductionPct"
                type="range"
                min="30"
                max="70"
                step="5"
                :aria-valuetext="inputs.wasteReductionPct + '% less cutting waste'"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <label for="calculator-waste-reduction" class="mt-2 text-gray-800 font-semibold">
                Waste reduction: {{inputs.wasteReductionPct}}%
            </label>
        </div>

        <div class="flex flex-col items-center p-4 ">
            <span class="block mt-3 text-4xl text-deep-purple-accent-400">Save <b>${{ savingsDisplay }}</b> {{ termDisplay }}</span>
            <div v-if="roiDisplay" class="flex mt-2">
                <span class="text-emerald-500 font-bold">{{ roiDisplay }} ROI</span> <span class="ml-1 text-emerald-500"> from the software</span>
            </div>

            <!--
                The scrap refund is a constant now, not a slider, but it still has to be stated: it is
                taken off every figure above, and a reader who works the arithmetic themselves and
                lands 13% high will think the page cannot add up rather than that it was being careful.
                Interpolated rather than typed so the note cannot outlive the rate it names.
            -->
            <p class="mt-3 max-w-sm text-center text-xs text-gray-500">
                {{ scrapRefundPct }}% nominal scrap refund rate accounted for
            </p>
        </div>
    </div>
</template>
