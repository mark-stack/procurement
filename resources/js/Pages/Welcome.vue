<script setup>
    //General Imports
    import {Head, Link, useForm, usePage} from '@inertiajs/vue3';
    import {computed, ref} from "vue";

    //Component Imports
    import SavingsCalculator from "@/Components/SavingsCalculator.vue";
    import LandingNav from "@/Components/Nav/LandingNav.vue";
    import Nesting from "@/Components/Nesting/Nesting.vue";
    import CadLogosBanner from "@/Components/CadLogosBanner.vue";

    //Props
    const props = defineProps({
        sampleNestingData: Object,
    });

    //Form
    const formCalculator = useForm({
        annualSpendMillions:1.5,
        wastePct:5,
        scrapRefundPct:13,
    });

    //Shared data
    const user = usePage().props.auth.user;
    const loginAvailable = computed(() => usePage().props.loginAvailable);

    //Variables
    const trial_months = 2;
    const savings_period_years = 3;
    const fullPriceMultiYear = 10000;
    const fullPriceAnnual = 2900;
    const fullPriceMonthly = 300;
    const fullPriceWeekly = 49;
    const firstYearDiscount = 0;
    const whichPlan = "MULTI_YEAR"; //"MONTHLY","ANNUAL", "WEEKLY", "MULTI_YEAR"


    //Shared Methods
    //...

    //Methods
    function calculate(){
        let annualSpend = formCalculator.annualSpendMillions * 1000000;
        let wasteFraction = formCalculator.wastePct/100; //e.g 5% = 0.05
        let annualWaste = annualSpend * wasteFraction;
        let annualScrapRefund = annualWaste * (formCalculator.scrapRefundPct/100)

        return savings_period_years * (annualWaste - annualScrapRefund);
    }

    function beforeFees(){
        let sum = calculate();
        let display = "";

        //Thousands
        if(sum < 1000000){
            display = (sum/1000).toFixed(0) + "K";
        }
        //Millions
        else{
            display = (sum/1000000).toFixed(1) + "M";
        }

        return display;
    }

    function afterFees(){
        let sum = calculate() - 10000;
        let display = "";

        //Thousands
        if(sum < 1000000){
            display = (sum/1000).toFixed(0) + "K";
        }
        //Millions
        else{
            display = (sum/1000000).toFixed(1) + "M";
        }

        return display;
    }
</script>

<template>
    <Head title="Steel Nesting" />

    <!-- Nav -->
    <LandingNav/>

    <!-- Hero -->
    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="flex flex-col items-center justify-between lg:flex-row">
            <div class="mb-10 lg:max-w-lg lg:pr-5 lg:mb-0">
                <div class="max-w-xl mb-16">
<!--                    <div>-->
<!--                        <p class="inline-block px-3 py-px mb-4 text-xs font-semibold tracking-wider text-teal-900 uppercase rounded-full bg-teal-accent-400">-->
<!--                            New in 2025-->
<!--                        </p>-->
<!--                    </div>-->
                    <h2 class="max-w-lg mb-6 font-sans text-5xl font-bold tracking-tight text-gray-900 sm:leading-none">
                        Maximum steel nesting efficiency could save you
                        <span v-if="savings_period_years === 1" class="inline-block text-deep-purple-accent-400">${{beforeFees()}} annually</span>
                        <span v-else class="inline-block text-orange-900">${{beforeFees()}} in <u>waste</u></span>
                    </h2>
