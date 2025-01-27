<script setup>
    //General Imports
    import {ref} from "vue";
    import {useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        notifications: Object,
    });

    //Form
    const formNotificationMarkStatus = useForm({
        id:null,
        status: null,
    });

    //Shared data
    //

    //Variables
    const hasNotifications = ref(props.notifications.length > 0);

    //Methods
    function notificationMarkStatus(id,index,status){
        let url = route("mark.notification.status");
        formNotificationMarkStatus.id = id;
        formNotificationMarkStatus.status = status;
        formNotificationMarkStatus.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
                formNotificationMarkStatus.reset();

                //Remove from array
                props.notifications.splice(index, 1);

                //Disabled red bell if no more notifications
                if(props.notifications.length === 0){
                    hasNotifications.value = false;
                }
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }
</script>

<template>
    <div class="">
        <!-- Dropdown menu -->
        <div class="w-full mt-2 bg-white rounded-md shadow-lg dark:bg-gray-800">
            <div v-if="hasNotifications" class="py-2">
                <div
                    v-for="(notification,index) in notifications"
                    class="flex items-center px-4 py-3 transition-colors duration-300 transform border-b border-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700 dark:border-gray-700"
                >
                    <div class="mx-2 text-sm text-gray-600 dark:text-white">
                        <span class="font-semibold">{{ notification.message }}</span>
                        <!-- footer -->
                        <div class="flex justify-between text-xs mt-1">
                            <span class="text-gray-500">{{notification.timestamp}}</span>
                            <p
                                @click="notificationMarkStatus(notification.id,index,'GREEN')"
                                class="text-blue-500 font-bold"
                                :style="formNotificationMarkStatus.processing ? 'pointer-events: none;' : 'cursor: pointer;'"
                            >
                                Mark as read
                            </p>
                        </div>
<!--                        &lt;!&ndash; More actions (trafficLights) &ndash;&gt;-->
<!--                        <div v-if="notification.trafficLights !== null" class="grid grid-cols-3 gap-x-1 justify-between mt-2">-->
<!--                            <p-->
<!--                                v-if="notification.trafficLights.green"-->
<!--                                @click="notificationMarkStatus(notification.id,index,'GREEN')"-->
<!--                                class="text-blue-500 font-bold bg-green-50 p-2 rounded text-center"-->
<!--                                style="cursor: pointer;"-->
<!--                            >-->
<!--                                {{notification.trafficLights.green[0]}}<br><small>{{notification.trafficLights.green[1]}}</small>-->
<!--                            </p>-->
<!--                            <p-->
<!--                                v-if="notification.trafficLights.yellow"-->
<!--                                @click="notificationMarkStatus(notification.id,index,'YELLOW')"-->
<!--                                class="text-blue-500 font-bold bg-yellow-50 p-2 rounded text-center"-->
<!--                                style="cursor: pointer;"-->
<!--                            >-->
<!--                                {{notification.trafficLights.yellow[0]}}<br><small>{{notification.trafficLights.yellow[1]}}</small>-->
<!--                            </p>-->
<!--                            <p-->
<!--                                v-if="notification.trafficLights.red"-->
<!--                                @click="notificationMarkStatus(notification.id,index,'RED')"-->
<!--                                class="text-blue-500 font-bold bg-red-50 p-2 rounded text-center"-->
<!--                                style="cursor: pointer;"-->
<!--                            >-->
<!--                                {{notification.trafficLights.red[0]}}<br><small>{{notification.trafficLights.red[1]}}</small>-->
<!--                            </p>-->
<!--                        </div>-->
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
