<script setup>
    //General Imports
    import {Head, Link, usePage} from '@inertiajs/vue3';
    import {computed, reactive} from "vue";

    //Component Imports
    import SavingsCalculator from "@/Components/SavingsCalculator.vue";
    import LandingNav from "@/Components/Nav/LandingNav.vue";
    import CadLogosBanner from "@/Components/CadLogosBanner.vue";

    //Shared Imports
    import useSavings from "@/Shared/useSavings.js";

    //Props
    const props = defineProps({
        //config('billing.trial_days') - what a signup will actually be granted
        trialDays: Number,
    });

    //Form
    //...

    //Shared data
    const user = usePage().props.auth.user;
    const loginAvailable = computed(() => usePage().props.loginAvailable);

    //Variables
    /*
     * How the trial is described everywhere on this page, derived from the length the application
     * will actually grant rather than typed in beside it. A whole number of 30-day months reads as
     * months because that is how it is sold; anything else is quoted in days rather than rounded
     * into a claim the app will not honour.
     */
    const trialLabel = computed(() => {
        const days = props.trialDays ?? 30;

        if (days % 30 === 0) {
            const months = days / 30;

            return `${months} Month${months > 1 ? 's' : ''}`;
        }

        return `${days} Day${days > 1 ? 's' : ''}`;
    });

    const savings_period_years = 1;
    const fullPriceMultiYear = 10000;
    /*
     * How many years that up front figure buys. Null until the multi-year plan is a real product
     * with a decided term - a lump sum is not a price until you know what it covers, and useSavings
     * hides the ROI and the monthly comparison rather than print a figure that guessed. Set this at
     * the same time as the price above, never separately.
     */
    const multiYearTermYears = null;
    const fullPriceAnnual = 2900;
    const fullPriceMonthly = 200;
    const fullPriceWeekly = 39;
    const firstYearDiscount = 0;
    const whichPlan = "WEEKLY"; //"MONTHLY","ANNUAL", "WEEKLY", "MULTI_YEAR"

    //Both calculators on the page share these, so the hero figure and the one further down agree
    const calculatorInputs = reactive({
        annualSpendMillions:1.5,
        /*
         * What the shop wastes on the saw today, as a share of what it buys.
         *
         * Ten points, which is deliberately a little kinder to the trade than the trade deserves:
         * manual nesting on sections runs nearer 12% (about 88% yield), and that is the pair of figures
         * the 50% below is derived from. Starting the reader at 10 understates the saving rather than
         * overstating it, and a hero figure that turns out to be low once somebody checks their own
         * yield is the only direction this page can afford to be wrong in.
         */
        currentWastePct:10,
        /*
         * How much of that waste goes away, and the number in the hero headline. Fifty, because twelve
         * points of waste become about six once the same cut list is nested across every live project
         * and against the offcuts already on the rack - good 1D nesting on sections against nesting it
         * by hand, 94% against 88%.
         *
         * THE HEADLINE READS THIS. Every piece of copy that states the claim - the hero, the "where
         * the 50% comes from" band, the calculator - takes it from here, so dragging the slider moves
         * all of them together and the page cannot end up asserting one figure above a dollar saving
         * worked out from another. Nothing types "50".
         */
        wasteReductionPct:50,
    });

    const plan = {
        years: savings_period_years,
        whichPlan,
        fullPriceMultiYear,
        multiYearTermYears,
        fullPriceAnnual,
        fullPriceMonthly,
        fullPriceWeekly,
        firstYearDiscount,
    };


    //Shared Methods
    const {
        nestedWastePct,
        materialRecoveredPct,
        savingsDisplay,
        termDisplay,
        costPerMonth,
    } = useSavings(calculatorInputs, plan);

    /*
        The cost model section below. Kept as data rather than markup because it is a list that will
        grow - every figure here is a real coefficient out of App\Services\NestingCostModel, and the
        defaults quoted are that class's DEFAULTS, which are also the businesses table column defaults.
    */
    const materialFactors = [
        {
            title: "Steel bought",
            body: "Millimetres become kilograms through the section's mass per metre, then dollars through your steel price. The only line you actually pay money out on.",
        },
        {
            title: "Steel destroyed",
            body: "Any offcut shorter than your scrap threshold goes in the bin, wherever it came from. Credited back at your scrap recovery rate, because the merchant weighs it in — so binning a bad remnant costs about seven eighths of the steel, not all of it.",
        },
        {
            title: "Saw kerf",
            body: "Costed as material, with no scrap credit. Swarf mixed with coolant is not something anyone buys back.",
        },
        {
            title: "Offcut value given up",
            body: "Drawing a length off the rack spends what it was worth and earns back whatever the new offcut is worth. Cutting a 2,000mm stub down to 1,500mm gives up very little; taking the same 500mm off a 9,000mm length gives up a great deal.",
        },
    ];

    const labourFactors = [
        {
            title: "Every saw cut",
            body: "A base time plus time that scales with mass per metre — how much section the blade has to get through. A cut is a cut whatever the length of the piece.",
        },
        {
            title: "Fetching an offcut",
            body: "Finding it in the rack, reading its mark and getting it to the saw. Scales with the weight of the piece being moved.",
        },
        {
            title: "Handling a new bar",
            body: "Off the lift and over to the saw. A 6m angle is carried; a 12m 500UB at over a tonne is a crane, slings and a second person.",
        },
        {
            title: "The rack, for the life of the piece",
            body: "Every offcut you keep has to be marked, recorded and shifted out of the way on every future job. Charged when an offcut goes on the rack, and credited when you consume a stub outright and retire the mark for good.",
        },
    ];

    const costModelRules = [
        {
            title: "A cut you don't get is not a saving",
            body: "A cut that no bar or offcut can hold carries a penalty no amount of material or labour can buy its way past. Every piece gets made first; cost decides between the plans that manage it.",
        },
        {
            title: "No cliff at the scrap threshold",
            body: "Score a banked offcut at full value and a 1,001mm offcut is free while a 999mm one is a write-off. A thousand iterations will find that line every time and fill your rack with metre-long stubs. Value rises on a curve instead, so an offcut that clears the threshold by a millimetre is worth almost nothing.",
        },
        {
            title: "Keep a remnant only while it beats the labour of keeping it",
            body: "On light angle that lands somewhere past two metres — anything shorter costs more to mark, record and shift than the steel will ever return. On a heavy beam almost anything over the threshold is worth having.",
        },
    ];

    //Methods
    //...
