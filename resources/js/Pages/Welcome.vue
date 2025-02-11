<script setup>
    //General Imports
    import {Head, Link, useForm, usePage} from '@inertiajs/vue3';
    import SavingsCalculator from "@/Components/SavingsCalculator.vue";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        //xxx: Object,
    });

    //Form
    const formCalculator = useForm({
        annualSpendMillions:2.0,
        wastePct:5,
        scrapRefundPct:13,
    });

    //Shared data
    const user = usePage().props.auth.user;

    //Variables
    const trial_months = 2;
    const savings_period_years = 3;
    const fullPriceAnnual = 4900;
    const fullPriceMonthly = 490;
    const firstYearDiscount = 70;
    const whichPlan = "ANNUAL";

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
    <Head title="Steel Minima" />

    <div class="bg-gray-900">
        <div class="px-4 py-5 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8">
            <div class="relative flex grid items-center grid-cols-2 lg:grid-cols-3">
                <ul class="flex items-center hidden space-x-8 lg:flex">
                    <li><a href="/#pricing" aria-label="Product pricing" title="Product pricing" class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400">Pricing</a></li>
                </ul>
                <a href="/" aria-label="Company" title="Company" class="inline-flex items-center lg:mx-auto">
                    <svg class="w-8 text-teal-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">
                        <rect x="3" y="1" width="7" height="12"></rect>
                        <rect x="3" y="17" width="7" height="6"></rect>
                        <rect x="14" y="1" width="7" height="6"></rect>
                        <rect x="14" y="11" width="7" height="12"></rect>
                    </svg>
                    <span class="ml-2 text-xl font-bold tracking-wide text-gray-100 uppercase">Steel minima</span>
                </a>
                <ul class="flex items-center hidden ml-auto space-x-8 lg:flex">
                    <li>
                        <Link
                            v-if="user"
                            :href="route('dashboard')"
                            aria-label="Dashboard"
                            title="Dashboard"
                            class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                        >
                            Dashboard
                        </Link>
                        <Link
                            v-else
                            :href="route('login')"
                            aria-label="Sign in"
                            title="Sign in"
                            class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                        >
                            Sign in
                        </Link>
                    </li>
                    <li>
                        <Link
                            v-if="!user"
                            :href="route('register')"
                            class="inline-flex items-center justify-center h-12 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                            aria-label="Sign up"
                            title="Sign up"
                        >
                            Sign up
                        </Link>
                    </li>
                </ul>
                <!-- Mobile menu -->
                <div class="ml-auto lg:hidden">
                    <button aria-label="Open Menu" title="Open Menu" class="p-2 -mr-1 transition duration-200 rounded focus:outline-none focus:shadow-outline">
                        <svg class="w-5 text-gray-600" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M23,13H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,13,23,13z"></path>
                            <path fill="currentColor" d="M23,6H1C0.4,6,0,5.6,0,5s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,6,23,6z"></path>
                            <path fill="currentColor" d="M23,20H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,20,23,20z"></path>
                        </svg>
                    </button>
                    <!-- Mobile menu dropdown
                    <div class="absolute top-0 left-0 w-full">
                      <div class="p-5 bg-white border rounded shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                          <div>
                            <a href="/" aria-label="Company" title="Company" class="inline-flex items-center">
                              <svg class="w-8 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">
                                <rect x="3" y="1" width="7" height="12"></rect>
                                <rect x="3" y="17" width="7" height="6"></rect>
                                <rect x="14" y="1" width="7" height="6"></rect>
                                <rect x="14" y="11" width="7" height="12"></rect>
                              </svg>
                              <span class="ml-2 text-xl font-bold tracking-wide text-gray-800 uppercase">Company</span>
                            </a>
                          </div>
                          <div>
                            <button aria-label="Close Menu" title="Close Menu" class="p-2 -mt-2 -mr-2 transition duration-200 rounded hover:bg-gray-200 focus:bg-gray-200 focus:outline-none focus:shadow-outline">
                              <svg class="w-5 text-gray-600" viewBox="0 0 24 24">
                                <path
                                  fill="currentColor"
                                  d="M19.7,4.3c-0.4-0.4-1-0.4-1.4,0L12,10.6L5.7,4.3c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l6.3,6.3l-6.3,6.3 c-0.4,0.4-0.4,1,0,1.4C4.5,19.9,4.7,20,5,20s0.5-0.1,0.7-0.3l6.3-6.3l6.3,6.3c0.2,0.2,0.5,0.3,0.7,0.3s0.5-0.1,0.7-0.3 c0.4-0.4,0.4-1,0-1.4L13.4,12l6.3-6.3C20.1,5.3,20.1,4.7,19.7,4.3z"
                                ></path>
                              </svg>
                            </button>
                          </div>
                        </div>
                        <nav>
                          <ul class="space-y-4">
                            <li><a href="/" aria-label="Our product" title="Our product" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Product</a></li>
                            <li><a href="/" aria-label="Our product" title="Our product" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Features</a></li>
                            <li><a href="/" aria-label="Product pricing" title="Product pricing" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Pricing</a></li>
                            <li><a href="/" aria-label="Sign in" title="Sign in" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Sign in</a></li>
                            <li>
                              <a
                                href="/"
                                class="inline-flex items-center justify-center w-full h-12 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                                aria-label="Sign up"
                                title="Sign up"
                              >
                                Sign up
                              </a>
                            </li>
                          </ul>
                        </nav>
                      </div>
                    </div>
                    -->
                </div>
            </div>
        </div>
    </div>

    <!-- Hero -->
    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="flex flex-col items-center justify-between lg:flex-row">
            <div class="mb-10 lg:max-w-lg lg:pr-5 lg:mb-0">
                <div class="max-w-xl mb-6">
                    <div>
                        <p class="inline-block px-3 py-px mb-4 text-xs font-semibold tracking-wider text-teal-900 uppercase rounded-full bg-teal-accent-400">
                            New in 2025
                        </p>
                    </div>
                    <h2 class="max-w-lg mb-6 font-sans text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl sm:leading-none">
                        Prevent
                        <span class="inline-block text-orange-900">${{beforeFees()}} of <u>waste</u></span>
                        in steel fabrication procurement.
                    </h2>
                    <p class="text-base text-gray-700 md:text-lg">
                        <b>Centralised procurement is the key to:</b>
                        <br>
                        - <span class="font-semibold text-deep-purple-accent-400">Far less waste</span> from cross-project nesting.
