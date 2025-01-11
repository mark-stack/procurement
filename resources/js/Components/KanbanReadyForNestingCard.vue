<script setup>
    //General Imports
    import {Link, useForm, usePage} from "@inertiajs/vue3";
    import {computed} from "vue";

    //Component Imports
    //...

    //Props


    const props = defineProps({
        projects: Object,
    });

    //Form
    //

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','quoteNow']);
    const user = computed(() => usePage().props.auth.user);

    //Methods
    function cropText(text, maxLength = 13) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "..";
        }
        return text;
    }

    function isYourProject(project){
        return project.user_id == user.value.id;
    }
</script>

<template>
    <!-- card -->
    <div class="border-2 border-blue-500 rounded-lg">
        <!-- Body -->
        <div class="p-3">
            <span class="text-sm block text-gray-500">Auto Batched Nesting</span>
            <span class="text-sm block text-green-500">Saves 13% waste</span>
            <span class="text-xs block text-orange-500">Suggest to wait [3 more days] to allow for more possible materials.</span>
            <Link :href="route('suggested.nesting')" class="font-bold">Nesting details <i class="fa-solid fa-list"/></Link>

            <div class="mt-3 grid grid-cols-1 gap-y-2">
                <!-- project mini card -->
                <div v-for="project in projects" class="p-2 rounded-lg border-2 border-blue-200 bg-blue-50">
                    <h3>{{ cropText(project.name,16) }}</h3>
                    <h6 class="text-xs text-gray-500">Quote/order by [4/2/24]</h6>
                    <div class="text-xs">
                        <div class="grid grid-cols-3 justify-between">
                            <Link
                                :href="route('products.index',projects[0].id)"
                            >
                                BOM
                            </Link>
                            <button
                                @click="$emit('toggleArchive',projects[0])"
                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                            >
                                Archive
                            </button>
                            <button
                                v-if="isYourProject(project)"
                                class="text-gray-500"
                                @click="$emit('editMode',project)"
                            >
                                Edit
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Footer -->
        <div class="grid grid-cols-2 border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">
            <button @click="$emit('quoteNow')">
                Quote now
            </button>
            <button>
                Order now
            </button>
        </div>
    </div>
</template>
