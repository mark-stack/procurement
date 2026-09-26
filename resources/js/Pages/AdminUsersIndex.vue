<script setup>
    //General Imports
    import {Link, Head} from '@inertiajs/vue3';
    import moment from 'moment';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        users: Object,
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
    //...
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <div class="">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-8">
                <section class="bg-white dark:bg-gray-900 rounded-xl">
                    <div class="px-6 pt-8 pb-8 mx-auto">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 mb-3">
                            Users
                        </h1>
                        <table class="w-full text-left">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Business</th>
                                <th>Templates</th>
                                <th>Suppliers</th>
                                <th>Ready</th>
                                <th>Created</th>
                            </tr>
                            <tr v-for="user in users.data" :key="user.id">
                                <td>
                                    <Link
                                        :href="route('admin.impersonate',user.id)"
                                        class="underline text-blue-500"
                                    >
                                        {{user.name}}
                                    </Link>
                                </td>
                                <td>{{user.email}}</td>
                                <td>
                                    <span v-if="user.business">{{user.business.domain}}</span>
                                    <span v-else class="text-red-500 font-bold">No business</span>
                                </td>
                                <td>
                                    <Link
                                        v-if="user.business"
                                        class="underline"
                                        :class="user.templates.length > 0 ? 'text-blue-500' : 'text-red-500 font-bold'"
                                        :href="route('admin.businesses.templates.index',user.business.id)"
                                    >
                                        {{user.templates.length}}
                                    </Link>
                                    <span v-else>&mdash;</span>
                                </td>
                                <td>
                                    <Link
                                        v-if="user.business"
                                        class="underline"
                                        :class="user.suppliers.length > 0 ? 'text-blue-500' : 'text-red-500 font-bold'"
                                        :href="route('admin.suppliers.index',user.business.id)"
                                    >
                                        {{user.suppliers.length}}
                                    </Link>
                                    <span v-else>&mdash;</span>
                                </td>
                                <td>
                                    <template v-if="user.business">
                                        <span v-if="user.business.admin_setup_complete === 1">Active</span>
                                        <Link
                                            v-if="user.business.admin_setup_complete === 0"
                                            :href="route('admin.activate.business',user.business.id)"
                                            class="text-green-500 font-bold"
                                        >
                                            Activate
                                        </Link>
                                    </template>
                                    <span v-else>&mdash;</span>
                                </td>
                                <td>{{ moment(user.created_at).fromNow() }}</td>
                            </tr>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
