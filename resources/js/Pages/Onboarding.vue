<script setup>
    //General Imports
    import {Head} from '@inertiajs/vue3';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import OnboardingInstructions from "@/Components/OnboardingInstructions.vue";

    //Props
    const props = defineProps({
        projects: Object,
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
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <OnboardingInstructions class="mt-12"/>
    </AuthenticatedLayout>
</template>
