<script setup>
    //General Imports
    import moment from "moment";
    import {computed} from "vue";
    import {usePage} from "@inertiajs/vue3";

    //Component Imports
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";

    //Props
    const props = defineProps({
        project: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','showBom','pageLoadingOn']);
    const user = computed(() => usePage().props.auth.user);

    //Shared methods
    import shared from '@/Shared/shared';

    //Methods
    //
</script>

<template>
    <!-- card -->
    <div
        :class="shared.isYourProject(project,user.id) ? 'bg-white' : 'bg-gray-200'"
        class="relative flex flex-col items-start p-4 mt-3 rounded-lg group"
    >
<!--        <h4 class="text-base font-medium">-->
<!--            {{ shared.cropText(shared.capitalizeWords(project.name)) }}-->
<!--        </h4>-->
        <div class="w-full grid grid-cols-7 text-gray-500">
            <h4 class="col-span-5 text-base font-medium">
                {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
            </h4>
            <span class="col-span-2 text-xs font-medium text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,6)}}</span>
        </div>
        <div
            v-if="shared.isYourProject(project,user.id)"
            class="flex justify-between w-full mt-3 text-xs font-medium text-gray-500"
        >
            <div class="flex items-center">
                <table>
                    <tr>
                        <td colspan="2" class="text-xs text-gray-400">Target dates</td>
                    </tr>
                    <tr>
                        <td>Quote:</td>
                        <td><b>{{ moment(project.quotingDeadline).format("D MMM YY")}}</b></td>
                    </tr>
                    <tr>
                        <td>Order:</td>
                        <td><b>{{ moment(project.orderingDeadline).format("D MMM YY")}}</b></td>
                    </tr>
                    <tr>
                        <td>Delivery:</td>
                        <td><b>{{ moment(project.deliveryDeadline).format("D MMM YY")}}</b></td>
                    </tr>
                </table>
            </div>
            <div class="flex items-center ml-4">
                <CardButtonGreen
                    @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                    :label="project.qtyMaterialRows"
                    :highlight="false"
                    :icon="true"
                />
            </div>
        </div>

        <div
            v-if="shared.isYourProject(project,user.id)"
            class="mt-3 w-full flex gap-x-2 justify-between items-center"
        >
            <CardButtonRed
                @click="$emit('toggleArchive',project)"
                label="Archive"
            />
            <CardButtonYellow
                @click="$emit('editMode',project)"
                label="Edit"
            />
            <CardButtonGreen
                @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                label="Materials"
                :highlight="true"
                :icon="false"
            />
        </div>
    </div>
</template>
