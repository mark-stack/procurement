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
    const {savingsDisplay, termDisplay, roiDisplay} = useSavings(props.inputs, props.plan);

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

        <!-- Extra yield won back by better nesting, as a share of total spend -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                id="calculator-yield-gain"
                v-model.number="inputs.yieldGainPct"
                type="range"
                min="3"
                max="7"
                step="0.5"
                :aria-valuetext="inputs.yieldGainPct + '% of annual spend'"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <label for="calculator-yield-gain" class="mt-2 text-gray-800 font-semibold">
                Extra material yield: {{inputs.yieldGainPct}}% of annual spend
            </label>
        </div>

        <!-- scrap rate -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                id="calculator-scrap-refund"
                v-model.number="inputs.scrapRefundPct"
                type="range"
                min="10"
                max="16"
                step="1"
                :aria-valuetext="inputs.scrapRefundPct + '% already recovered as scrap'"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <label for="calculator-scrap-refund" class="mt-2 text-gray-800 font-semibold">
                Scrap refund rate: {{inputs.scrapRefundPct}}%
            </label>

            <!-- Raising this lowers the saving, which needs saying or it looks like a bug -->
            <p class="mt-1 max-w-sm text-center text-xs text-gray-500">
                Deducted from the saving, because offcuts you never cut are scrap you no longer sell.
            </p>
        </div>
        <div class="flex flex-col items-center p-4 ">
            <span class="block mt-3 text-4xl text-deep-purple-accent-400">Save <b>${{ savingsDisplay }}</b> {{ termDisplay }}</span>
            <div v-if="roiDisplay" class="flex mt-2">
                <span class="text-emerald-500 font-bold">{{ roiDisplay }} ROI</span> <span class="ml-1 text-emerald-500"> from the software</span>
            </div>

        </div>
    </div>
</template>
