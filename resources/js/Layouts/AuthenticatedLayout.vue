<script setup>
    //General Imports
    import {usePage} from '@inertiajs/vue3';
    import {computed, ref, watch} from "vue";

    //Component Imports
    import AuthenticatedNav from "@/Components/Nav/AuthenticatedNav.vue";
    import ImpersonationBanner from "@/Components/ImpersonationBanner.vue";
    import BillingBanner from "@/Components/Billing/BillingBanner.vue";

    //Shared data
    //What the master materials screen said about the last edit it saved or refused. A refusal is
    //the interesting case: the catalogue is edited from a modal, and a spec locked by work already
    //recorded against it has to explain itself outside that modal too.
    const materials = computed(() => usePage().props.flash?.materials);
    const success = computed(() => usePage().props.flash?.success);

    //Variables
    /*
     * There is deliberately no height measured here any more. <main> used to be a scroll container
     * of exactly window.innerHeight minus the nav and any banners, which gave every page two
     * scrollbars: that height was read once at setup and never again, so a resize, a zoom or a
     * banner appearing left the box taller than the viewport and the document scrolled too. The
     * page scrolls, and nothing inside it does.
     */
    const materialsDismissed = ref(false);
    const successDismissed = ref(false);

    //This layout survives Inertia visits, so a dismissal has to end with the message it dismissed
    watch(success, () => successDismissed.value = false);
    //Without this, dismissing one result hid every later one: a second edit reported neither
    //success nor failure
    watch(materials, () => materialsDismissed.value = false);
</script>

<template>
    <!--
        One column the height of the viewport, with the banners and the nav in it, rather than a
        five-column grid holding one item that spanned seven of them. The two implicit tracks that
        spanning created were sized to content, which is where the horizontal scrollbar came from;
        the banners are inside it now, so a banner appearing costs main its height instead of
        pushing the whole page past the bottom of the screen. The sidebar the grid was for is
        still commented out below.
    -->
    <div class="flex flex-col min-h-screen">
        <ImpersonationBanner/>

        <BillingBanner/>

        <AuthenticatedNav/>

<!--        &lt;!&ndash;sidebar &ndash;&gt;-->
<!--        <aside class="col-span-1 h-screen px-2 py-8 overflow-y-auto bg-white border-r dark:bg-gray-900 dark:border-gray-700">-->
<!--            <div class="flex justify-between">-->
<!--                <a href="/" class="pl-3 font-extrabold">-->
<!--                    PROCUREMENT-->
<!--                </a>-->
<!--                &lt;!&ndash; Logout &ndash;&gt;-->
<!--                <Link-->
<!--                    :href="route('logout')"-->
<!--                    method="post"-->
<!--                    class="text-gray-500 transition-colors duration-200 rotate-180 dark:text-gray-400 rtl:rotate-0 hover:text-blue-500 dark:hover:text-blue-400"-->
<!--                >-->
<!--                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">-->
<!--                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />-->
<!--                    </svg>-->
<!--                </Link>-->
<!--            </div>-->

<!--            <div class="flex flex-col justify-start flex-1 mt-3">-->
<!--                <nav class="">-->
<!--                    &lt;!&ndash; Onboarding &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="!onboarded"-->
<!--                        :route="route('onboarding')"-->
<!--                        label="Onboarding"-->
<!--                        icon="fa-solid fa-list-check"-->
<!--                    />-->
<!--                    &lt;!&ndash; Projects &ndash;&gt;-->
<!--                    <Link-->
<!--                        v-if="onboarded"-->
<!--                        class="flex items-center px-3 py-2 text-gray-600 transition-colors duration-300 transform rounded-lg dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 dark:hover:text-gray-200 hover:text-gray-700"-->
<!--                        :href="route('projects.index')"-->
<!--                    >-->
<!--                        <svg xmlns="http://www.w3.org/2000/svg" width="1.3em" height="1.3em" viewBox="0 0 18 18" class="bi bi-kanban" fill="currentColor">-->
<!--                            <path fill-rule="evenodd" d="M13.5 1h-11a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zm-11-1a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2h-11z"/>-->
<!--                            <path d="M6.5 3a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V3zm-4 0a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V3zm8 0a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V3z"/>-->
<!--                        </svg>-->
<!--                        <span class="mx-2 text-sm font-medium">Projects (Kanban)</span>-->
<!--                    </Link>-->

