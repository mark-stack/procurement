<script setup>
    //General Imports
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        notifications: Array,
    });

    //Form
    const formNotificationMarkStatus = useForm({
        id: null,
        status: null,
    });

    //Shared data
    //...

    //Variables
    /*
     * No local copy of the list and no local "has notifications" flag.
     *
     * Both were here, and both were wrong in the same way: hasNotifications was a ref read once at
     * setup, so it never changed when the prop did, and the success handler called
     * props.notifications.splice() - mutating a prop, which is Vue's own array reached through
     * usePage().props.auth.notifications.
     *
     * Nothing needs to be spliced. HandleInertiaRequests shares auth.notifications on every
     * response, so the answer to "what is still unread" comes back with the POST. Removing the row
     * by hand only made the list disagree with the server whenever the action did something other
     * than mark it read - "Lock it in" saves the project, and if that failed the row vanished anyway.
     */

    //Methods
    function notificationMarkStatus(id, status) {
        formNotificationMarkStatus.id = id;
        formNotificationMarkStatus.status = status;

        formNotificationMarkStatus.post(route("mark.notification.status"), {
            preserveScroll: true,
            //Keep the panel open: acting on one of five notifications should leave the other four up
            preserveState: true,
            onFinish: () => formNotificationMarkStatus.reset(),
        });
    }

    /*
     * The buttons a notification offers, in the order they are read. trafficLights is null for the
     * ones that only report something - a colleague joined - and those get a single Dismiss.
     */
    function actions(notification) {
        const lights = notification.trafficLights;

        if (!lights) {
            return [{status: 'YELLOW', label: 'Dismiss', hint: null, tone: 'neutral'}];
        }

        return [
            lights.green && {status: 'GREEN', label: lights.green[0], hint: lights.green[1], tone: 'go'},
            lights.yellow && {status: 'YELLOW', label: lights.yellow[0], hint: lights.yellow[1], tone: 'neutral'},
            lights.red && {status: 'RED', label: lights.red[0], hint: lights.red[1], tone: 'stop'},
        ].filter(Boolean);
    }

    const toneClasses = {
        go: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
        neutral: 'border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100',
        stop: 'border-red-200 bg-red-50 text-red-800 hover:bg-red-100',
    };
</script>

<template>
    <div class="overflow-hidden">
        <p class="px-4 py-2 text-xs font-semibold tracking-wider text-gray-400 uppercase border-b border-gray-200">
            Notifications
        </p>

        <!-- Capped so a busy week scrolls inside the panel rather than off the bottom of the page -->
        <div class="overflow-y-auto max-h-80">
            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="px-4 py-3 border-b border-gray-100 last:border-b-0"
            >
                <p class="text-sm font-medium text-gray-700">{{ notification.message }}</p>

                <p class="mt-1 text-xs text-gray-400">{{ notification.timestamp }}</p>

                <!--
                    Each type names its own buttons. Two columns rather than three: with the red
                    action omitted on most types, a 3-column grid left a gap where a button wasn't.
                -->
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <button
                        v-for="action in actions(notification)"
                        :key="action.status"
                        type="button"
                        :disabled="formNotificationMarkStatus.processing"
                        @click="notificationMarkStatus(notification.id, action.status)"
                        :class="toneClasses[action.tone]"
                        class="flex-1 min-w-[5rem] px-2 py-1.5 text-xs font-semibold text-center border rounded-md transition-colors duration-200 disabled:opacity-50 disabled:cursor-wait"
                    >
                        {{ action.label }}
                        <span v-if="action.hint" class="block font-normal opacity-70">{{ action.hint }}</span>
                    </button>
                </div>
            </div>

            <!--
                Reachable now. The bell used to be disabled when the count was zero, so an empty
                panel could not be opened and the one thing a person wants to confirm - that there
                is genuinely nothing waiting - was indistinguishable from a broken button.
            -->
            <p v-if="notifications.length === 0" class="px-4 py-6 text-sm text-center text-gray-400">
                Nothing needs your attention.
            </p>
        </div>
    </div>
</template>
