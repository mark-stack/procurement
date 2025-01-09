<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link} from "@inertiajs/vue3";

    const props = defineProps({
        batch: Object,
        projects: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode']);

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
    <div class="border-2 border-blue-500 rounded-lg">
        <!-- Body -->
        <div class="p-3">
            <span v-if="projects.length > 1" class="text-sm block text-gray-500">Batch ID: {{batch.id}}</span>
            <span v-for="project in projects" class="block">{{ cropText(project.name) }}</span>
        </div>
        <!-- Footer -->
        <div class="border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">
            PM: [You]
            <br>
            Edit | Archive
            <!-- Single project actions -->
            <div v-if="projects.length === 1">
                <!-- Import materials button -->
                <div class="flex justify-center items-center gap-x-6 mt-2">
                    <Link
                        :href="route('products.store',projects[0].id)"
                        :class="projects[0].hasRawMaterialQuotes ? 'text-emerald-500 bg-emerald-100 border-emerald-300 hover:bg-emerald-200' : 'text-orange-500 bg-orange-50 border-orange-300 hover:bg-orange-100'"
                        class="px-2 py-1 rounded border-2 font-semibold"
                    >
                        {{projects[0].hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}
                    </Link>
                </div>

                <!-- actions -->
                <div class="flex justify-center items-center gap-x-6 mt-1">
                    <button
                        @click="$emit('toggleArchive',projects[0])"
                        class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                    >
                        Archive
                    </button>
                    <button v-if="!projects[0].archive" @click="$emit('editMode',projects[0])">
                        Edit
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
