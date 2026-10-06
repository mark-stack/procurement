<script setup>
    //General Imports
    import {Link, usePage} from '@inertiajs/vue3';
    import {computed, onMounted, onUnmounted, ref, watch} from 'vue';

    //Component Imports
    import NavButton from '@/Components/Buttons/NavButton.vue';
    import Notifications2 from '@/Components/Notifications2.vue';

    //Shared Imports
    import useBilling from '@/Shared/useBilling.js';

    //Props
    //...

    //Form
    //...

    //Shared data
    const page = usePage();
    const isAdmin = page.props.auth.isAdmin;
    /*
     * Every link below used to be behind an "onboarded" flag - whether an admin had recorded this
     * business's import templates and pressed Activate. Until that was true the nav showed one item,
     * Onboarding, pointing at a page that asked the customer to email us their spreadsheets.
     *
     * There is no such state now: an upload that matches no template writes its own. So the product's
     * own links are simply the product's links.
     */
    const user = computed(() => page.props.auth.user);
    const notifications = computed(() => page.props.auth.notifications ?? []);

    /*
     * Counted off the rendered list, not off a raw unread count in the database.
     *
     * Only the types on NotificationService::implementations() produce a row for the panel, so a
     * notification whose type is no longer rendered - the deprecated "has this been awarded to you"
     * reminders, say - would show in a database count and then not be in the panel. A badge saying
     * 3 above a list of two is the sort of thing that makes people stop trusting the badge.
     */
    const unreadCount = computed(() => notifications.value.length);
    const hasPastBatches = computed(() => page.props.auth.hasPastBatches);
    //computed, not read once: the nav alert has to clear when an import fills the catalogue,
    //not stay red until the next full page load
    const hasSeedImport = computed(() => page.props.hasSeedImport);

    //Whether this user is in their own sandbox. Decides which way the menu item switches them
    const inSandbox = computed(() => page.props.sandbox?.active === true);

    //A lapsed account, a failed payment or a trial about to run out. Same condition the banner
    //uses, so the red nav item and the red bar can never disagree - see useBilling.js
    const {needsAttention: billingNeedsAttention} = useBilling();

    //Variables
    const showMenu = ref(false);
    const showNotifications = ref(false);
    const showMobileNav = ref(false);

    //The bar is dark, so items on it are light and the open panels are the only white surfaces
    const barLink = 'px-3 py-2 text-sm font-medium tracking-wide rounded-md transition-colors duration-200';
    const panelLink = 'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-200';

    //Shared Methods
    //...

    //Methods
    /*
     * Named handlers, not inline arrows: v-click-away re-registers its document listener on every
     * update unless the bound value is identical, and an inline arrow is a new value each render.
     */
    const closeMenu = () => showMenu.value = false;
    const closeMobileNav = () => showMobileNav.value = false;
    const closeNotifications = () => showNotifications.value = false;

    //Only one panel at a time, or the account menu opens underneath the notifications list
    const toggleMenu = () => {
        showNotifications.value = false;
        showMenu.value = !showMenu.value;
    };

    const toggleNotifications = () => {
        showMenu.value = false;
        showNotifications.value = !showNotifications.value;
    };

    const toggleMobileNav = () => showMobileNav.value = !showMobileNav.value;

    //This nav survives Inertia visits, so a panel left open would hang over the page it navigated to
    watch(() => page.url, () => {
        closeMenu();
        closeMobileNav();
        closeNotifications();
    });

    const closeOnEscape = (e) => {
        if (e.key === 'Escape') {
            closeMenu();
            closeMobileNav();
            closeNotifications();
        }
    };

    onMounted(() => document.addEventListener('keydown', closeOnEscape));
    onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

    /*
     * Compared as paths rather than by route name so the caller can pass route() straight in:
     * Ziggy hands back an absolute URL, and page.url is path-and-query.
     */
    const currentPath = computed(() => page.url.split('?')[0]);

    const isActive = (url) => {
        const path = new URL(url, window.location.origin).pathname;

        return currentPath.value === path || currentPath.value.startsWith(`${path}/`);
    };

    const initials = computed(() => (user.value?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join(''));

    //An empty catalogue only ever announced itself inside the closed menu, where nobody saw it
    const hasAlert = computed(() => isAdmin && !hasSeedImport.value);
</script>

<template>
    <!--
        A fixed 68px rather than a bar that grows with whatever is dropped into it: the boards that
        size themselves against the screen subtract exactly that much.
    -->
    <header class="relative z-30 border-b bg-gray-900 border-white/10">
        <div class="flex items-center justify-between h-[68px] gap-4 px-4 md:px-8">
            <!-- Brand and primary links -->
            <div class="flex items-center min-w-0 gap-6 lg:gap-8">
                <Link
                    href="/"
                    aria-label="SteelNesting.com.au"
                    title="SteelNesting.com.au"
                    class="text-lg font-bold tracking-wide text-gray-100 truncate transition-colors duration-200 hover:text-teal-accent-400 sm:text-xl"
                >
                    SteelNesting.com.au
                </Link>

                <nav class="items-center hidden gap-1 lg:flex">
                    <!--
                        One link, because there is one screen. "Dashboard" and "Current Projects"
                        stood beside this and pointed at the same place: the board they were written
                        for is gone, and so is the pipeline-and-upload page that held /dashboard
                        before Nesting took the route over. Labelled for what the page is rather
                        than for the url it sits on.
                    -->
                    <Link
                        :href="route('dashboard')"
                        title="Every live batch, and its nesting"
                        :class="[barLink, isActive(route('dashboard')) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white']"
                    >
                        Current batches
                    </Link>
                    <Link
                        v-if="hasPastBatches"
                        :href="route('past.batches.index')"
                        title="Past Batches"
                        :class="[barLink, isActive(route('past.batches.index')) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white']"
                    >
                        Past Batches
                    </Link>
                </nav>
            </div>

            <div class="flex items-center gap-2">
                <!-- Notifications -->
                <div v-click-away="closeNotifications" class="relative">
                    <button
                        type="button"
                        @click="toggleNotifications"
                        aria-haspopup="true"
                        :aria-expanded="showNotifications"
                        :aria-label="unreadCount > 0 ? `Notifications (${unreadCount} unread)` : 'Notifications'"
                        :title="unreadCount > 0 ? `${unreadCount} notification${unreadCount === 1 ? '' : 's'}` : 'Notifications'"
                        class="relative p-2 text-gray-300 transition-colors duration-200 rounded-md hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-accent-400"
                    >
                        <svg
                            :class="unreadCount > 0 ? 'animate-wiggle text-orange-300' : 'text-gray-400'"
                            class="w-5 h-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path d="M12 22C10.8954 22 10 21.1046 10 20H14C14 21.1046 13.1046 22 12 22ZM20 19H4V17L6 16V10.5C6 7.038 7.421 4.793 10 4.18V2H13C12.3479 2.86394 11.9967 3.91762 12 5C12 5.25138 12.0187 5.50241 12.056 5.751H12C10.7799 5.67197 9.60301 6.21765 8.875 7.2C8.25255 8.18456 7.94714 9.33638 8 10.5V17H16V10.5C16 10.289 15.993 10.086 15.979 9.9C16.6405 10.0366 17.3226 10.039 17.985 9.907C17.996 10.118 18 10.319 18 10.507V16L20 17V19ZM17 8C16.3958 8.00073 15.8055 7.81839 15.307 7.477C14.1288 6.67158 13.6811 5.14761 14.2365 3.8329C14.7919 2.5182 16.1966 1.77678 17.5954 2.06004C18.9942 2.34329 19.9998 3.5728 20 5C20 6.65685 18.6569 8 17 8Z" fill="currentColor"></path>
                        </svg>

                        <!-- The count, not just a dot: "quote overdue on three projects" is a different morning -->
                        <span
                            v-if="unreadCount > 0"
                            class="absolute -top-0.5 -right-0.5 min-w-[1.05rem] px-1 text-[0.625rem] font-bold leading-[1.05rem] text-white bg-orange-500 rounded-full ring-2 ring-gray-900"
                        >
                            {{ unreadCount > 9 ? '9+' : unreadCount }}
                        </span>
                    </button>

                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-75"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-95"
                    >
                        <!--
                            Wider than the account menu and clamped to the viewport: these read as
                            sentences naming a project, and w-64 wrapped every one of them to five
                            lines.
                        -->
                        <div
                            v-show="showNotifications"
                            class="absolute right-0 z-40 mt-2 origin-top-right bg-white shadow-xl w-[min(22rem,calc(100vw-2rem))] rounded-xl ring-1 ring-black ring-opacity-5"
                        >
                            <Notifications2 :notifications="notifications"/>
                        </div>
                    </Transition>
                </div>

                <!-- Account menu -->
                <div v-click-away="closeMenu" class="relative">
                    <button
                        type="button"
                        @click="toggleMenu"
                        aria-haspopup="true"
                        :aria-expanded="showMenu"
                        class="flex items-center gap-2 py-1.5 pl-1.5 pr-2 rounded-full ring-1 transition-colors duration-200 bg-white/5 ring-white/10 hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-accent-400"
                    >
                        <span class="relative flex items-center justify-center w-8 h-8 text-xs font-bold text-white rounded-full bg-deep-purple-accent-400">
                            {{ initials }}
                            <span
                                v-if="hasAlert"
                                title="The product catalogue is empty"
                                class="absolute w-2.5 h-2.5 bg-red-500 rounded-full ring-2 ring-gray-900 -top-0.5 -right-0.5"
                            ></span>
                        </span>
                        <span class="hidden max-w-[10rem] text-sm font-medium text-gray-100 truncate sm:block">
                            {{ user.name }}
                        </span>
                        <svg
                            :class="showMenu ? 'rotate-180' : ''"
                            class="w-5 h-5 text-gray-400 transition-transform duration-200"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path d="M12 15.713L18.01 9.70299L16.597 8.28799L12 12.888L7.40399 8.28799L5.98999 9.70199L12 15.713Z" fill="currentColor"></path>
                        </svg>
                    </button>

                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-75"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-95"
                    >
                        <!-- A click on any item closes the panel, including one that navigates nowhere new -->
                        <div
                            v-show="showMenu"
                            @click="closeMenu"
                            class="absolute right-0 z-40 w-64 mt-2 overflow-hidden origin-top-right bg-white shadow-xl rounded-xl ring-1 ring-black ring-opacity-5"
                        >
                            <Link
                                :href="route('profile.edit')"
                                class="block px-4 py-3 transition-colors duration-200 hover:bg-gray-50"
                            >
                                <p class="text-sm font-semibold text-gray-700 truncate">{{ user.name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ user.email }}</p>
                            </Link>

                            <div class="p-1.5 space-y-0.5 border-t border-gray-200">
                                <!--
                                    Offcuts, and through it the scrap report - which is not in this
                                    menu on purpose. Scrap is what happened to the steel the rack
                                    did not keep, so it is one link off the offcuts page rather than
                                    a second entry here competing with it.
                                -->
                                <NavButton
                                    :route="route('offcuts.index')"
                                    label="Offcuts"
                                    icon="fa-solid fa-scissors"
                                />
                                <!--
                                    Measures stood here, and is admin-only now. It is read one
                                    business at a time, from the users list, so there is no "my own
                                    figures" page left for this menu - or for the Admin block
                                    below - to link to.
                                -->
                                <!-- Profile -->
                                <NavButton
                                    :route="route('profile.edit')"
                                    label="Profile"
                                    icon="fa-solid fa-gear"
                                />
                                <!--
                                    Test mode. The way in and the way out - the banner on the board
                                    carries the way out too, but this is where somebody looking for
                                    a safe place to try something goes to find one.
                                -->
                                <NavButton
                                    v-if="!inSandbox"
                                    :route="route('sandbox.enter')"
                                    label="Test mode"
                                    icon="fa-solid fa-flask"
                                    method="post"
                                />
                                <NavButton
                                    v-if="inSandbox"
                                    :route="route('sandbox.leave')"
                                    label="Leave test mode"
                                    icon="fa-solid fa-flask"
                                    method="post"
                                />
                                <!--
                                    Billing
                                    The free trial starts when the business does, and the page that
                                    fixes an expired one sits outside the gate an expired one applies.
                                -->
                                <NavButton
                                    :route="route('billing.index')"
                                    label="Billing"
                                    icon="fa-solid fa-credit-card"
                                    :alert="billingNeedsAttention"
                                />
                            </div>

                            <div v-if="isAdmin" class="p-1.5 space-y-0.5 border-t border-gray-200">
                                <p class="px-3 pt-1 pb-1 text-xs font-semibold tracking-wider text-gray-400 uppercase">
                                    Admin
                                </p>
                                <!-- Users -->
                                <NavButton
                                    :route="route('admin.users.index')"
                                    label="Users"
                                    icon="fa-solid fa-users"
                                />
                                <!-- Nesting algorithm -->
                                <NavButton
                                    :route="route('admin.nesting.algorithm')"
                                    label="Nesting Algorithm"
                                    icon="fa-solid fa-calculator"
                                />
                                <!--
                                    Proof
                                    The algorithm page explains what the model charges for; this one runs
                                    it over three lifecycles and shows the plans, offcuts-of-offcuts and all.
                                -->
                                <NavButton
                                    :route="route('proof')"
                                    label="Proof"
                                    icon="fa-solid fa-flask"
                                />
                                <!--
                                    2D Nesting
                                    A proof of concept, and nothing else in the application nests plate.
                                    Whether the offcut philosophy survives the second dimension.
                                -->
                                <NavButton
                                    :route="route('admin.nesting.2d')"
                                    label="2D Nesting"
                                    icon="fa-solid fa-border-all"
                                />
                                <!-- Telescope: not an Inertia page, so it stays a plain link -->
                                <a href="/telescope/exceptions" :class="[panelLink, 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']">
                                    <i class="w-4 text-center fa-solid fa-bug"></i>
                                    <span>Telescope</span>
                                </a>
                                <!--
                                    Master Materials
                                    A page now, not a POST that rewrote all 1,150 rows on one click.
                                    The alert still means "the catalogue is empty", and the page is
                                    where that is explained and fixed.
                                -->
                                <NavButton
                                    :route="route('admin.materials.index')"
                                    label="Master Materials"
                                    icon="fa-solid fa-layer-group"
                                    :alert="!hasSeedImport"
                                />
                            </div>

                            <div class="p-1.5 border-t border-gray-200">
                                <!-- Logout -->
                                <NavButton
                                    :route="route('logout')"
                                    label="Log out"
                                    icon="fa-solid fa-arrow-right-from-bracket"
                                    method="post"
                                    danger
                                />
                            </div>
                        </div>
                    </Transition>
                </div>

                <!-- Mobile menu: the same primary links the bar hides below lg -->
                <div v-click-away="closeMobileNav" class="relative lg:hidden">
                    <button
                        type="button"
                        @click="toggleMobileNav"
                        aria-label="Open Menu"
                        title="Open Menu"
                        :aria-expanded="showMobileNav"
                        class="p-2 text-gray-300 transition-colors duration-200 rounded-md hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-accent-400"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M23,13H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,13,23,13z"></path>
                            <path fill="currentColor" d="M23,6H1C0.4,6,0,5.6,0,5s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,6,23,6z"></path>
                            <path fill="currentColor" d="M23,20H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,20,23,20z"></path>
                        </svg>
                    </button>

                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-75"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-95"
                    >
                        <div
                            v-show="showMobileNav"
                            @click="closeMobileNav"
                            class="absolute right-0 z-40 w-56 mt-2 origin-top-right bg-white shadow-xl rounded-xl ring-1 ring-black ring-opacity-5 p-1.5 space-y-0.5"
                        >
                            <!-- One link, for the one screen - see the bar above -->
                            <Link
                                :href="route('dashboard')"
                                :class="[panelLink, isActive(route('dashboard')) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']"
                            >
                                <i class="w-4 text-center fa-solid fa-bars-staggered"></i>
                                <span>Current batches</span>
                            </Link>
                            <Link
                                v-if="hasPastBatches"
                                :href="route('past.batches.index')"
                                :class="[panelLink, isActive(route('past.batches.index')) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']"
                            >
                                <i class="w-4 text-center fa-solid fa-clock-rotate-left"></i>
                                <span>Past Batches</span>
                            </Link>
                        </div>
                    </Transition>
                </div>
            </div>
        </div>
    </header>
</template>

<style scoped>
    @keyframes wiggle {
        0%, 100% { transform: rotate(-3deg); }
        50% { transform: rotate(3deg); }
    }

    /*
     * Three shakes, not infinite. This bell sits on every authenticated page, so "infinite" meant a
     * permanently moving object in the corner of the screen for as long as anything was unread -
     * which, with deadline reminders that re-ask every day, is most of the time. The orange colour
     * and the count carry the signal after the animation has had its say.
     */
    .animate-wiggle {
        animation: wiggle 0.5s ease-in-out 3;
    }

    @media (prefers-reduced-motion: reduce) {
        .animate-wiggle {
            animation: none;
        }
    }
</style>
