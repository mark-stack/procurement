<script setup>
    //General Imports
    import {Head, Link, router} from '@inertiajs/vue3';
    import {computed} from 'vue';
    import moment from 'moment';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        deliveries: Object,
        filters: Object,
        //{database: 'Bell', mail: 'Email'} - from the model, so the pills cannot name a channel
        //the filter would then reject
        channels: Object,
        //Keyed by channel, plus '' for every channel. Counted under the user filter but not the
        //channel one, so each pill says what is on its own side of the filter
        totals: Object,
        //The user the list is narrowed to, or null. Null with filters.user set means the id
        //matched nobody - a stale link, or a deleted account
        filteredUser: Object,
    });

    //Variables
    /*
     * '' first: the point of this page is that both channels are one list, so "everything" is the
     * default view and the two narrower ones hang off it.
     */
    const channelPills = computed(() => [
        {value: '', label: 'All'},
        ...Object.entries(props.channels).map(([value, label]) => ({value, label})),
    ]);

    const isFilteredToMissingUser = computed(() => !!props.filters.user && !props.filteredUser);

    //Methods
    /*
     * The user filter is carried through a channel change and not silently dropped: arriving from
     * the users list and clicking "Bell" asks "what has this person seen in the app", which is a
     * question about the same person. Dropping it would answer about the whole platform instead and
     * look like the filter had simply broken.
     */
    function filterChannel(channel){
        router.get(route('admin.notifications.index'), {
            channel: channel,
            user: props.filters.user ?? undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    //Keeps the channel, because "show me everyone's emails" is the way out of one person's
    function clearUser(){
        router.get(route('admin.notifications.index'), {
            channel: props.filters.channel || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    //A notification sent by both channels is two rows a second apart at most, so the exact time
    //is what distinguishes them - the relative wording goes in the title
    const sentAt = (timestamp) => moment(timestamp).format('D MMM YYYY, HH:mm');
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout>
        <div class="">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-8">
                <section class="bg-white dark:bg-gray-900 rounded-xl">
                    <div class="px-6 pt-8 pb-8 mx-auto">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 mb-1">
                            Notifications
                        </h1>
                        <p class="mb-5 text-sm text-gray-600 dark:text-gray-400">
                            Everything sent, bell and email alike. A notification that went out by
                            both appears twice &mdash; once per channel.
                        </p>

                        <!-- Filters -->
                        <div class="flex flex-wrap items-center gap-2 mb-5">
                            <button
                                v-for="pill in channelPills"
                                :key="pill.value || 'all'"
                                type="button"
                                class="px-3 py-1 text-sm border rounded-full transition-colors"
                                :class="filters.channel === pill.value
                                    ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold dark:bg-blue-950 dark:text-blue-200'
                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800'"
                                :aria-pressed="filters.channel === pill.value"
                                @click="filterChannel(pill.value)"
                            >
                                {{ pill.label }}
                                <span class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                                    {{ totals[pill.value] ?? 0 }}
                                </span>
                            </button>

                            <!--
                                The user filter, which is how this page is almost always arrived at.
                                Shown as something to dismiss rather than as a heading, because the
                                list behind it is the whole platform's and the narrowing is the part
                                that is easy to forget.
                            -->
                            <span
                                v-if="filteredUser"
                                class="flex items-center gap-2 px-3 py-1 text-sm border border-gray-300 rounded-full dark:border-gray-700"
                            >
                                <span class="text-gray-700 dark:text-gray-200">
                                    To {{ filteredUser.name }}
                                    <span class="text-gray-500 dark:text-gray-400">
                                        &lt;{{ filteredUser.email }}&gt;
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    class="font-bold text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                                    aria-label="Show every recipient"
                                    @click="clearUser"
                                >
                                    &times;
                                </button>
                            </span>
                        </div>

                        <!--
                            A filter that matched nobody, said out loud. An empty table under a
                            dismissed-looking filter reads as "nothing has ever been sent", which
                            about this page is the one wrong conclusion to leave somebody with.
                        -->
                        <p
                            v-if="isFilteredToMissingUser"
                            role="alert"
                            class="px-4 py-3 mb-3 text-sm border rounded-lg border-amber-300 bg-amber-50 text-amber-900"
                        >
                            No user with id {{ filters.user }} &mdash; the account may have been
                            deleted since this link was made. Nothing below is filtered to them.
                            <button
                                type="button"
                                class="underline"
                                @click="clearUser"
                            >
                                Show every recipient
                            </button>
                        </p>

                        <table class="w-full text-left">
                            <thead>
                                <tr>
                                    <th scope="col">Sent</th>
                                    <th scope="col">Channel</th>
                                    <th scope="col">Notification</th>
                                    <th scope="col">Recipient</th>
                                    <th scope="col">Subject</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="delivery in deliveries.data" :key="delivery.id">
                                    <td :title="moment(delivery.sentAt).fromNow()">
                                        {{ sentAt(delivery.sentAt) }}
                                    </td>
                                    <td>
                                        <!--
                                            The pill, and the only thing on the row that says which
                                            of the two happened. Mail is the one that left the
                                            building and cannot be taken back, so it is the one
                                            that carries weight.
                                        -->
                                        <span
                                            class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full"
                                            :class="delivery.channel === 'mail'
                                                ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200'
                                                : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'"
                                        >
                                            {{ delivery.channelLabel }}
                                        </span>
                                    </td>
                                    <td>
                                        <!-- The class name is the only stable handle on a type, so
                                             it is the title rather than lost to the wording -->
                                        <span :title="delivery.type">{{ delivery.typeLabel }}</span>
                                        <span
                                            v-if="delivery.projectName"
                                            class="block text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ delivery.projectName }}
                                        </span>
                                    </td>
                                    <td>
                                        <!--
                                            This log outlives the account it names - that is what a
                                            log is for - so a missing recipient is a state to render
                                            and not a reason to fall over.
                                        -->
                                        <template v-if="delivery.recipient">
                                            <Link
                                                class="underline text-blue-500"
                                                :href="route('admin.notifications.index',{
                                                    channel: filters.channel || undefined,
                                                    user: delivery.recipient.id,
                                                })"
                                                :title="`Everything sent to ${delivery.recipient.name}`"
                                            >
                                                {{ delivery.recipient.name }}
                                            </Link>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ delivery.recipient.email }}
                                                <template v-if="delivery.recipient.business">
                                                    &middot; {{ delivery.recipient.business }}
                                                </template>
                                            </span>
                                            <!--
                                                Only when the address has changed since. The log
                                                keeps where the mail actually went, and a reminder
                                                sent before somebody corrected a typo in their email
                                                did not reach the corrected one.
                                            -->
                                            <span
                                                v-if="delivery.recipientEmail
                                                    && delivery.recipientEmail !== delivery.recipient.email"
                                                class="block text-xs text-amber-600 dark:text-amber-400"
                                            >
                                                sent to {{ delivery.recipientEmail }}
                                            </span>
                                        </template>
                                        <span v-else class="text-gray-500">
                                            {{ delivery.recipientEmail || 'No recipient' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="delivery.subject">{{ delivery.subject }}</span>
                                        <!-- The bell has no subject, and did not fail to have one -->
                                        <span v-else class="text-gray-400">&mdash;</span>
                                    </td>
                                </tr>
                                <tr v-if="deliveries.data.length === 0">
                                    <td colspan="5" class="py-6 text-gray-500 dark:text-gray-400">
                                        <!--
                                            Mail has no history before this log existed, so an empty
                                            Email list on a platform that has been sending for months
                                            is expected rather than alarming - and worth saying,
                                            because the alternative reading is that mail is broken.
                                        -->
                                        Nothing sent
                                        <template v-if="filters.channel">
                                            on this channel
                                        </template>
                                        <template v-if="filteredUser">
                                            to {{ filteredUser.name }}
                                        </template>
                                        yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Paged for the reason the users list is: this table only grows -->
                        <div
                            v-if="deliveries.meta.last_page > 1"
                            class="flex items-center justify-between mt-6"
                        >
                            <Link
                                v-if="deliveries.links.prev"
                                :href="deliveries.links.prev"
                                preserve-scroll
                                class="underline text-blue-500"
                            >
                                Previous
                            </Link>
                            <span v-else class="text-gray-400">Previous</span>

                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                {{ deliveries.meta.from }}&ndash;{{ deliveries.meta.to }}
                                of {{ deliveries.meta.total }}
                            </span>

                            <Link
                                v-if="deliveries.links.next"
                                :href="deliveries.links.next"
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
