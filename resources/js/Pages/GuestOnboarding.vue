<script setup>
//General Imports
import {Head} from '@inertiajs/vue3';

//Component Imports
import OnboardingInstructions from "@/Components/OnboardingInstructions.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";

//Props
const props = defineProps({
    //
});

//Form
//...

//Shared data
//...

//Variables


//Shared Methods
//...

//Methods
//...
</script>

<template>
    <Head title="Onboarding" />

    <GuestLayout wider="true">
        <OnboardingInstructions/>
    </GuestLayout>
</template>
