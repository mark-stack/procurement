<script setup>
    //General Imports
    import moment from "moment";
    import {computed, ref} from "vue";
    import {usePage} from "@inertiajs/vue3";

    //Component Imports
    import CardButtonGreen from "@/Components/Buttons/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/Buttons/CardButtonRed.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import CardButtonForward from "@/Components/Buttons/CardButtonForward.vue";
    import CardButtonExpand from "@/Components/Buttons/CardButtonExpand.vue";

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
    const expand = ref(false);

    //Shared methods
    import shared from '@/Shared/shared';


    //Methods
    //
</script>

<template>
    <!-- card -->
    <div class="bg-white relative flex flex-col items-start p-4 mt-3 rounded-lg group border-[1px] border-gray-300 shadow-lg">
        <div class="w-full grid grid-cols-7 text-gray-900">
            <h4 class="col-span-5 text-base font-medium">
                {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
            </h4>
            <span class="col-span-2 text-xs font-medium text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,6)}}</span>
        </div>
        <div class="flex justify-between gap-x-1 w-full mt-3 text-xs font-medium text-gray-900">
            <CardButtonExpand
                label="Details/Edit"
                :expandedIndex="expandProject"
                :thisIndex="999"
                @click="expand = !expand"
            />
            <CardButtonForward
                label="Add Mat'ls"
                @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
            />
        </div>
        <div
            v-if="expand"
            class="flex justify-between w-full mt-5 text-xs font-medium text-gray-900"
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
            <div
                v-if="shared.isYourProject(project,user.id)"
                class="grid grid-cols-1 gap-y-1"
            >
                <CardButtonGreen
                    @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                    :label="project.qtyMaterialRows + ' pieces'"
                    :highlight="false"
                    :icon="false"
                />
                <CardButtonRed
                    @click="$emit('toggleArchive',project)"
                    label="Archive"
                    :fullWidth="true"
                    :disabled="false"
                />
                <CardButtonYellow
                    @click="$emit('editMode',project)"
                    label="Edit"
                    :fullWidth="true"
                />
            </div>
        </div>
    </div>
</template>
