<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/CardButtonBlue.vue";

    //Props
    const props = defineProps({
        info: Object,
        type: String,
    });

    //Form
    const formBreakBatch = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','showQuotesModal','showOrdersModal','pageLoadingOn','pageLoadingOff','showBom','showNesting']);
    const user = computed(() => usePage().props.auth.user);

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Methods
    function breakBatch(){
        let url = route("batches.destroy",props.info.batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success after re-nest');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function allOrdersSent(){
        return props.info.sentOrdersQty === props.info.totalOrdersQty;
    }
</script>

<template>
    <!-- card -->
    <div
        :class="shared.atLeastOneProjectIsYours(info.projects.data,user.id) ? 'bg-white' : 'bg-gray-200'"
        class="relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group"
    >
        <div
            v-if="type === 'QUOTES' && shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mb-2 text-center"
        >
            <p class="text-sm text-gray-700">
                Quote coverage: <b>{{ info.sentQuotesQty }}/{{ info.totalQuotesQty }}</b>
            </p>
        </div>

        <div
            v-if="type === 'ORDERS' && shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mb-2 text-center"
        >
            <p class="text-sm text-gray-700">
                Order coverage: <b>{{ info.sentOrdersQty }}/{{ info.totalOrdersQty }}</b>
            </p>
            <p v-if="info.all_project_manager_approvals" class="text-sm text-green-700">
                All project managers approved
            </p>
        </div>


        <div
            :class="shared.atLeastOneProjectIsYours(info.projects.data,user.id) ? '' : 'mt-2'"
            class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-500"
        >
            <div
                v-for="project in info.projects.data"
                :class="shared.isYourProject(project,user.id) ? 'border-green-100' : 'border-gray-300'"
                class="w-full rounded-lg border-2 p-2"
            >
                <div class="grid grid-cols-6">
                    <h4 class="col-span-4 text-base font-medium">
                        {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
                    </h4>
                    <span class="col-span-2 text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,5)}}</span>
                </div>

                <div
                    v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
                    class="flex justify-between mt-2"
                >
                    <div  class="flex items-center">
                        <i class="fa-regular fa-calendar-days text-2xl"></i>
                        <!-- Quote by -->
                        <div v-if="type === 'QUOTES'">
                            <span class="ml-1 text-xs">Quote by:</span>
                            <span class="block ml-1 leading-none text-xs">{{ moment(project.quoteRequestDeadline).format("DD-MM-YYYY")}}</span>
                        </div>
                        <!-- Order by -->
                        <div v-if="type === 'ORDERS'">
                            <span class="ml-1 text-xs">Order by:</span>
                            <span class="block ml-1 leading-none text-xs">{{ moment(project.orderDeadline).format("DD-MM-YYYY")}}</span>
                        </div>
                    </div>

                    <div
                        v-if="shared.isYourProject(project,user.id)"
                        class="flex items-center ml-4"
                    >
                        <CardButtonGreen
                            @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                            :label="project.qtyMaterialRows"
                            :highlight="false"
                            :icon="true"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="flex w-full justify-center mt-2"
        >
            <CardButtonBlue
                @click="$emit('pageLoadingOn',null);$emit('showNesting',info.batch.id)"
                label="Nesting details"
                :highlight="false"
            />
        </div>

        <div
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="mt-3 w-full flex gap-x-2 justify-between items-center"
        >
            <!-- Quote actions -->
            <CardButtonRed
                v-if="type === 'QUOTES'"
                @click="$emit('pageLoadingOn',3); breakBatch()"
                label="Re-nest"
            />

<!--            <CardButtonGreen-->
<!--                v-if="type === 'QUOTES'"-->
<!--                @click="$emit('showQuotesModal',info.batch.id)"-->
<!--                label="Quotes"-->
<!--                :highlight="true"-->
<!--                :icon="false"-->
<!--            />-->
            <Link
                v-if="type === 'QUOTES'"
                :href="route('quote.order.management',props.info.batch.id)"
                class="w-full"
            >
                <CardButtonGreen
                    label="Quotes"
                    :highlight="true"
                    :icon="false"
                />
            </Link>

            <!-- Order actions -->
<!--            <CardButtonGreen-->
<!--                v-if="type === 'ORDERS'"-->
<!--                @click="$emit('showOrdersModal',info.batch.id)"-->
<!--                label="Orders"-->
<!--                :highlight="true"-->
<!--                :icon="false"-->
<!--            />-->

            <Link
                v-if="type === 'ORDERS'"
                :href="route('quote.order.management',props.info.batch.id)"
                class="w-full"
            >
                <CardButtonGreen
                    label="Orders"
                    :highlight="true"
                    :icon="false"
                />
            </Link>
        </div>
        <p
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mt-2 text-xs block text-center text-orange-300"
        >
            <span v-if="type === 'QUOTES'">{{ shared.quoteDeadlineMessage(info.projects.data,false) }}</span>
            <span v-if="type === 'ORDERS'">{{ shared.orderDeadlineMessage(info.projects.data, allOrdersSent())}}</span>
        </p>
    </div>
</template>