<!--                    &lt;!&ndash; Price Book &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="onboarded"-->
<!--                        :route="route('pricebook')"-->
<!--                        label="Price Book"-->
<!--                        icon="fa-solid fa-list"-->
<!--                    />-->
<!--                    &lt;!&ndash; Suppliers &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="onboarded"-->
<!--                        :route="route('suppliers.index')"-->
<!--                        label="Suppliers"-->
<!--                        icon="fa-solid fa-cubes"-->
<!--                    />-->
<!--                    &lt;!&ndash; Profile &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="onboarded"-->
<!--                        :route="route('profile.edit')"-->
<!--                        label="Profile"-->
<!--                        icon="fa-solid fa-gear"-->
<!--                    />-->
<!--                    &lt;!&ndash; Users &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="isAdmin"-->
<!--                        :route="route('admin.users.index')"-->
<!--                        label="Users (Admin)"-->
<!--                        icon="fa-solid fa-users"-->
<!--                    />-->
<!--                    &lt;!&ndash; Telescope &ndash;&gt;-->
<!--                    <a-->
<!--                        v-if="isAdmin"-->
<!--                        class="flex items-center px-3 py-2 text-gray-600 transition-colors duration-300 transform rounded-lg dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 dark:hover:text-gray-200 hover:text-gray-700"-->
<!--                        href="/telescope/exceptions"-->
<!--                    >-->
<!--                        <i class="fa-solid fa-bug"></i>-->
<!--                        <span class="mx-2 text-sm font-medium">Telescope (Admin)</span>-->
<!--                    </a>-->
<!--                    &lt;!&ndash; Master Materials &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="isAdmin"-->
<!--                        :route="route('admin.materials.index')"-->
<!--                        label="Master Materials (Admin)"-->
<!--                        icon="fa-solid fa-layer-group"-->
<!--                        :alert="!hasSeedImport"-->
<!--                    />-->
<!--                </nav>-->

<!--                <div class="mt-6">-->
<!--                    <Notifications2/>-->
<!--                </div>-->
<!--            </div>-->
<!--        </aside>-->

        <!-- Main. Grows to fill the screen so the background does, and scrolls with the page -->
        <main class="flex flex-col flex-1 min-w-0 px-4 bg-[#f9fafb]"><!--bg-gradient-to-tr from-blue-100 via-indigo-100 to-gray-100-->
            <!-- What the master materials screen made of the last edit -->
            <div
                v-if="materials && !materialsDismissed"
                :class="materials.ok ? 'border-green-300 bg-green-50 text-green-900' : 'border-red-300 bg-red-50 text-red-900'"
                class="flex items-start justify-between gap-4 px-4 py-3 mt-4 text-sm border rounded-lg"
            >
                <p>{{ materials.message }}</p>
                <button
                    type="button"
                    class="font-bold shrink-0"
                    aria-label="Dismiss"
                    @click="materialsDismissed = true"
                >
                    &times;
                </button>
            </div>

            <!-- Flashed confirmation of something that has already happened -->
            <div
                v-if="success && !successDismissed"
                role="status"
                class="flex items-start justify-between gap-4 px-4 py-3 mt-4 text-sm border rounded-lg border-green-300 bg-green-50 text-green-900"
            >
                <p>{{ success }}</p>
                <button
                    type="button"
                    class="font-bold shrink-0"
                    aria-label="Dismiss"
                    @click="successDismissed = true"
                >
                    &times;
                </button>
            </div>
            <slot/>
        </main>
    </div>
</template>