<!--                    <p class="text-base text-gray-700 md:text-lg">-->
<!--                        <b>How to reach maximum efficiency?</b>-->
<!--                        <br>-->
<!--                        - Cross-project nesting.-->
<!--                        <br>-->
<!--                        - Optimised offcut placement.-->
<!--                        <br>-->
<!--                        - Algorithm that finds best of 1000 iterations.-->
<!--                    </p>-->
                </div>
                <div class="flex flex-col items-center md:flex-row">
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-3 font-medium tracking-wide text-white transition duration-200 rounded shadow-md md:w-auto md:mr-4 md:mb-0 bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                    >
                        <span class="mr-3">{{trial_months}} Month{{trial_months > 1 ? 's' : ''}} FREE TRIAL</span>
                    </Link>
                </div>
            </div>
            <div class="lg:w-1/2 pt-10">
                <div class="relative">

                    <img
                        src="https://framerusercontent.com/images/7c1DajeRRBT7lYlrLnWW397U.png"
                        style="width:120px"
                        class="mx-auto"
                    >
                    <!-- Pricing slider -->
                    <SavingsCalculator
                        :formCalculator="formCalculator"
                        :years="savings_period_years"
                        :fullPriceMultiYear="fullPriceMultiYear"
                        :fullPriceAnnual="fullPriceAnnual"
                        :fullPriceMonthly="fullPriceMonthly"
                        :fullPriceWeekly="fullPriceWeekly"
                        :whichPlan="whichPlan"
                        :firstYearDiscount="firstYearDiscount"
                    />
                </div>
            </div>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="mb-16 md:mx-auto sm:text-center">
            <h2 class="max-w-3xl mb-6 font-sans text-5xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
                How to reach maximum steel nesting efficiency?
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

    <div class="max-w-5xl mx-auto">
        <CadLogosBanner/>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="mb-16 md:mx-auto sm:text-center">
            <h2 class="max-w-4xl mb-6 font-sans text-5xl font-bold leading-none tracking-tight text-gray-900 md:mx-auto">
                An <u>unrivaled</u> nesting software for Australian fabricators would require:
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
                            Simple order tracking
                        </h6>
                        <p class="text-sm text-gray-900">
                            Track quoting to delivery of nested batches, with a clear Kanban view of active orders
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





    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                See the difference even a 3% yield increase can make...
            </h2>
        </div>

        <div>
            <div>
                <!-- Pricing slider -->
                <SavingsCalculator
                    :formCalculator="formCalculator"
                    :years="savings_period_years"
                    :fullPriceMultiYear="fullPriceMultiYear"
                    :fullPriceAnnual="fullPriceAnnual"
                    :fullPriceMonthly="fullPriceMonthly"
                    :fullPriceWeekly="fullPriceWeekly"
                    :whichPlan="whichPlan"
                    :firstYearDiscount="firstYearDiscount"
                />
            </div>
        </div>
    </div>


    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                Beautiful nesting...
            </h2>
        </div>
        <img src="/nesting.png" class="mx-auto" style="width:100%; max-width:700px">
    </div>

<!--    <div-->
<!--        v-if="sampleNestingData"-->
<!--        id="nesting"-->
<!--        class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20"-->
<!--    >-->
<!--        <div class="max-w-7xl mb-10 md:mx-auto md:mb-12">-->
<!--            <h2 class="text-center max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">-->
<!--                Example nesting 200PFC across 2 projects-->
<!--            </h2>-->
<!--            <div class="mx-auto px-4 py-8 mx-auto max-w-5xl">-->
<!--                <div class="grid gap-3 grid-cols-2">-->
<!--                    <div-->
<!--                        v-for="check in sampleNestingData.checks"-->
<!--                        class="flex gap-x-2"-->
<!--                    >-->
<!--                        <i-->
<!--                            :class="check.result ? 'fa-solid fa-check bg-teal-accent-400' : 'fa-solid fa-xmark bg-orange-400'"-->
<!--                            class="flex items-center justify-center text-lg rounded-full w-6 h-6"-->
<!--                        ></i>-->
<!--                        <span class="font-semibold text-sm">{{check.description}}{{check.number !== null ? (' ('+check.number+(check.suffix ?? '')+')') : ''}}</span>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
<!--            <Nesting-->
<!--                :width="900"-->
<!--                :projectsReadyForBatching="sampleNestingData.projectsReadyForBatching"-->
<!--                :lettersProjectArray="sampleNestingData.lettersProjectArray"-->
<!--                :usage="sampleNestingData.usage"-->
<!--                :piecesGroupedBySupplierGroup="sampleNestingData.piecesGroupedBySupplierGroup"-->
<!--                :currentSupplierGroup="Object.keys(sampleNestingData.piecesGroupedBySupplierGroup.assigned)[0]"-->
<!--            />-->
<!--        </div>-->
<!--    </div>-->

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
                                {{trial_months}} Month{{trial_months > 1 ? 's' : ''}} Trial
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
                        Start {{trial_months}} Month{{trial_months > 1 ? 's' : ''}} Free Trial
                    </Link>
                    <Link
                        v-else
                        :href="route('guest.onboarding')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 bg-green-600 rounded shadow-md hover:bg-green-700 focus:shadow-outline focus:outline-none"
                    >
                        Start {{trial_months}} Month{{trial_months > 1 ? 's' : ''}} Free Trial
                    </Link>
                </div>
            </div>
            <div class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm">
                <div class="mb-6">
                    <div class="flex items-center justify-between pb-6 mb-6 border-b">
                        <!-- Multi year -->
                        <div v-if="whichPlan === 'MULTI_YEAR'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                {{savings_period_years}} year licence
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
