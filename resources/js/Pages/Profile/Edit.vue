<script setup>
    //General Imports
    import {Head, Link, usePage} from '@inertiajs/vue3';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import DeleteUserForm from './Partials/DeleteUserForm.vue';
    import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
    import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

    //Props
    defineProps({
        mustVerifyEmail: {
            type: Boolean,
        },
        status: {
            type: String,
        },
    });

    //Shared data
    const onboarded = usePage().props.auth.onboarded;

</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="mb-3">
                    <Link
                        class="font-semibold px-3 py-2 text-gray-800 transition-colors duration-300 transform rounded-lg hover:text-deep-purple-accent-400"
                        :href="route('projects.index')"
                    >
                        <i class="fa-regular fa-hand-point-left pr-2"></i> {{onboarded ? 'Current Projects' : 'Onboarding'}}
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

<!--                <div-->
<!--                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"-->
<!--                >-->
<!--                    <DeleteUserForm class="max-w-xl" />-->
<!--                </div>-->
            </div>
        </div>
    </AuthenticatedLayout>
</template>
