<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        formCalculator: Object,
        years: Number,
        fullPriceMultiYear: Number,
        fullPriceAnnual: Number,
        fullPriceMonthly: Number,
        fullPriceWeekly: Number,
        whichPlan: String,
        firstYearDiscount: Number,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    //...

    //Methods
    function calculate(){
        let annualSpend = props.formCalculator.annualSpendMillions * 1000000;
        let wasteFraction = props.formCalculator.wastePct/100; //e.g 5% = 0.05
        let annualWaste = annualSpend * wasteFraction;
        let annualScrapRefund = annualWaste * (props.formCalculator.scrapRefundPct/100)

        return props.years * (annualWaste - annualScrapRefund);
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

    function displayTerm(){
        let displayTerm = "";

        //1 year: "per year"
        if(props.years === 1){
            displayTerm = "per year";
        }
        //Multiple years: "over 3 years"
        if(props.years > 1){
            displayTerm = "over " + props.years + " years";
        }

        return displayTerm;
    }

    function roiDisplay(){
        let priceFirstYearAfterDiscount = null;
        let priceAfterFirstYearFullPrice = null;
        let priceOverSavingsPeriod = null;

        if(props.whichPlan === "MULTI_YEAR"){
            priceOverSavingsPeriod = props.fullPriceMultiYear;
        }
        if(props.whichPlan === "ANNUAL"){
            priceFirstYearAfterDiscount = props.fullPriceAnnual*((100-props.firstYearDiscount)/100);
            priceAfterFirstYearFullPrice = props.fullPriceAnnual;
            priceOverSavingsPeriod = priceFirstYearAfterDiscount + ((props.years - 1) * priceAfterFirstYearFullPrice);
        }
        if(props.whichPlan === "MONTHLY"){
            priceFirstYearAfterDiscount = 12*props.fullPriceMonthly*((100-props.firstYearDiscount)/100);
            priceAfterFirstYearFullPrice = 12*props.fullPriceMonthly;
            priceOverSavingsPeriod = priceFirstYearAfterDiscount + ((props.years - 1) * priceAfterFirstYearFullPrice);
        }
        if(props.whichPlan === "WEEKLY"){
            priceFirstYearAfterDiscount = 52*props.fullPriceWeekly*((100-props.firstYearDiscount)/100);
            priceAfterFirstYearFullPrice = 52*props.fullPriceWeekly;
            priceOverSavingsPeriod = priceFirstYearAfterDiscount + ((props.years - 1) * priceAfterFirstYearFullPrice);
        }

        //"1:15"
        let ratio = (calculate()/priceOverSavingsPeriod).toFixed(0);

        return "1:"+ratio;
    }
</script>

<template>
    <!-- Pricing slider -->
    <div>
        <!-- Material spend ($m) -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                v-model="formCalculator.annualSpendMillions"
                type="range"
                min="0.5"
                max="6"
                step="0.1"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <div class="mt-2 text-gray-800 font-semibold">
                Steel sections spend: ${{formCalculator.annualSpendMillions}}m/year
            </div>
        </div>

        <!-- waste reduction -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                v-model="formCalculator.wastePct"
                type="range"
                min="3"
                max="7"
                step="0.5"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <div class="mt-2 text-gray-800 font-semibold">
                Steel efficiency increase: {{formCalculator.wastePct}}%
            </div>
        </div>

        <!-- scrap rate -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                v-model="formCalculator.scrapRefundPct"
                type="range"
                min="10"
                max="16"
                step="1"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <div class="mt-2 text-gray-800 font-semibold">
                Scrap refund rate: {{formCalculator.scrapRefundPct}}%
            </div>
        </div>
        <div class="flex flex-col items-center p-4 ">
<!--            <span class="block"><b>${{ beforeFees() }}</b> - <b>${{(price/1000).toFixed(1)}}K</b> for {{years}} year software term</span>-->
            <span class="block mt-3 text-4xl text-deep-purple-accent-400">Save <b>${{ beforeFees() }}</b> {{ displayTerm() }}</span>
            <div class="flex mt-2">
                <span class="text-emerald-500 font-bold">{{ roiDisplay() }} ROI</span> <span class="ml-1 text-emerald-500"> from the software</span>
            </div>

        </div>
    </div>
</template>
