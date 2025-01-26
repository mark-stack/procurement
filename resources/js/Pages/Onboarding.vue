<script setup>
    //General Imports
    import {Link, Head, usePage} from '@inertiajs/vue3';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        projects: Object,
    });

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
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <section class="bg-white dark:bg-gray-900 rounded-xl">
                    <div class="px-6 pt-8 pb-8 mx-auto">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            Welcome
                        </h1>
                        <p class="mt-5">
                            To get going, we need to shape the software to your construction company!
                        </p>
                        <div class="mt-3">
                            This means providing some docs to train the software:

                            <!-- Quote template -->
                            <div class="mt-3">
                                <h3 class="font-semibold">Your project quoting template:</h3>
                                <ul>
                                    <li>- From wherever you do quoting (often spreadsheets), download an export in Excel format.</li>
                                    <li>- You might have multiple templates for different staff members. That's ok.</li>
                                    <li>- We configure the software so it auto detects any quoting template your company uses and extracts all the materials.</li>
                                </ul>
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
                                :href="'mailto:'+adminEmail+'?subject=Project%20templates%20and%20suppliers%20list'"
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
        </div>
    </AuthenticatedLayout>
</template>