</script>

<template>
    <Head title="Halve your steel cutting waste | SteelNesting.com.au" />

    <!-- Nav -->
    <LandingNav/>

    <!-- Hero -->
    <div class="p-5 lg:p-20 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl lg:py-20">
        <div class="flex flex-col items-center justify-between lg:flex-row">
            <div class="mb-10 lg:max-w-lg lg:pr-5 lg:mb-0">
                <div class="max-w-xl mb-16">
                    <h2 class="max-w-lg mb-6 font-sans text-5xl font-bold tracking-tight text-gray-900 sm:leading-none">
                        {{calculatorInputs.wasteReductionPct}}% less cutting <u>waste</u>, so you spend
                        <span class="inline-block text-orange-900">${{savingsDisplay}} less on steel {{termDisplay}}</span>
                    </h2>
                    <p class="max-w-lg text-base text-gray-700 md:text-lg">
                        Steel nesting for Australian fabricators.
                    </p>
                </div>
                <div class="flex flex-col items-center md:flex-row">
                    <Link
                        v-if="loginAvailable"
                        :href="route('register')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-3 font-medium tracking-wide text-white transition duration-200 rounded shadow-md md:w-auto md:mr-4 md:mb-0 bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                    >
                        <span class="mr-3">{{trialLabel}} FREE TRIAL</span>
                    </Link>
                    <Link
                        v-else
                        :href="route('guest.onboarding')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-3 font-medium tracking-wide text-white transition duration-200 rounded shadow-md md:w-auto md:mr-4 md:mb-0 bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                    >
                        <span class="mr-3">{{trialLabel}} FREE TRIAL</span>
                    </Link>
                </div>
            </div>
            <div class="lg:w-1/2">
                <div class="relative">

                    <img
                        src="https://framerusercontent.com/images/7c1DajeRRBT7lYlrLnWW397U.png"
                        style="width:120px"
                        class="mx-auto"
                    >
                    <!-- Pricing slider -->
                    <SavingsCalculator
                        :inputs="calculatorInputs"
                        :plan="plan"
                    />
                </div>
            </div>
        </div>
    </div>

    <!--
        Where the headline figure comes from.

        The claim in the hero is the specific, checkable kind, so the first thing under it is the
        arithmetic behind it rather than a feature list. Every figure here is read off the calculator's
        own sliders, so moving one moves this block too - a reader who tells the page their yield is
        already good sees a smaller claim, not ours repeated at them.
    -->
    <div class="bg-gray-900">
        <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
            <div class="max-w-3xl mb-10 md:mx-auto sm:text-center">
                <h2 class="mb-6 font-sans text-4xl font-bold leading-none tracking-tight text-white md:mx-auto">
                    Where the {{calculatorInputs.wasteReductionPct}}% comes from
                </h2>
            </div>
            <div class="grid max-w-screen-lg gap-8 mx-auto text-gray-300 md:grid-cols-3">
                <div>
                    <p class="mb-2 text-3xl font-extrabold text-white">{{100 - calculatorInputs.currentWastePct}}%</p>
                    <h6 class="mb-2 text-lg font-bold text-white">Nesting a cut list by hand</h6>
                    <p class="text-sm">
                        Yield on sections lands somewhere near here. The other
                        {{calculatorInputs.currentWastePct}}% is drops too short to use, saw kerf, and
                        offcuts that go back on the rack and are never reached for again.
                    </p>
                </div>
                <div>
                    <p class="mb-2 text-3xl font-extrabold text-white">{{100 - nestedWastePct}}%</p>
                    <h6 class="mb-2 text-lg font-bold text-white">The same list, nested here</h6>
                    <p class="text-sm">
                        {{nestedWastePct}}% waste instead of {{calculatorInputs.currentWastePct}}%,
                        because the same thousand-combination search runs across every live project at
                        once and draws on the offcuts you already own before it buys a bar.
                    </p>
                </div>
                <div>
                    <p class="mb-2 text-3xl font-extrabold text-white">{{materialRecoveredPct}}%</p>
                    <h6 class="mb-2 text-lg font-bold text-white">Off your steel bill</h6>
                    <p class="text-sm">
                        {{calculatorInputs.wasteReductionPct}}% of the waste gone, which is
                        {{materialRecoveredPct}}% of what you buy becoming structure instead of scrap.
                        Less whatever the merchant already pays you to weigh it in &mdash; that part
                        was never yours to save twice.
                    </p>
                </div>
            </div>
            <p class="max-w-screen-lg mx-auto mt-10 text-sm text-center text-gray-400">
                A shop already running good 1D nesting has less than this to win, and a shop nesting by
                eye on a whiteboard has more, which is why both figures are yours to set and not ours
                to assert. And you do not have to take them on faith either way: every nest the
                application runs records what it actually achieved at the time &mdash; used, scrapped
                and kerfed against what it consumed &mdash; so your own yield is there month by month
                from the first job. <Link :href="route('try.nesting')" class="underline hover:text-white">Try
                it on a worked example</Link>, run through the real algorithm, with the checks shown.
            </p>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="mb-16 md:mx-auto sm:text-center">
            <h2 class="text-center max-w-3xl mb-6 font-sans text-5xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
                How that waste comes out
            </h2>
        </div>
        <div class="grid gap-8 row-gap-0 lg:grid-cols-3">
            <div class="relative text-center">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-indigo-50 sm:w-20 sm:h-20">
                    <svg class="w-12 h-12 text-deep-purple-accent-400 sm:w-16 sm:h-16" stroke="currentColor" viewBox="0 0 52 52">
                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                    </svg>
                </div>
                <h6 class="mb-2 text-2xl font-extrabold">Cross-project nesting</h6>
                <p class="max-w-md mb-3 text-sm text-gray-900 sm:mx-auto">
                    Multiple concurrent projects drive up material yield with no extra effort. The tool manages crystal clear distinction between cuts.
                </p>
                <div class="top-0 right-0 flex items-center justify-center h-24 lg:-mr-8 lg:absolute">
                    <svg class="w-8 text-gray-700 transform rotate-90 lg:rotate-0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <line fill="none" stroke-miterlimit="10" x1="2" y1="12" x2="22" y2="12"></line>
                        <polyline fill="none" stroke-miterlimit="10" points="15,5 22,12 15,19 "></polyline>
                    </svg>
                </div>
            </div>
            <div class="relative text-center">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-indigo-50 sm:w-20 sm:h-20">
                    <svg class="w-12 h-12 text-deep-purple-accent-400 sm:w-16 sm:h-16" stroke="currentColor" viewBox="0 0 52 52">
                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                    </svg>
                </div>
                <h6 class="mb-2 text-2xl font-extrabold">Auto nest offcut inventory</h6>
                <p class="max-w-md mb-3 text-sm text-gray-900 sm:mx-auto">
                    Real time offcut tracking & placement. No stocktaking necessary as the tool knows it's own history so knows exactly what offcuts you have in stock.
                </p>
                <div class="top-0 right-0 flex items-center justify-center h-24 lg:-mr-8 lg:absolute">
                    <svg class="w-8 text-gray-700 transform rotate-90 lg:rotate-0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <line fill="none" stroke-miterlimit="10" x1="2" y1="12" x2="22" y2="12"></line>
                        <polyline fill="none" stroke-miterlimit="10" points="15,5 22,12 15,19 "></polyline>
                    </svg>
                </div>
            </div>
            <div class="relative text-center">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-indigo-50 sm:w-20 sm:h-20">
                    <svg class="w-12 h-12 text-deep-purple-accent-400 sm:w-16 sm:h-16" stroke="currentColor" viewBox="0 0 52 52">
                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                    </svg>
                </div>
                <h6 class="mb-2 text-2xl font-extrabold">Iterative algorithms</h6>
                <p class="max-w-md mb-3 text-sm text-gray-900 sm:mx-auto">
                    The tool tries multiple algorithms and iterates to find best of 1000 nesting combinations.
                </p>
            </div>
        </div>
    </div>

    <!-- Cost model -->
    <div class="bg-gray-50">
        <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
            <div class="max-w-4xl mb-12 md:mx-auto sm:text-center">
                <h2 class="max-w-4xl mb-6 font-sans text-5xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
                    Cutting the waste means cutting the dollars
                </h2>
                <p class="text-base text-gray-700 md:text-lg">
                    Waste is not millimetres, it is money, and the two do not move together. Saving 500mm of
                    65x65 angle is worth about <strong>$6</strong>. Saving 500mm of 500UB is worth about
                    <strong>$90</strong>. Software that ranks plans on yield alone counts those as the same
                    win — which is how a shop ends up reading {{100 - nestedWastePct}}% on paper with a rack
                    full of metre-long stubs nobody will ever cut, and no less steel going out the door.
                </p>
                <p class="mt-4 text-base text-gray-700 md:text-lg">
                    So every candidate nest here is scored in <strong>dollars</strong>. Material converts through the
                    section's mass and your steel price; labour converts through a duration and your hourly rate.
                    Best of a thousand combinations means the cheapest to actually produce — which is what makes
                    what comes off your waste the part that was costing you something.
                </p>
            </div>

            <div class="grid max-w-screen-lg gap-8 mx-auto lg:grid-cols-2">
                <!-- Material -->
                <div class="p-6 bg-white border rounded shadow-sm sm:p-8">
                    <h3 class="pb-4 mb-6 text-2xl font-extrabold text-gray-900 border-b">
                        Material it consumes
                    </h3>
                    <ul class="space-y-6">
                        <li v-for="factor in materialFactors" :key="factor.title" class="flex">
                            <div class="mt-1 mr-4 shrink-0">
                                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-indigo-50">
                                    <svg class="w-5 h-5 text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h6 class="mb-1 text-lg font-bold leading-6">{{ factor.title }}</h6>
                                <p class="text-sm text-gray-700">{{ factor.body }}</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Labour -->
                <div class="p-6 bg-white border rounded shadow-sm sm:p-8">
                    <h3 class="pb-4 mb-6 text-2xl font-extrabold text-gray-900 border-b">
                        Time it takes
                    </h3>
                    <ul class="space-y-6">
                        <li v-for="factor in labourFactors" :key="factor.title" class="flex">
                            <div class="mt-1 mr-4 shrink-0">
                                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-indigo-50">
                                    <svg class="w-5 h-5 text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <h6 class="mb-1 text-lg font-bold leading-6">{{ factor.title }}</h6>
                                <p class="text-sm text-gray-700">{{ factor.body }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <p class="max-w-screen-lg mx-auto mt-6 text-sm text-center text-gray-600">
                Cutting time scales with mass per metre. Handling time scales with the weight of the piece being
                moved. Both are per-business settings, along with your labour rate, steel price, scrap recovery
                rate, kerf and scrap threshold — so the nest is priced for your shop, not a generic one.
            </p>

            <!-- Rules that override the arithmetic -->
            <div class="max-w-screen-lg mx-auto mt-16">
                <h3 class="mb-8 text-2xl font-extrabold text-center text-gray-900">
                    Three rules the arithmetic never gets to overrule
                </h3>
                <div class="grid gap-8 md:grid-cols-3">
                    <div v-for="rule in costModelRules" :key="rule.title">
                        <h6 class="mb-2 text-lg font-bold leading-6 text-gray-900">{{ rule.title }}</h6>
                        <p class="text-sm text-gray-700">{{ rule.body }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="px-4 mx-auto sm:max-w-6xl md:px-24 lg:px-8">
        <div class="overflow-x-auto px-4 py-8">
            <table class="min-w-full table-fixed border-separate border-spacing-y-2 text-sm text-left">
                <thead>
                    <tr>
                        <th class="w-48 font-semibold text-gray-700"></th>
                        <th class="text-center">SteelNesting<small>.com.au</small></th>
                        <th class="text-center">StruMIS<small>.com</small></th>
                        <th class="text-center">Tekla PowerFab</th>
                        <th class="text-center">1d-solutions<small>.com</small></th>
                        <th class="text-center">smartcut<small>.pro</small></th>
                        <th class="text-center">astrokettle<small>.com</small></th>
                        <th class="text-center">opticutter<small>.com</small></th>

                        <!--https://optimalprograms.com/realcut1d.htm#Price_Buy-->
                        <!--http://www.nirvanatec.com/order.html-->
                        <!--https://apps.autodesk.com/INVNTOR/en/Detail/Index?id=4775763541516569961&appLang=en&os=Win64-->
                    </tr>
                </thead>
                <!--
                    EVERY ROW IS IN HEADER ORDER: SteelNesting, StruMIS, Tekla, 1d-solutions, smartcut,
                    astrokettle, opticutter. The rows below used to run 1d-solutions, astrokettle,
                    opticutter, smartcut instead - invisible on the rows where those four share a value,
                    and wrong on the two where they do not. The price row published astrokettle's $48
                    under smartcut's heading, opticutter's $34 under astrokettle's and smartcut's $26
                    under opticutter's. Keep the trailing comments on every cell: they are what makes a
                    mis-ordered row show up while it is being edited rather than after it ships.
                -->
                <tbody class="divide-y divide-gray-200">
                    <tr class="bg-white">
                        <td class="py-3 font-medium text-gray-800">Cross-project nesting</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="py-3 font-medium text-gray-800">Offcut Inventory</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>

                    <tr class="bg-white">
                        <td class="py-3 font-medium text-gray-800">Material traceability</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="py-3 font-medium text-gray-800">Range of stock lengths</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center">✅</td><!--1d-solutions-->
                        <td class="text-center">✅</td><!--smartcut-->
                        <td class="text-center">✅</td><!--astrokettle-->
                        <td class="text-center">✅</td><!--opticutter-->
                    </tr>
                    <tr class="bg-white">
                        <td class="py-3 font-medium text-gray-800">FIFO (First in first out)</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="py-3 font-medium text-gray-800">Mitre cuts</td>
                        <td class="text-center text-xs">❌</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>
                    <tr class="bg-white">
                        <td class="py-3 font-medium text-gray-800">Multiple CAD</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">❌</td><!--Tekla PowerFab-->
                        <td class="text-center">✅</td><!--1d-solutions-->
                        <td class="text-center">✅</td><!--smartcut-->
                        <td class="text-center">✅</td><!--astrokettle-->
                        <td class="text-center">✅</td><!--opticutter-->
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="py-3 font-medium text-gray-800">Bulk Import</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center">✅<br><small>Tedious</small></td><!--1d-solutions-->
                        <td class="text-center">✅<br><small>Tedious</small></td><!--smartcut-->
                        <td class="text-center">✅<br><small>Tedious</small></td><!--astrokettle-->
                        <td class="text-center">✅<br><small>Tedious</small></td><!--opticutter-->
                    </tr>
                    <tr class="bg-white">
                        <td class="py-3 font-medium text-gray-800">A4 print formatted</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center">✅</td><!--StruMIS-->
                        <td class="text-center">✅</td><!--Tekla PowerFab-->
                        <td class="text-center">✅</td><!--1d-solutions-->
                        <td class="text-center">✅</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center">✅</td><!--opticutter-->
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="py-3 font-medium text-gray-800">Designed for<br>Australian Standards</td>
                        <td class="text-center">✅</td><!--SteelNesting.com.au-->
                        <td class="text-center text-xs">❌</td><!--StruMIS-->
                        <td class="text-center text-xs">❌</td><!--Tekla PowerFab-->
                        <td class="text-center text-xs">❌</td><!--1d-solutions-->
                        <td class="text-center text-xs">❌</td><!--smartcut-->
                        <td class="text-center text-xs">❌</td><!--astrokettle-->
                        <td class="text-center text-xs">❌</td><!--opticutter-->
                    </tr>
                    <tr class="bg-white">
                        <!--
                            No green/red on this row: at $169/month we sit above four of these,
                            so colouring ours green and theirs green too said nothing, and claiming
                            the cheap end would not survive a reader checking the numbers.
                        -->
                        <td class="py-3 font-medium text-gray-800">$AUD/month</td>
                        <td class="text-center text-gray-900 font-bold">{{ costPerMonth === null ? '—' : '$' + costPerMonth }}</td><!--SteelNesting.com.au-->
                        <td class="text-center text-gray-900 font-bold">$2,000+</td><!--StruMIS-->
                        <td class="text-center text-gray-900 font-bold">$2,000+</td><!--Tekla PowerFab-->
                        <td class="text-center text-gray-900 font-bold">$88</td><!--1d-solutions-->
                        <td class="text-center text-gray-900 font-bold">$26</td><!--smartcut-->
                        <td class="text-center text-gray-900 font-bold">$48</td><!--astrokettle-->
                        <td class="text-center text-gray-900 font-bold">$34</td><!--opticutter-->
                    </tr>
                </tbody>
            </table>
        </div>
    </div>


    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="mb-16 md:mx-auto sm:text-center">
            <h2 class="text-center max-w-4xl mb-6 font-sans text-5xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
                The <u>waste</u> is the point. The rest just has to work.
            </h2>
        </div>
        <div class="grid max-w-screen-lg mx-auto space-y-6 lg:grid-cols-2 lg:space-y-0 lg:divide-x">
            <div class="space-y-6 sm:px-16">
                <div class="flex flex-col max-w-md sm:flex-row">
                    <div class="mb-4 mr-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-indigo-50">
                            <svg class="w-8 h-8 text-deep-purple-accent-400 sm:w-10 sm:h-10" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h6 class="mb-3 text-xl font-bold leading-5">
                            Automatically import cut lists from any source
                        </h6>
                        <p class="text-sm text-gray-900">
                            Import BOMs from Tekla, Inventor, Advance Steel, or any CAD that exports to Excel — manual spreadsheets supported too.
                        </p>
                    </div>
                </div>
                <div class="flex flex-col max-w-md sm:flex-row">
                    <div class="mb-4 mr-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-indigo-50">
                            <svg class="w-8 h-8 text-deep-purple-accent-400 sm:w-10 sm:h-10" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h6 class="mb-3 text-xl font-bold leading-5">
                            100% Material traceability
                        </h6>
                        <p class="text-sm text-gray-900">
                            Attach material certificates from steel orders so that every single cut is traceable. Every cut ties to exact project when cross-project nesting is used.
                        </p>
                    </div>
                </div>
                <div class="flex flex-col max-w-md sm:flex-row">
                    <div class="mb-4 mr-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-indigo-50">
                            <svg class="w-8 h-8 text-deep-purple-accent-400 sm:w-10 sm:h-10" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h6 class="mb-3 text-xl font-bold leading-5">
                            Built 100% in Australia for Australian Fabricators
                        </h6>
                        <p class="text-sm text-gray-900">
                            Thousands of Australian-standard products from major steel catalogs. Local support from Melbourne
                        </p>
                    </div>
                </div>
            </div>
            <!--
                "Simple order tracking - a clear Kanban view of active orders" was here until the board
                was deleted. There is no Kanban view to show anybody now: the screen, its route and its
                gate all went, and /nesting carries the workflow. A promise on the landing page outlives
                the feature behind it unless it is taken down with it.
            -->
            <div class="space-y-6 sm:px-16">
                <div class="flex flex-col max-w-md sm:flex-row">
                    <div class="mb-4 mr-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-indigo-50">
                            <svg class="w-8 h-8 text-deep-purple-accent-400 sm:w-10 sm:h-10" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h6 class="mb-3 text-xl font-bold leading-5">
                           1-click BOM to Email
                        </h6>
                        <p class="text-sm text-gray-900">
                            Instantly launch an email with table of materials ready to send for quoting and ordering.
                        </p>
                    </div>
                </div>


                <div class="flex flex-col max-w-md sm:flex-row">
                    <div class="mb-4 mr-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-indigo-50">
                            <svg class="w-8 h-8 text-deep-purple-accent-400 sm:w-10 sm:h-10" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <h6 class="mb-3 text-xl font-bold leading-5">
                            The simplest interface your grandma could use
                        </h6>
                        <p class="text-sm text-gray-900">
                            Software shouldn't have a learning curve and 50 buttons. Just bare minimum what you need to get the job done.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-5 mt-5 mx-auto text-center">
        <h2 class="max-w-4xl font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
            Auto Import BOM lists from:
        </h2>
    </div>
    <div class="max-w-5xl mx-auto">
        <CadLogosBanner/>
    </div>



    <div class="px-4 pb-16 pt-28 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                See what cutting your waste is worth
            </h2>
        </div>

        <div>
            <div>
                <!-- Pricing slider -->
                <SavingsCalculator
                    :inputs="calculatorInputs"
                    :plan="plan"
                />
            </div>
        </div>
    </div>


    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                A4 Printer friendly cutting lists
            </h2>
        </div>
        <img
            src="/nesting.png"
            class="mx-auto"
            style="width:100%; max-width:700px; box-shadow: -6px -6px 6px rgba(0,0,0,0.1), 6px -6px 6px rgba(0,0,0,0.1);"
        >
    </div>

    <!--
        "Example nesting 200PFC across 2 projects" was here: the real algorithm run live, with its
        checks. It lives on its own page now, /try-nesting, which is where the band above sends anyone
        who wants to see the number proved rather than asserted. Nothing was lost but the scroll - and
        the nesting run the landing page was doing on every single hit to draw it.
    -->
    <div class="px-4 py-16 mx-auto sm:max-w-7xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-7xl mb-10 md:mx-auto sm:text-center lg:max-w-7xl md:mb-12">
            <h2 id="pricing" class="max-w-7xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                Start reducing annual spend on steel...
            </h2>
        </div>
        <div class="grid max-w-md gap-10 row-gap-5 sm:row-gap-10 lg:max-w-screen-md lg:grid-cols-2 sm:mx-auto">
            <div class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm">
                <div class="mb-6">
                    <div class="flex items-center justify-between pb-6 mb-6 border-b">
                        <div>
                            <p class="text-sm font-bold tracking-wider uppercase">
                                {{trialLabel}} Trial
                            </p>
                            <p class="text-5xl font-extrabold">Free</p>
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 font-bold tracking-wide">Features</p>
                        <ul class="space-y-2">
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited import sources</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited staff</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited nesting</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">White glove support</p>
                            </li>
                        </ul>
                    </div>
                </div>
                <div>
                    <Link
                        v-if="loginAvailable"
                        :href="route('register')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 bg-green-600 rounded shadow-md hover:bg-green-700 focus:shadow-outline focus:outline-none"
                    >
                        Start {{trialLabel}} Free Trial
                    </Link>
                    <Link
                        v-else
                        :href="route('guest.onboarding')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 bg-green-600 rounded shadow-md hover:bg-green-700 focus:shadow-outline focus:outline-none"
                    >
                        Start {{trialLabel}} Free Trial
                    </Link>
                </div>
            </div>
            <div class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm">
                <div class="mb-6">
                    <div class="flex items-center justify-between pb-6 mb-6 border-b">
                        <!-- Multi year -->
                        <div v-if="whichPlan === 'MULTI_YEAR'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                {{savings_period_years}} year{{savings_period_years > 1 ? 's' : ''}} licence
                            </p>
                            <p class="text-5xl font-extrabold">
                                A${{ fullPriceMultiYear.toLocaleString('en-US') }}<span class="font-light text-lg"> (ex GST)</span>
                            </p>
                        </div>

                        <!-- Annual plan -->
                        <div v-if="whichPlan === 'ANNUAL'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                Unlimited plan
                            </p>

                            <div v-if="firstYearDiscount > 0" class="mt-4 flex items-baseline justify-start">
                                <p class="text-3xl font-extrabold">
                                    <s class="font-medium">${{ fullPriceAnnual.toLocaleString('en-US') }}</s><span class="font-bold text-xl">/year</span><span class="font-light text-lg"> (ex GST)</span>
                                </p>
                            </div>
                            <p class="text-5xl font-extrabold">
                                A${{ (fullPriceAnnual*((100-firstYearDiscount)/100)).toLocaleString('en-US') }}<span class="text-xl">/year</span><span class="font-light text-lg"> (ex GST)</span>
                            </p>
                        </div>

                        <!-- Monthly plan -->
                        <div v-if="whichPlan === 'MONTHLY'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                Unlimited plan
                            </p>

                            <div v-if="firstYearDiscount > 0" class="mt-4 flex items-baseline justify-start">
                                <p class="text-3xl font-extrabold">
                                    <s class="font-medium">${{ fullPriceMonthly.toLocaleString('en-US') }}</s><span class="font-bold text-xl">/month</span><span class="font-light text-lg"> (ex GST)</span>
                                </p>
                            </div>
                            <p class="text-5xl font-extrabold">
                                A${{ (fullPriceMonthly*((100-firstYearDiscount)/100)).toLocaleString('en-US') }}<span class="text-xl">/month</span><span class="font-light text-lg"> (ex GST)</span>
                            </p>
                        </div>

                        <!-- Weekly plan -->
                        <div v-if="whichPlan === 'WEEKLY'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                Unlimited plan
                            </p>

                            <div v-if="firstYearDiscount > 0" class="mt-4 flex items-baseline justify-start">
                                <p class="text-3xl font-extrabold">
                                    <s class="font-medium">${{ fullPriceWeekly.toLocaleString('en-US') }}</s><span class="font-bold text-xl">/week</span><span class="font-light text-lg"> (ex GST)</span>
                                </p>
                            </div>
                            <p class="text-5xl font-extrabold">
                                A${{ (fullPriceWeekly*((100-firstYearDiscount)/100)).toLocaleString('en-US') }}<span class="text-xl">/week</span><span class="font-light text-lg"> (ex GST)</span>
                            </p>
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 font-bold tracking-wide">Features</p>
                        <ul class="space-y-2">
                            <li v-if="firstYearDiscount > 0" class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-bold text-green-500">{{firstYearDiscount}}% first year discount</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited import sources</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited staff</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">Unlimited nesting</p>
                            </li>
                            <li class="flex items-center">
                                <div class="mr-2">
                                    <svg class="w-4 h-4 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2">
                                        <polyline fill="none" stroke="currentColor" points="6,12 10,16 18,8"></polyline>
                                        <circle cx="12" cy="12" fill="none" r="11" stroke="currentColor"></circle>
                                    </svg>
                                </div>
                                <p class="font-medium text-gray-800">White glove support</p>
                            </li>
                        </ul>
                    </div>
                </div>
                <div v-if="user">
                    <a
                        href="mailto:mark@steelnesting.com.au?subject=Request%20an%20invoice%20for%20SteelNesting.com.au"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 bg-deep-purple-accent-400 rounded shadow-md hover:bg-deep-purple-accent-500 focus:shadow-outline focus:outline-none"
                    >
                        Request invoice by email
                    </a>
                </div>
            </div>
        </div>
    </div>

</template>

<style scoped>
    s, strike{text-decoration:none;position:relative;}
    s::before, strike::before {
        top: 50%; /*tweak this to adjust the vertical position if it's off a bit due to your font family */
        background:red; /*this is the color of the line*/
        opacity:.7;
        content: '';
        width: 110%;
        position: absolute;
        height:.1em;
        border-radius:.1em;
        left: -5%;
        white-space:nowrap;
        display: block;
        transform: rotate(-15deg);
    }
    s.straight::before, strike.straight::before{transform: rotate(0deg);left:-1%;width:102%;}
</style>