<!--                        <br>-->
<!--                        - <span class="font-semibold text-deep-purple-accent-400">More bulk discounts</span> via cross-project batched orders.-->
<!--                        <br>-->
<!--                        - <span class="font-semibold text-deep-purple-accent-400">More supplier discounts</span> aligned to your material lists.-->
                        <br>
                        - <span class="font-semibold text-deep-purple-accent-400">Less delivery fees</span> from cross-project batched orders.
                        <br>
                        - <span class="font-semibold text-deep-purple-accent-400">Less over-ordering</span> from reusing tracked offcuts.
                        <br>
                        - <span class="font-semibold text-deep-purple-accent-400">Less ordering errors</span> from automated material list checks.
                    </p>
                </div>
                <div class="flex flex-col items-center md:flex-row">
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-3 font-medium tracking-wide text-white transition duration-200 rounded shadow-md md:w-auto md:mr-4 md:mb-0 bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                    >
                        <span class="mr-3">{{trial_months}} Months FREE TRIAL</span>
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
                        :fullPriceAnnual="fullPriceAnnual"
                        :fullPriceMonthly="fullPriceMonthly"
                        :whichPlan="whichPlan"
                        :firstYearDiscount="firstYearDiscount"
                    />
                </div>
            </div>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto max-w-5xl md:px-24 lg:px-8 lg:py-20">
        <div class="mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                The 3 pillars of procurement cost reduction
            </h2>
        </div>
        <div class="grid max-w-md gap-8 row-gap-10 sm:mx-auto lg:max-w-full lg:grid-cols-3">
            <div class="flex flex-col sm:flex-row">
                <div>
                    <h6 class="mb-2 font-bold leading-5 text-2xl">Reduce waste</h6>
                    <ul class="mb-4 -ml-1 space-y-2 mt-4">
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Efficient cross-project nesting
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Prevent Incorrect ordering
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Prevent Over-ordering
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Prevent Re-ordering
                        </li>
                    </ul>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row">
                <div>
                    <h6 class="mb-2 font-bold leading-5 text-2xl">Reduce prices</h6>
                    <ul class="mb-4 -ml-1 space-y-2 mt-4">
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Bulk discounts
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Promotional discounts
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Seasonal lower prices
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Early payment discounts
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Material substitution
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Informational bargaining power
                        </li>
                    </ul>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row">
                <div>
                    <h6 class="mb-2 font-bold leading-5 text-2xl">Reduce deliveries</h6>
                    <ul class="mb-4 -ml-1 space-y-2 mt-4">
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Prevent Under-ordering
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Prevent Unnecessary deliveries
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                              <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                              </svg>
                            </p>
                            Prevent Excessive priced fast deliveries
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Prevent Excessive delivery distance
                        </li>
                        <li class="flex items-start">
                            <p class="mr-1">
                                <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                    <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                                </svg>
                            </p>
                            Prevent Excessive delivery fees
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                See the difference even 3% can make
            </h2>
        </div>

        <div>
            <div>
                <!-- Pricing slider -->
                <SavingsCalculator
                    :formCalculator="formCalculator"
                    :years="savings_period_years"
                    :fullPriceAnnual="fullPriceAnnual"
                    :fullPriceMonthly="fullPriceMonthly"
                    :whichPlan="whichPlan"
                    :firstYearDiscount="firstYearDiscount"
                />
            </div>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                How does an online software achieve this?
            </h2>
        </div>
        <div class="grid gap-8 row-gap-0 lg:grid-cols-3">
            <div class="relative text-center">
                <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full bg-indigo-50 sm:w-20 sm:h-20">
                    <svg class="w-12 h-12 text-deep-purple-accent-400 sm:w-16 sm:h-16" stroke="currentColor" viewBox="0 0 52 52">
                        <polygon stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                    </svg>
                </div>
                <h6 class="mb-2 text-2xl font-extrabold">
                    1) Import Material List
                </h6>
                <ul class="mb-4 -ml-1 space-y-2 mt-4 text-left">
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Import materials from any quote template
                    </li>
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Compares against a database of 10,000+ items
                    </li>
