<script setup>
    //General Imports
    import {Link, usePage} from '@inertiajs/vue3';
    import {computed, onMounted, onUnmounted, ref, watch} from 'vue';

    //Component Imports
    import NavButton from '@/Components/Buttons/NavButton.vue';
    import Notifications2 from '@/Components/Notifications2.vue';

    //Props
    //...

    //Form
    //...

    //Shared data
    const page = usePage();
    const isAdmin = page.props.auth.isAdmin;
    const onboarded = page.props.auth.onboarded;
    const user = computed(() => page.props.auth.user);
    const notifications = computed(() => page.props.auth.notifications);
    const hasPastProjects = computed(() => page.props.auth.hasPastProjects);
    //computed, not read once: the nav alert has to clear when an import fills the catalogue,
    //not stay red until the next full page load
    const hasSeedImport = computed(() => page.props.hasSeedImport);

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

    //Only one panel at a time, or the account menu opens underneath the notifications list
    const toggleMenu = () => {
        showNotifications.value = false;
        showMenu.value = !showMenu.value;
    };

    const toggleMobileNav = () => showMobileNav.value = !showMobileNav.value;

    //This nav survives Inertia visits, so a panel left open would hang over the page it navigated to
    watch(() => page.url, () => {
        closeMenu();
        closeMobileNav();
    });

    const closeOnEscape = (e) => {
        if (e.key === 'Escape') {
            closeMenu();
            closeMobileNav();
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
        The layout sizes the rest of the page as window.innerHeight minus 68, so this bar holds a
        fixed height rather than growing with whatever is dropped into it.
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
                    <Link
                        v-if="onboarded"
                        :href="route('projects.index')"
                        title="Current Projects"
                        :class="[barLink, isActive(route('projects.index')) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white']"
                    >
                        Current Projects
                    </Link>
                    <Link
                        v-if="hasPastProjects"
                        :href="route('past.projects.index')"
                        title="Past Projects"
                        :class="[barLink, isActive(route('past.projects.index')) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white']"
                    >
                        Past Projects
                    </Link>
                </nav>
            </div>

            <div class="flex items-center gap-2">
                <!--
                    Notifications. The bell itself stays switched off; the panel below is what it
                    opens, kept here so turning it back on is a matter of restoring the button.
                -->
                <div class="relative">
<!--                    <button-->
<!--                        @click="showMenu = false; showNotifications = !showNotifications"-->
<!--                        :disabled="notifications.length === 0"-->
<!--                        class="relative p-2 text-gray-300 transition-colors duration-200 rounded-md hover:bg-white/10 hover:text-white focus:outline-none"-->
<!--                    >-->
<!--                        <svg-->
<!--                            :class="notifications.length > 0 ? 'animate-wiggle text-orange-300' : 'text-gray-400'"-->
<!--                            class="w-5 h-5"-->
<!--                            viewBox="0 0 24 24"-->
<!--                            fill="none"-->
<!--                            xmlns="http://www.w3.org/2000/svg"-->
<!--                        >-->
<!--                            <path d="M12 22C10.8954 22 10 21.1046 10 20H14C14 21.1046 13.1046 22 12 22ZM20 19H4V17L6 16V10.5C6 7.038 7.421 4.793 10 4.18V2H13C12.3479 2.86394 11.9967 3.91762 12 5C12 5.25138 12.0187 5.50241 12.056 5.751H12C10.7799 5.67197 9.60301 6.21765 8.875 7.2C8.25255 8.18456 7.94714 9.33638 8 10.5V17H16V10.5C16 10.289 15.993 10.086 15.979 9.9C16.6405 10.0366 17.3226 10.039 17.985 9.907C17.996 10.118 18 10.319 18 10.507V16L20 17V19ZM17 8C16.3958 8.00073 15.8055 7.81839 15.307 7.477C14.1288 6.67158 13.6811 5.14761 14.2365 3.8329C14.7919 2.5182 16.1966 1.77678 17.5954 2.06004C18.9942 2.34329 19.9998 3.5728 20 5C20 6.65685 18.6569 8 17 8Z" fill="currentColor"></path>-->
<!--                        </svg>-->
<!--                    </button>-->

                    <div
                        v-if="showNotifications && notifications.length > 0"
                        class="absolute right-0 z-40 w-64 mt-2 origin-top-right bg-white shadow-xl rounded-xl ring-1 ring-black ring-opacity-5"
                    >
                        <Notifications2 :notifications="notifications"/>
                    </div>
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
                                <!-- Onboarding -->
                                <NavButton
                                    v-if="!onboarded"
                                    :route="route('onboarding')"
                                    label="Onboarding"
                                    icon="fa-solid fa-list-check"
                                />
                                <!-- Suppliers -->
                                <NavButton
                                    v-if="onboarded"
                                    :route="route('suppliers.index')"
                                    label="Suppliers"
                                    icon="fa-solid fa-cubes"
                                />
                                <!-- Offcuts -->
                                <NavButton
                                    v-if="onboarded"
                                    :route="route('offcuts.index')"
                                    label="Offcuts"
                                    icon="fa-solid fa-scissors"
                                />
                                <!-- Profile -->
                                <NavButton
                                    v-if="onboarded"
                                    :route="route('profile.edit')"
                                    label="Profile"
                                    icon="fa-solid fa-gear"
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
                                <!-- Telescope: not an Inertia page, so it stays a plain link -->
                                <a href="/telescope/exceptions" :class="[panelLink, 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']">
                                    <i class="w-4 text-center fa-solid fa-bug"></i>
                                    <span>Telescope</span>
                                </a>
                                <!-- Update Materials -->
                                <NavButton
                                    :route="route('admin.update.master.materials.spreadsheet')"
                                    label="Update Materials"
                                    icon="fa-solid fa-file-excel"
                                    :alert="!hasSeedImport"
                                    method="post"
                                    confirm="Re-import master_materials.csv? This rewrites the whole platform product catalogue."
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
                <div v-if="onboarded" v-click-away="closeMobileNav" class="relative lg:hidden">
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
                            <Link
                                :href="route('projects.index')"
                                :class="[panelLink, isActive(route('projects.index')) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']"
                            >
                                <i class="w-4 text-center fa-solid fa-diagram-project"></i>
                                <span>Current Projects</span>
                            </Link>
                            <Link
                                v-if="hasPastProjects"
                                :href="route('past.projects.index')"
                                :class="[panelLink, isActive(route('past.projects.index')) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-700']"
                            >
                                <i class="w-4 text-center fa-solid fa-box-archive"></i>
                                <span>Past Projects</span>
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

    .animate-wiggle {
        animation: wiggle 0.5s ease-in-out infinite;
    }
</style>
