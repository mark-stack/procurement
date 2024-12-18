<script setup>
    //General Imports
    import { ref } from 'vue';
    import {Link, usePage} from '@inertiajs/vue3';

    //Component Imports
    import ApplicationLogo from '@/Components/ApplicationLogo.vue';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownLink from '@/Components/DropdownLink.vue';
    import NavLink from '@/Components/NavLink.vue';
    import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
    import Notifications from "@/Components/Notifications.vue";
    import Notifications2 from "@/Components/Notifications2.vue";
    import NavButton from "@/Components/NavButton.vue";

    //Props
    // const props = defineProps({
    //     xxx: Object,
    // });

    //Form
    //...

    //Shared data
    const business = usePage().props.auth.business;
    const isAdmin = usePage().props.auth.isAdmin;
    const onboarded = usePage().props.auth.onboarded;

    //Variables
    //...

    //Methods
    //...

</script>

<template>
    <div class="grid grid-cols-5">

        <!--sidebar -->
        <aside class="col-span-1 h-screen px-2 py-8 overflow-y-auto bg-white border-r dark:bg-gray-900 dark:border-gray-700">
            <div class="flex justify-between">
                <a href="/" class="pl-3 font-extrabold">
                    PROCUREMENT
                </a>
                <!-- Logout -->
                <Link
                    :href="route('logout')"
                    method="post"
                    class="text-gray-500 transition-colors duration-200 rotate-180 dark:text-gray-400 rtl:rotate-0 hover:text-blue-500 dark:hover:text-blue-400"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                </Link>
            </div>


            <div class="flex flex-col justify-start flex-1 mt-3">
                <nav class="">
                    <!-- Onboarding -->
                    <NavButton
                        v-if="!onboarded"
                        :route="route('onboarding')"
                        label="Onboarding"
                        icon="fa-solid fa-list-check"
                    />
                    <!-- Projects -->
                    <NavButton
                        v-if="onboarded"
                        :route="route('dashboard')"
                        label="Projects"
                        icon="fa-solid fa-list"
                    />
                    <!-- Quotes -->
                    <NavButton
                        v-if="onboarded"
                        :route="route('quotes.index')"
                        label="Quote Requests"
                        icon="fa-solid fa-comments-dollar"
                    />
                    <!-- Price Book -->
                    <NavButton
                        v-if="onboarded"
                        :route="route('pricebook')"
                        label="Price Book"
                        icon="fa-solid fa-list"
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
                    />
                </nav>

                <div class="mt-6">
                    <Notifications2/>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <main class="col-span-4 h-screen overflow-y-auto pl-4 pr-4">
            <slot />
        </main>
    </div>
</template>
