<script setup>
    //General Imports
    import {Link, usePage} from '@inertiajs/vue3';
    import {computed, ref} from "vue";

    //Component Imports
    import Notifications2 from "@/Components/Notifications2.vue";
    import NavButton from "@/Components/Buttons/NavButton.vue";

    //Shared data
    const business = usePage().props.auth.business;
    const isAdmin = usePage().props.auth.isAdmin;
    const onboarded = usePage().props.auth.onboarded;
    const hasSeedImport = usePage().props.hasSeedImport;
    const user = computed(() => usePage().props.auth.user);
    const notifications = computed(() => usePage().props.auth.notifications);
    const hasPastProjects = computed(() => usePage().props.auth.hasPastProjects);

    //Variables
    const showNotifications = ref(false);
    const showMenu = ref(false);
    const underNavScreenHeight = window.innerHeight - 68;
</script>

<template>
    <div class="bg-gray-900">
        <div class="px-4 py-2 mx-auto md:px-24 lg:px-8">
            <div class="relative flex items-center justify-between">
                <div class="flex items-center">
                    <Link href="/" aria-label="Company" title="Company" class="inline-flex items-center mr-8">
<!--                        <svg class="w-8 text-teal-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">-->
<!--                            <rect x="3" y="1" width="7" height="12"></rect>-->
<!--                            <rect x="3" y="17" width="7" height="6"></rect>-->
<!--                            <rect x="14" y="1" width="7" height="6"></rect>-->
<!--                            <rect x="14" y="11" width="7" height="12"></rect>-->
<!--                        </svg>-->
                        <span class="ml-2 text-xl font-bold tracking-wide text-gray-100">SteelNesting.com.au</span>
                    </Link>
                    <ul class="flex items-center hidden space-x-8 lg:flex">
                        <li>
                            <Link
                                :href="route('projects.index')"
                                title="Current Projects"
                                class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                            >
                                Current Projects
                            </Link>
                        </li>
                        <li v-if="hasPastProjects">
                            <Link
                                :href="route('past.projects.index')"
                                title="Past Projects"
                                class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                            >
                                Past Projects
                            </Link>
                        </li>
                    </ul>
                </div>
                <ul class="flex items-center hidden space-x-8 lg:flex">
                    <li>
                        <!-- Mobile Menu open: "block", Menu closed: "hidden" -->
                        <div class="absolute inset-x-0 z-20 w-full px-6 py-4 transition-all duration-300 ease-in-out bg-white dark:bg-gray-800 md:mt-0 md:p-0 md:top-0 md:relative md:bg-transparent md:w-auto md:opacity-100 md:translate-x-0 md:flex md:items-center">
                            <div class="flex justify-center pt-2 gap-x-2">
                                <!-- Notifications -->
                                <div class="relative text-gray-700 transition-colors duration-300 transform dark:text-gray-200 hover:text-gray-600 dark:hover:text-gray-300">
                                    <div class="relative inline-block">
                                        <!-- Notifications button -->
                                        <button
                                            @click="showMenu = false; showNotifications = !showNotifications"
                                            :disabled="notifications.length === 0"
                                            class="relative z-10 block p-2 text-gray-700 bg-white border border-transparent rounded-md dark:text-white focus:border-blue-500 focus:ring-opacity-40 dark:focus:ring-opacity-40 focus:ring-blue-300 dark:focus:ring-blue-400 focus:ring dark:bg-gray-800 focus:outline-none"
                                        >
                                            <svg
                                                :class="notifications.length > 0 ? 'animate-wiggle text-orange-800' : 'text-gray-800'"
                                                class="w-5 h-5"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                xmlns="http://www.w3.org/2000/svg"
                                            >
                                                <path d="M12 22C10.8954 22 10 21.1046 10 20H14C14 21.1046 13.1046 22 12 22ZM20 19H4V17L6 16V10.5C6 7.038 7.421 4.793 10 4.18V2H13C12.3479 2.86394 11.9967 3.91762 12 5C12 5.25138 12.0187 5.50241 12.056 5.751H12C10.7799 5.67197 9.60301 6.21765 8.875 7.2C8.25255 8.18456 7.94714 9.33638 8 10.5V17H16V10.5C16 10.289 15.993 10.086 15.979 9.9C16.6405 10.0366 17.3226 10.039 17.985 9.907C17.996 10.118 18 10.319 18 10.507V16L20 17V19ZM17 8C16.3958 8.00073 15.8055 7.81839 15.307 7.477C14.1288 6.67158 13.6811 5.14761 14.2365 3.8329C14.7919 2.5182 16.1966 1.77678 17.5954 2.06004C18.9942 2.34329 19.9998 3.5728 20 5C20 6.65685 18.6569 8 17 8Z" fill="currentColor"></path>
                                            </svg>
                                        </button>

                                        <!-- Dropdown menu -->
                                        <div v-if="showNotifications && notifications.length > 0"
                                             style="width:250px"
                                             class="absolute right-0 z-20 mt-2 origin-top-right bg-white rounded-md shadow-lg dark:bg-gray-800"
                                        >
                                            <Notifications2
                                                :notifications="notifications"
                                            />
                                        </div>
                                    </div>
                                </div>


                                <!-- Menu button -->
                                <div class="relative text-gray-700 transition-colors duration-300 transform dark:text-gray-200 hover:text-gray-600 dark:hover:text-gray-300">
                                    <div class="relative inline-block ">
                                        <!-- Dropdown toggle button -->
                                        <button @click="showNotifications = false; showMenu = !showMenu" class="relative z-10 flex items-center p-2 text-sm text-gray-600 bg-white border border-transparent rounded-md focus:border-blue-500 focus:ring-opacity-40 dark:focus:ring-opacity-40 focus:ring-blue-300 dark:focus:ring-blue-400 focus:ring dark:text-white dark:bg-gray-800 focus:outline-none">
                                            <span class="mx-1">{{user.name}}</span>
                                            <svg class="w-5 h-5 mx-1" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M12 15.713L18.01 9.70299L16.597 8.28799L12 12.888L7.40399 8.28799L5.98999 9.70199L12 15.713Z" fill="currentColor"></path>
                                            </svg>
                                        </button>

                                        <!-- Dropdown menu -->
                                        <div v-if="showMenu"
                                             class="absolute right-0 z-20 w-56 py-2 mt-2 overflow-hidden origin-top-right bg-white rounded-md shadow-xl dark:bg-gray-800"
                                        >
                                            <Link :href="route('profile.edit')" class="flex items-center p-3 -mt-2 text-sm text-gray-600 transition-colors duration-300 transform dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 dark:hover:text-white">
                                                <div class="mx-1">
                                                    <h1 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                                        {{user.name}}
                                                    </h1>
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                                        {{user.email}}
                                                    </p>
                                                </div>
                                            </Link>

                                            <hr class="border-gray-200 dark:border-gray-700 ">


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
                                                :route="route('suppliers.index',business.id)"
                                                label="Suppliers"
                                                icon="fa-solid fa-cubes"
                                            />
                                            <!-- Profile -->
                                            <NavButton
                                                v-if="onboarded"
                                                :route="route('profile.edit')"
                                                label="Profile"
                                                icon="fa-solid fa-gear"
                                            />
                                            <!-- Users -->
                                            <NavButton
                                                v-if="isAdmin"
                                                :route="route('admin.users.index')"
                                                label="Users (Admin)"
                                                icon="fa-solid fa-users"
                                            />
                                            <!-- Telescope -->
                                            <a
                                                v-if="isAdmin"
                                                class="flex items-center px-3 py-2 text-gray-600 transition-colors duration-300 transform rounded-lg dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 dark:hover:text-gray-200 hover:text-gray-700"
                                                href="/telescope/exceptions"
                                            >
                                                <i class="fa-solid fa-bug"></i>
                                                <span class="mx-2 text-sm font-medium">Telescope (Admin)</span>
                                            </a>
                                            <!-- Update Materials -->
                                            <NavButton
                                                v-if="isAdmin"
                                                :route="route('admin.update.master.materials.spreadsheet')"
                                                label="Update Materials (Admin)"
                                                icon="fa-solid fa-file-excel"
                                                :alert="!hasSeedImport"
                                            />
                                            <!-- Logout -->
                                            <Link
                                                :href="route('logout')"
                                                method="post"
                                                class="pt-1 pl-2 font-bold flex text-gray-500 transition-colors duration-200 dark:text-gray-400 rtl:rotate-0 hover:text-blue-500 dark:hover:text-blue-400"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mt-1">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                                </svg>
                                                <span class="pl-2" style="margin-top:2px">Logout</span>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
                <!-- Mobile menu -->
                <div class="lg:hidden">
                    <button aria-label="Open Menu" title="Open Menu" class="p-2 -mr-1 transition duration-200 rounded focus:outline-none focus:shadow-outline">
                        <svg class="w-5 text-gray-600" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M23,13H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,13,23,13z"></path>
                            <path fill="currentColor" d="M23,6H1C0.4,6,0,5.6,0,5s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,6,23,6z"></path>
                            <path fill="currentColor" d="M23,20H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,20,23,20z"></path>
                        </svg>
                    </button>
                    <!-- Mobile menu dropdown
                    <div class="absolute top-0 left-0 w-full">
                      <div class="p-5 bg-white border rounded shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                          <div>
                            <a href="/" aria-label="Company" title="Company" class="inline-flex items-center">
                              <svg class="w-8 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">
                                <rect x="3" y="1" width="7" height="12"></rect>
                                <rect x="3" y="17" width="7" height="6"></rect>
                                <rect x="14" y="1" width="7" height="6"></rect>
                                <rect x="14" y="11" width="7" height="12"></rect>
                              </svg>
                              <span class="ml-2 text-xl font-bold tracking-wide text-gray-800 uppercase">Company</span>
                            </a>
                          </div>
                          <div>
                            <button aria-label="Close Menu" title="Close Menu" class="p-2 -mt-2 -mr-2 transition duration-200 rounded hover:bg-gray-200 focus:bg-gray-200 focus:outline-none focus:shadow-outline">
                              <svg class="w-5 text-gray-600" viewBox="0 0 24 24">
                                <path
                                  fill="currentColor"
                                  d="M19.7,4.3c-0.4-0.4-1-0.4-1.4,0L12,10.6L5.7,4.3c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l6.3,6.3l-6.3,6.3 c-0.4,0.4-0.4,1,0,1.4C4.5,19.9,4.7,20,5,20s0.5-0.1,0.7-0.3l6.3-6.3l6.3,6.3c0.2,0.2,0.5,0.3,0.7,0.3s0.5-0.1,0.7-0.3 c0.4-0.4,0.4-1,0-1.4L13.4,12l6.3-6.3C20.1,5.3,20.1,4.7,19.7,4.3z"
                                ></path>
                              </svg>
                            </button>
                          </div>
                        </div>
                        <nav>
                          <ul class="space-y-4">
                            <li><a href="/" aria-label="Our product" title="Our product" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Product</a></li>
                            <li><a href="/" aria-label="Our product" title="Our product" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Features</a></li>
                            <li><a href="/" aria-label="Product pricing" title="Product pricing" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Pricing</a></li>
                            <li><a href="/" aria-label="About us" title="About us" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">About us</a></li>
                            <li><a href="/" aria-label="Sign in" title="Sign in" class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400">Sign in</a></li>
                            <li>
                              <a
                                href="/"
                                class="inline-flex items-center justify-center w-full h-12 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                                aria-label="Sign up"
                                title="Sign up"
                              >
                                Sign up
                              </a>
                            </li>
                          </ul>
                        </nav>
                      </div>
                    </div>
                    -->
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-5" :style="'height:'+underNavScreenHeight+'px'">
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
<!--                        :route="route('suppliers.index',business.id)"-->
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
<!--                    &lt;!&ndash; Update Materials &ndash;&gt;-->
<!--                    <NavButton-->
<!--                        v-if="isAdmin"-->
<!--                        :route="route('admin.update.master.materials.spreadsheet')"-->
<!--                        label="Update Materials (Admin)"-->
<!--                        icon="fa-solid fa-file-excel"-->
<!--                        :alert="!hasSeedImport"-->
<!--                    />-->
<!--                </nav>-->

<!--                <div class="mt-6">-->
<!--                    <Notifications2/>-->
<!--                </div>-->
<!--            </div>-->
<!--        </aside>-->

        <!-- Main -->
        <main class="col-span-7 overflow-y-auto pl-4 pr-4 bg-[#f9fafb]"><!--bg-gradient-to-tr from-blue-100 via-indigo-100 to-gray-100-->
            <slot/>
        </main>
    </div>
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
