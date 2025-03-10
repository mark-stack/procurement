<script setup>
    //General Imports
    import {Link, usePage} from "@inertiajs/vue3";
    import {computed, ref} from "vue";

    //Component Imports
    //...

    //Props
    // const props = defineProps({
    //     xxx: Object,
    // });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const showMobileNav = ref(false);
    const user = computed(() => usePage().props.auth.user);

    //Shared Methods
    //...

    //Methods
    //...
</script>

<template>
    <!-- nav -->
    <div class="bg-gray-900">
        <div class="px-4 py-2 mx-auto sm:max-w-xl md:max-w-full lg:max-w-screen-xl md:px-24 lg:px-8">
            <div class="relative flex items-center justify-between">
                <div class="flex items-center">
                    <Link href="/" aria-label="Company" title="Company" class="inline-flex items-center mr-8">
                        <svg class="w-8 text-teal-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">
                            <rect x="3" y="1" width="7" height="12"></rect>
                            <rect x="3" y="17" width="7" height="6"></rect>
                            <rect x="14" y="1" width="7" height="6"></rect>
                            <rect x="14" y="11" width="7" height="12"></rect>
                        </svg>
                        <span class="ml-2 text-xl font-bold tracking-wide text-gray-100 uppercase">Steel Minima</span>
                    </Link>
                    <ul class="flex items-center hidden space-x-8 lg:flex">
                        <li>
                            <Link
                                href="/#nesting"
                                aria-label="Nesting"
                                title="Try Nesting"
                                class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                            >
                                Nesting
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/#pricing"
                                aria-label="Product pricing"
                                title="Product pricing"
                                class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                            >
                                Pricing
                            </Link>
                        </li>
                    </ul>
                </div>
                <ul class="flex items-center hidden space-x-8 lg:flex">
                    <li>
                        <Link
                            v-if="!user"
                            :href="route('login')"
                            aria-label="Sign in"
                            title="Sign in"
                            class="font-medium tracking-wide text-gray-100 transition-colors duration-200 hover:text-teal-accent-400"
                        >
                            Sign in
                        </Link>
                    </li>
                    <li>
                        <Link
                            v-if="user"
                            :href="route('projects.index')"
                            class="inline-flex items-center justify-center h-10 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                            aria-label="Dashboard"
                            title="Dashboard"
                        >
                            Dashboard
                        </Link>
                        <Link
                            v-else
                            :href="route('register')"
                            class="inline-flex items-center justify-center h-10 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                            aria-label="Sign up"
                            title="Sign up"
                        >
                            Sign up
                        </Link>
                    </li>
                </ul>
                <!-- Mobile menu -->
                <div class="lg:hidden">
                    <button @click="showMobileNav = true" aria-label="Open Menu" title="Open Menu" class="p-2 -mr-1 transition duration-200 rounded focus:outline-none focus:shadow-outline">
                        <svg class="w-5 text-gray-600" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M23,13H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,13,23,13z"></path>
                            <path fill="currentColor" d="M23,6H1C0.4,6,0,5.6,0,5s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,6,23,6z"></path>
                            <path fill="currentColor" d="M23,20H1c-0.6,0-1-0.4-1-1s0.4-1,1-1h22c0.6,0,1,0.4,1,1S23.6,20,23,20z"></path>
                        </svg>
                    </button>
                    <!-- Mobile menu dropdown -->
                    <div v-show="showMobileNav" class="absolute top-0 left-0 w-full">
                        <div class="p-5 bg-white border rounded shadow-sm">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <Link href="/" aria-label="Company" title="Company" class="inline-flex items-center">
                                        <svg class="w-8 text-deep-purple-accent-400" viewBox="0 0 24 24" stroke-linejoin="round" stroke-width="2" stroke-linecap="round" stroke-miterlimit="10" stroke="currentColor" fill="none">
                                            <rect x="3" y="1" width="7" height="12"></rect>
                                            <rect x="3" y="17" width="7" height="6"></rect>
                                            <rect x="14" y="1" width="7" height="6"></rect>
                                            <rect x="14" y="11" width="7" height="12"></rect>
                                        </svg>
                                        <span class="ml-2 text-xl font-bold tracking-wide text-gray-800 uppercase">Steel Minima</span>
                                    </Link>
                                </div>
                                <div>
                                    <button
                                        @click="showMobileNav = false"
                                        aria-label="Close Menu"
                                        title="Close Menu"
                                        class="p-2 -mt-2 -mr-2 transition duration-200 rounded hover:bg-gray-200 focus:bg-gray-200 focus:outline-none focus:shadow-outline"
                                    >
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
                                    <li>
                                        <a
                                            href="/#pricing"
                                            aria-label="Product pricing"
                                            title="Product pricing"
                                            class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400"
                                        >
                                            Pricing
                                        </a>
                                    </li>
                                    <li>
                                        <a
                                            href="/#nesting"
                                            aria-label="Nesting"
                                            title="Nesting"
                                            class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400"
                                        >
                                            Nesting
                                        </a>
                                    </li>
                                    <li>
                                        <Link
                                            v-if="!user"
                                            :href="route('login')"
                                            aria-label="Sign in"
                                            title="Sign in"
                                            class="font-medium tracking-wide text-gray-700 transition-colors duration-200 hover:text-deep-purple-accent-400"
                                        >
                                            Sign in
                                        </Link>
                                    </li>
                                    <li>
                                        <Link
                                            v-if="user"
                                            :href="route('dashboard')"
                                            class="inline-flex items-center justify-center h-12 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                                            aria-label="Dashboard"
                                            title="Dashboard"
                                        >
                                            Dashboard
                                        </Link>
                                        <Link
                                            v-else
                                            :href="route('register')"
                                            class="inline-flex items-center justify-center h-12 px-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-700 focus:shadow-outline focus:outline-none"
                                            aria-label="Sign up"
                                            title="Sign up"
                                        >
                                            Sign up
                                        </Link>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
