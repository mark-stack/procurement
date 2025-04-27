<script setup>
    //General Imports
    import {Link, Head, usePage} from '@inertiajs/vue3';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import OffcutsDatatable from "@/Components/Tables/OffcutsDatatable.vue";

    //Props
    const props = defineProps({
        offcuts: Object,
    });

    //Form
    //

    //Shared data
    const isAdmin = usePage().props.auth.isAdmin;

    //Variables
    //

    //Methods
    //
</script>

<template>
    <Head title="Suppliers" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="mb-3">
                    <Link
                        class="font-semibold px-3 py-2 text-gray-800 transition-colors duration-300 transform rounded-lg hover:text-deep-purple-accent-400"
                        :href="route('projects.index')"
                    >
                        <i class="fa-regular fa-hand-point-left pr-2"></i> Current Projects
                    </Link>
                </div>
                <section class="bg-white dark:bg-gray-900 rounded-xl mt-5">

                    <OffcutsDatatable :data="offcuts.data"/>

<!--                    <table class="min-w-full table-auto border-collapse border border-gray-300">-->
<!--                        <thead>-->
<!--                            <tr class="bg-gray-100">-->
<!--                                <th class="border border-gray-300 px-4 py-2 text-left">Material</th>-->
<!--                                <th class="border border-gray-300 px-4 py-2 text-left">Length (mm)</th>-->
<!--                                <th class="border border-gray-300 px-4 py-2 text-left">Marked</th>-->
<!--                                <th class="border border-gray-300 px-4 py-2 text-left">From Batch/Projects</th>-->
<!--                                <th class="border border-gray-300 px-4 py-2 text-left">Certificates</th>-->
<!--                            </tr>-->
<!--                        </thead>-->
<!--                        <tbody>-->
<!--                            <tr v-for="offcut in offcuts.data">-->
<!--                                <td class="border border-gray-300 px-4 py-2">{{offcut.label}}</td>-->
<!--                                <td class="border border-gray-300 px-4 py-2">{{offcut.length}}</td>-->
<!--                                <td class="border border-gray-300 px-4 py-2"><i>{{offcut.unique_mark}}</i></td>-->
<!--                                <td class="border border-gray-300 px-4 py-2">#{{offcut.batch_from_id}}: {{getProjectNames(offcut)}}</td>-->
<!--                                <td class="border border-gray-300 px-4 py-2">{{getCerts(offcut)}}</td>-->
<!--                            </tr>-->
<!--                        </tbody>-->
<!--                    </table>-->
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
