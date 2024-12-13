<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        formCalculator: Object,
        years: Number,
        price: Number,
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
        let spend = props.formCalculator.spend * 1000000;
        let wasteFraction = (100 - props.formCalculator.waste)/100; //e.g 3% = 0.97
        //let discountFraction = (100 - props.formCalculator.discounts)/100; //e.g 3% = 0.97
        //let result = props.years * (spend - (spend * wasteFraction * discountFraction));
        return props.years * (spend - (spend * wasteFraction));
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
        //"1:15"
        let ratio = (calculate()/props.price).toFixed(0);

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
                v-model="formCalculator.spend"
                type="range"
                min="1"
                max="8"
                step="0.5"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <div class="mt-2 text-gray-800 font-semibold">
                Material Spend: ${{formCalculator.spend}}m/year
            </div>
        </div>

        <!-- waste reduction -->
        <div class="flex flex-col items-center p-4">
            <!-- Slider -->
            <input
                v-model="formCalculator.waste"
                type="range"
                min="1"
                max="6"
                step="1"
                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <!-- Value Display -->
            <div class="mt-2 text-gray-800 font-semibold">
                Waste reduction: {{formCalculator.waste}}%
            </div>
        </div>

<!--        &lt;!&ndash; discounts &ndash;&gt;-->
<!--        <div class="flex flex-col items-center p-4">-->
<!--            &lt;!&ndash; Slider &ndash;&gt;-->
<!--            <input-->
<!--                v-model="formCalculator.discounts"-->
<!--                type="range"-->
<!--                min="1"-->
<!--                max="4"-->
<!--                step="1"-->
<!--                class="w-full max-w-sm appearance-none bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"-->
<!--            />-->

<!--            &lt;!&ndash; Value Display &ndash;&gt;-->
<!--            <div class="mt-2 text-gray-800 font-semibold">-->
<!--                Discounts: {{formCalculator.discounts}}%-->
<!--            </div>-->
<!--        </div>-->
        <div class="flex flex-col items-center p-4 ">
<!--            <span class="block"><b>${{ beforeFees() }}</b> - <b>${{(price/1000).toFixed(1)}}K</b> for {{years}} year software term</span>-->
            <span class="block mt-3 text-4xl text-deep-purple-accent-400">Save <b>${{ beforeFees() }}</b> {{ displayTerm() }}</span>
            <div class="flex mt-2">
                <span class="text-emerald-500 font-bold">{{ roiDisplay() }} ROI</span> <span class="ml-1 text-emerald-500"> with software</span>
            </div>

        </div>
    </div>
</template>
