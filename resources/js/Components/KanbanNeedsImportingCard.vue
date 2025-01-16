<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link} from "@inertiajs/vue3";

    const props = defineProps({
        projects: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','showBom']);

    //Methods
    function cropText(text, maxLength = 5) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }
</script>

<template>
    <!-- card -->
    <div
        class="border-2 border-blue-500 rounded-lg"
    >
        <!-- Body -->
        <div class="p-3">
            <span v-for="project in projects" class="block">{{ cropText(project.name) }}</span>
        </div>
        <!-- Footer -->
        <div
            class="border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs"
        >
            PM: {{projects[0].projectManager.name}}

            <!-- Single project actions -->
            <div v-if="projects.length === 1">


                <!-- actions -->
                <div class="flex justify-center items-center gap-x-6 mt-1">
                    <Link :href="route('products.index',projects[0].id)">
                        BOM (page)
                    </Link>
                    <button @click="$emit('showBom',projects[0])">
                        BOM (modal)
                    </button>
                    <button @click="$emit('editMode',projects[0])">
                        Edit
                    </button>
                    <button
                        @click="$emit('toggleArchive',projects[0])"
                        class="text-gray-800 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                    >
                        Archive
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
