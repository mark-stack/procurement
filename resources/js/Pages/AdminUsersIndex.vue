<script setup>
    //General Imports
    import {Link, Head, usePage} from '@inertiajs/vue3';
    import {computed} from 'vue';
    import moment from 'moment';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        users: Object,
    });

    //Shared data
    //The layout renders flash.success but not flash.warning, and both admin refusals that
    //land back here - impersonating an admin, activating an active business - use warning
    const warning = computed(() => usePage().props.flash?.warning);

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    //...

    //Methods
    //The name column is the easiest thing here to mis-click, and a mis-click swaps the
    //account the session is signed in as
    const confirmImpersonate = (event) => {
        if (! window.confirm('Sign in as this user? You will see the site exactly as they do until you stop impersonating.')) {
            event.preventDefault();
        }
    };

    //Activating emails every user in the business, and that cannot be taken back
    const confirmActivate = (event) => {
        if (! window.confirm('Activate this business? Every user in it is emailed a welcome message.')) {
            event.preventDefault();
        }
    };
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
                        <p
                            v-if="warning"
                            role="alert"
                            class="px-4 py-3 mb-3 text-sm border rounded-lg border-red-300 bg-red-50 text-red-900"
                        >
                            {{ warning }}
                        </p>
                        <table class="w-full text-left">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Business</th>
                                    <th scope="col">Templates</th>
                                    <th scope="col">Suppliers</th>
                                    <th scope="col">Ready</th>
                                    <th scope="col">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users.data" :key="user.id">
                                    <td>
                                        <!--
                                            POST: impersonating changes who the session is
                                            authenticated as, so it must not be a link a prefetch
                                            or a crawler can follow. It asks first because the
                                            name column is the easiest thing on the page to
                                            mis-click.
                                        -->
                                        <Link
                                            v-if="!user.isAdmin"
                                            :href="route('admin.impersonate',user.id)"
                                            method="post"
                                            as="button"
                                            type="button"
                                            class="underline text-blue-500"
                                            @click="confirmImpersonate"
                                        >
                                            {{user.name}}
                                        </Link>
                                        <span v-else>{{user.name}}</span>
                                    </td>
                                    <td>
                                        {{user.email}}
                                        <!--
                                            Impersonating an unverified user lands on the
                                            verification prompt rather than the dashboard, so the
                                            row has to say so before it is clicked
                                        -->
                                        <span
                                            v-if="!user.isVerified"
                                            class="ml-1 text-xs text-red-500 font-bold"
                                        >
                                            unverified
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="user.business">{{user.business.domain}}</span>
                                        <span v-else class="text-red-500 font-bold">No business</span>
                                    </td>
                                    <td>
                                        <Link
                                            v-if="user.business"
                                            class="underline"
                                            :class="user.templates_count > 0 ? 'text-blue-500' : 'text-red-500 font-bold'"
                                            :href="route('admin.businesses.templates.index',user.business.id)"
                                        >
                                            {{user.templates_count}}
                                        </Link>
                                        <span v-else>&mdash;</span>
                                    </td>
                                    <td>
                                        <Link
                                            v-if="user.business"
                                            class="underline"
                                            :class="user.suppliers_count > 0 ? 'text-blue-500' : 'text-red-500 font-bold'"
                                            :href="route('admin.suppliers.index',user.business.id)"
                                        >
                                            {{user.suppliers_count}}
                                        </Link>
                                        <span v-else>&mdash;</span>
                                    </td>
                                    <td>
                                        <template v-if="user.business">
                                            <span v-if="user.business.admin_setup_complete">Active</span>
                                            <!--
                                                POST: activating emails every user in the business,
                                                so it must not be reachable by a prefetch, a
                                                crawler or the back button
                                            -->
                                            <Link
                                                v-else
                                                :href="route('admin.activate.business',user.business.id)"
                                                method="post"
                                                as="button"
                                                type="button"
                                                class="text-green-500 font-bold"
                                                @click="confirmActivate"
                                            >
                                                Activate
                                            </Link>
                                        </template>
                                        <span v-else>&mdash;</span>
                                    </td>
                                    <td>{{ moment(user.created_at).fromNow() }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <!--
                            The list used to return every user on the platform in one response.
                            Newest first, so page 1 is the one an admin actually wants.
                        -->
                        <div
                            v-if="users.meta.last_page > 1"
                            class="flex items-center justify-between mt-6"
                        >
                            <Link
                                v-if="users.links.prev"
                                :href="users.links.prev"
                                preserve-scroll
                                class="underline text-blue-500"
                            >
                                Previous
                            </Link>
                            <span v-else class="text-gray-400">Previous</span>

                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ users.meta.from }}&ndash;{{ users.meta.to }} of {{ users.meta.total }}
                            </span>

                            <Link
                                v-if="users.links.next"
                                :href="users.links.next"
                                preserve-scroll
                                class="underline text-blue-500"
                            >
                                Next
                            </Link>
                            <span v-else class="text-gray-400">Next</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
