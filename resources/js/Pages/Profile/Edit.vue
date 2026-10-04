<script setup>
    //General Imports
    import {Head, Link} from '@inertiajs/vue3';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import DeleteUserForm from './Partials/DeleteUserForm.vue';
    import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
    import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
    import UpdateBusinessPreferencesForm from './Partials/UpdateBusinessPreferencesForm.vue';

    //Props
    defineProps({
        mustVerifyEmail: {
            type: Boolean,
        },
        status: {
            type: String,
        },
        //The business's own settings, which are not this user's - see UpdateBusinessPreferencesForm
        businessPreferences: {
            type: Object,
        },
        canEditBusinessPreferences: {
            type: Boolean,
        },
    });

    //Shared data

</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="mb-3">
                    <!--
                        One destination. This was a conditional - "Onboarding" for a business whose
                        templates an admin had not yet recorded, projects for everybody else - and
                        before that it was labelled "Onboarding" while linking to projects, which
                        BusinessReadyMiddleware bounced straight back. There is no onboarding state
                        now, so there is one label and one link.
                    -->
                    <Link
                        class="font-semibold px-3 py-2 text-gray-800 transition-colors duration-300 transform rounded-lg hover:text-deep-purple-accent-400"
                        :href="route('dashboard')"
                    >
                        <i class="fa-regular fa-hand-point-left pr-2"></i> Current Projects
                    </Link>
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <!--
                    The business's settings rather than this user's, which is why it is last and says
                    as much in its own heading - see UpdateBusinessPreferencesForm.
                -->
                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateBusinessPreferencesForm
                        :business-preferences="businessPreferences"
                        :can-edit-business-preferences="canEditBusinessPreferences"
                        class="max-w-xl"
                    />
                </div>

<!--                <div-->
<!--                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"-->
<!--                >-->
<!--                    <DeleteUserForm class="max-w-xl" />-->
<!--                </div>-->
            </div>
        </div>
    </AuthenticatedLayout>
</template>
