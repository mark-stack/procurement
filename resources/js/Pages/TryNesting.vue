<script setup>
    //General Imports
    import {Head, Link, useForm, usePage} from '@inertiajs/vue3';
    import {ref} from "vue";
    import LandingNav from "@/Components/Nav/LandingNav.vue";
    import Nesting from "@/Components/Nesting/Nesting.vue";

    //Component Imports
    //

    //Props
    const props = defineProps({
        sampleNestingData: Object,
    });

    //Form


    //Shared data
    const user = usePage().props.auth.user;

    //Variables
    //
    //Shared Methods
    //...

    //Methods
    //
</script>

<template>
    <Head title="Steel Nesting" />

    <!-- Nav -->
    <LandingNav/>

    <div class="px-4 py-16 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8 lg:py-20">
        <div class="max-w-5xl mb-10 md:mx-auto sm:text-center md:mb-12">
            <h2 class="max-w-5xl mb-6 font-sans text-3xl font-bold leading-none tracking-tight text-gray-900 sm:text-4xl md:mx-auto">
                Example nesting 200PFC across 3 projects
            </h2>
        </div>



        <template v-if="sampleNestingData">
            <div class="px-4 py-8 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8">
                <div class="grid gap-3 grid-cols-2">

                    <div
                        v-for="check in sampleNestingData.checks"
                        class="flex gap-x-2"
                    >
                        <i
                            :class="check.result ? 'fa-solid fa-check bg-teal-accent-400' : 'fa-solid fa-xmark bg-orange-400'"
                            class="flex items-center justify-center text-lg rounded-full w-6 h-6"
                        ></i>
                        <span class="font-semibold">{{check.description}}{{check.number !== null ? (' ('+check.number+check.suffix+')') : ''}}</span>
                    </div>
                </div>
            </div>
            <Nesting
                :width="900"
                :projectsReadyForBatching="sampleNestingData.projectsReadyForBatching"
                :lettersProjectArray="sampleNestingData.lettersProjectArray"
                :usage="sampleNestingData.usage"
                :piecesGroupedBySupplierGroup="sampleNestingData.piecesGroupedBySupplierGroup"
                :currentSupplierGroup="Object.keys(sampleNestingData.piecesGroupedBySupplierGroup.assigned)[0]"
            />
        </template>
    </div>
</template>

<style scoped>

</style>