<!--                    <li class="flex items-start">-->
<!--                        <p class="mr-1">-->
<!--                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">-->
<!--                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>-->
<!--                            </svg>-->
<!--                        </p>-->
<!--                        Custom items easily added (once and done)-->
<!--                    </li>-->
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Automatic sense check based on 20 criteria
                    </li>
                </ul>
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
                <h6 class="mb-2 text-2xl font-extrabold">
                    2) Automatic Nesting
                </h6>
                <ul class="mb-4 -ml-1 space-y-2 mt-4 text-left">
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Cross-project nesting for less waste and higher likelihood of bulk discounts
                    </li>
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Auto group materials together based on your suppliers for easier RFQ preparation.
                    </li>
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Reminders for managing RFQs based on project deadlines
                    </li>
                </ul>
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
                <h6 class="mb-2 text-2xl font-extrabold">
                    3) Automatic Order batching
                </h6>
                <ul class="mb-4 -ml-1 space-y-2 mt-4 text-left">
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Cross-project batched ordering
                    </li>
<!--                    <li class="flex items-start">-->
<!--                        <p class="mr-1">-->
<!--                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">-->
<!--                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>-->
<!--                            </svg>-->
<!--                        </p>-->
<!--                        Supplier discounts based on exact product matches-->
<!--                    </li>-->
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Multiple Purchase order numbers handled
                    </li>
                    <li class="flex items-start">
                        <p class="mr-1">
                            <svg class="w-5 h-5 mt-px text-deep-purple-accent-400" stroke="currentColor" viewBox="0 0 52 52">
                                <polygon stroke-width="4" stroke-linecap="round" stroke-linejoin="round" fill="none" points="29 13 14 29 25 29 23 39 38 23 27 23"></polygon>
                            </svg>
                        </p>
                        Reminders for managing orders based on project deadlines. No expensive expedited orders because something was forgotten.
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-xl mb-10 md:mx-auto sm:text-center lg:max-w-2xl md:mb-12">
            <h2 id="pricing" class="max-w-lg mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                Pricing
            </h2>
        </div>
        <div class="grid max-w-md gap-10 row-gap-5 sm:row-gap-10 lg:max-w-screen-md lg:grid-cols-2 sm:mx-auto">
            <div class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm">
                <div class="mb-6">
                    <div class="flex items-center justify-between pb-6 mb-6 border-b">
                        <div>
                            <p class="text-sm font-bold tracking-wider uppercase">
                                {{trial_months}} Months Trial
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
                                <p class="font-medium text-gray-800">Unlimited import templates</p>
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
                                <p class="font-medium text-gray-800">Unlimited projects</p>
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
                    <Link :href="route('register')" class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 bg-gray-800 rounded shadow-md hover:bg-gray-900 focus:shadow-outline focus:outline-none">
                        Sign up
                    </Link>
                </div>
            </div>
            <div class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm">
                <div class="mb-6">
                    <div class="flex items-center justify-between pb-6 mb-6 border-b">
                        <!-- Annual plan -->
                        <div v-if="whichPlan === 'ANNUAL'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                Unlimited plan
                            </p>

                            <div class="mt-4 flex items-baseline justify-start">
                                <p class="text-3xl font-extrabold">
                                    <s class="font-medium">${{ fullPriceAnnual.toLocaleString('en-US') }}</s><span class="font-bold text-xl">/year</span>
                                </p>
                            </div>
                            <p class="text-5xl font-extrabold">A${{ (fullPriceAnnual*((100-firstYearDiscount)/100)).toLocaleString('en-US') }}<span class="text-xl">/year</span></p>
                        </div>

                        <!-- Monthly plan -->
                        <div v-if="whichPlan === 'MONTHLY'">
                            <p class="text-sm font-bold tracking-wider uppercase">
                                Unlimited plan
                            </p>

                            <div v-if="firstYearDiscount > 0" class="mt-4 flex items-baseline justify-start">
                                <p class="text-3xl font-extrabold">
                                    <s class="font-medium">${{ fullPriceMonthly.toLocaleString('en-US') }}</s><span class="font-bold text-xl">/month</span>
                                </p>
                            </div>
                            <p class="text-5xl font-extrabold">A${{ (fullPriceMonthly*((100-firstYearDiscount)/100)).toLocaleString('en-US') }}<span class="text-xl">/month</span></p>
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
                                <p class="font-medium text-gray-800">Unlimited import templates</p>
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
                                <p class="font-medium text-gray-800">Unlimited projects</p>
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
<!--                <div>-->
<!--                    <Link-->
<!--                        :href="route('register')"-->
<!--                        class="inline-flex items-center justify-center w-full h-12 px-6 mb-4 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"-->
<!--                    >-->
<!--                        Get started-->
<!--                    </Link>-->
<!--                </div>-->
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
