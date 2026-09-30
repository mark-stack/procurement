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
    /*
     * Both of these are Link's onBefore, which cancels the visit when it returns false. They were
     * @click handlers calling event.preventDefault(), which cannot work: Link's own click handler
     * is registered first - its render spreads attrs and then overwrites onClick with its own, and
     * Vue's attribute fallthrough re-merges ours after it - so router.visit() had already fired by
     * the time the dialog opened, and shouldIntercept had already read defaultPrevented as false.
     * Cancel activated the business and emailed everyone in it anyway.
     */

    //The name column is the easiest thing here to mis-click, and a mis-click swaps the
    //account the session is signed in as
    const confirmImpersonate = () =>
        window.confirm('Sign in as this user? You will see the site exactly as they do until you stop impersonating.');

    //Activating emails every user in the business, and that cannot be taken back
    const confirmActivate = () =>
        window.confirm('Activate this business? Every user in it is emailed a welcome message.');

    //Another email to a real inbox, so it is worth a beat - but it is the recoverable one of
    //the three, which is why it names the address rather than warning about anything
    const confirmResend = (user) =>
        window.confirm(`Email a fresh welcome and login link to ${user.email}?`);

    //This one takes the app away from everyone in the business until it is activated again
    const confirmDeactivate = () =>
        window.confirm('Deactivate this business? Everyone in it goes back to onboarding and cannot use the app until it is activated again. Nobody is emailed.');
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
                                            :on-before="confirmImpersonate"
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
                                        <!--
                                            Two numbers when they disagree: templates that are matched
                                            against uploads, of templates recorded. A bare red "0"
                                            beside a business with three templates recorded reads as
                                            "nobody has recorded one", and it means "none of the three
                                            is active" - a different problem, on a different screen.
                                        -->
                                        <Link
                                            v-if="user.business"
                                            class="underline"
                                            :class="user.templates_count > 0 ? 'text-blue-500' : 'text-red-500 font-bold'"
                                            :href="route('admin.businesses.templates.index',user.business.id)"
                                            :title="user.templates_total > user.templates_count
                                                ? user.templates_count + ' of ' + user.templates_total + ' recorded templates are active and able to find a table. The rest match no upload.'
                                                : 'Templates this business\'s uploads are matched against'"
                                        >
                                            {{user.templates_count}}<span
                                                v-if="user.templates_total > user.templates_count"
                                                class="font-normal text-gray-500 dark:text-gray-400"
                                            > of {{user.templates_total}}</span>
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
                                        <!--
                                            POST throughout: each of these writes, and two of them
                                            send mail, so none must be reachable by a prefetch, a
                                            crawler or the back button.
                                        -->
                                        <template v-if="user.business">
                                            <!--
                                                Active is not the end of the story: a welcome can
                                                have been queued while the worker was down, and a
                                                business can have been activated by mistake.
                                            -->
                                            <div v-if="user.business.admin_setup_complete" class="flex flex-wrap items-baseline gap-x-3">
                                                <span>Active</span>

                                                <!-- This user's own welcome, not the whole business's -->
                                                <Link
                                                    :href="route('admin.resend.welcome',user.id)"
                                                    method="post"
                                                    as="button"
                                                    type="button"
                                                    class="text-xs underline text-blue-500"
                                                    :on-before="() => confirmResend(user)"
                                                >
                                                    Resend welcome
                                                </Link>

                                                <Link
                                                    :href="route('admin.deactivate.business',user.business.id)"
                                                    method="post"
                                                    as="button"
                                                    type="button"
                                                    class="text-xs underline text-red-500"
                                                    :on-before="confirmDeactivate"
                                                >
                                                    Deactivate
                                                </Link>
                                            </div>

                                            <Link
                                                v-else
                                                :href="route('admin.activate.business',user.business.id)"
                                                method="post"
                                                as="button"
                                                type="button"
                                                class="text-green-500 font-bold"
                                                :on-before="confirmActivate"
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
