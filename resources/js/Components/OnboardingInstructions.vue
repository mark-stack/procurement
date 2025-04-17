<script setup>
    //General Imports
    //...

    //Component Imports
    import {usePage} from "@inertiajs/vue3";

    //Props
    // const props = defineProps({
    //     xxx: Object,
    // });

    //Form
    //...

    //Shared data
    //...

    //Variables


    const adminEmail = usePage().props.adminEmail;

    //Shared Methods
    //...

    //Methods
    //...

</script>

<template>


    <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
        <section class="bg-white dark:bg-gray-900 rounded-xl">
            <div class="px-6 pt-8 pb-8 mx-auto">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                    Welcome
                </h1>
                <p class="mt-5">
                    To customise the software to how your company works, we need some example docs to help us calibrate it for you:
                </p>
                <div class="mt-3">
                    <!-- Quote template -->
                    <div class="mt-3">
                        <h3 class="font-semibold">Examples of your bill-of-materials:</h3>
                        <ul>
                            <li>- Excel exported from CAD like Tekla, Inventor, Advance Steel etc.</li>
                            <li>- Manually created Excel spreadsheets. </li>
                            <li>- You might have multiple templates for different staff members. That's ok.</li>
                        </ul>
                        <p class="mt-3">
                            We configure the software to auto detect all your templates to then extract all the materials.
                            We can add more later as needed.
                        </p>
                    </div>

                    <!-- Supplier list -->
                    <div class="mt-3">
                        <h3 class="font-semibold">Your suppliers list:</h3>
                        This may come from Xero export, a spreadsheet, or simply brain dump a list suppliers in the email.
                    </div>
                </div>
                <p class="mt-5">
                    Send whatever you come up with to:
                    <br>
                    <a
                        :href="'mailto:'+adminEmail+'?subject=BOM%20templates%20and%20suppliers%20list'"
                        target="_blank"
                        class="underline text-blue-500 text-lg"
                    >
                        {{adminEmail}}
                    </a>
                </p>
                <p class="mt-5">
                    We take maximum 2 business days to process, then we'll notify you by email that everything is ready.
                </p>
            </div>
        </section>
    </div>
</template>

<style scoped>

</style>
