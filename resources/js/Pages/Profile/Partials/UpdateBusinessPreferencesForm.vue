<script setup>
    /**
     * The two lead times the whole business is measured against.
     *
     * Quoting is how long it takes to get prices back from the merchants; delivering is how long the
     * longest-lead material takes to turn up once it has been bought. Added together they are the
     * critical path, which is what every deadline reminder counts back from - so the sum is printed
     * under the boxes rather than left to be worked out: these two numbers are only ever read as
     * "how long before the steel is wanted do we have to start".
     *
     * Not a personal setting, though it sits on the personal page. It is the only business-settings
     * screen a customer has, and changing it changes when every colleague gets chased - which the
     * form says on its face rather than leaving somebody to find out from their inbox.
     */
    //Component Imports
    import InputError from '@/Components/InputError.vue';
    import InputLabel from '@/Components/InputLabel.vue';
    import PrimaryButton from '@/Components/Buttons/PrimaryButton.vue';
    import TextInput from '@/Components/TextInput.vue';

    //General Imports
    import {computed} from 'vue';
    import {useForm} from '@inertiajs/vue3';

    //Props
    const props = defineProps({
        //The figures in force now - see ProfileController::edit
        businessPreferences: Object,
        //False for an account with no business behind it, which has nothing to save against
        canEditBusinessPreferences: Boolean,
    });

    /*
     * Strings, because that is what a number input gives back through v-model and what TextInput's
     * model is typed as - seeding the form with integers makes the first render disagree with every
     * keystroke after it. The server validates them as integers either way.
     */
    const form = useForm({
        quoting_days: String(props.businessPreferences.quoting_days),
        delivery_days: String(props.businessPreferences.delivery_days),
    });

    /*
     * What the two come to, redrawn as they are typed - the number that actually decides when a
     * project starts being chased. Read off the form rather than off the saved values, so the line
     * answers the change being considered rather than the one already in force.
     */
    const criticalPathDays = computed(() => {
        //An empty box is not a zero - Number("") is 0, which would print a figure made out of a blank
        if (form.quoting_days === '' || form.delivery_days === '') {
            return null;
        }

        const quoting = Number(form.quoting_days);
        const delivery = Number(form.delivery_days);

        if (! Number.isFinite(quoting) || ! Number.isFinite(delivery)) {
            return null;
        }

        return quoting + delivery;
    });

    const dayWord = days => days === 1 ? 'working day' : 'working days';
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                Business preferences
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                How long your business takes to get prices back, and how long its material takes to
                arrive once it has been ordered. Every deadline reminder is counted back from these.
            </p>

            <!--
                Said once here rather than left to the "working days" beside each box, because it is
                the difference between a Friday deadline and a Tuesday one and somebody filling this
                in is thinking about how their merchant actually behaves.
            -->
            <p class="mt-1 text-sm text-gray-600">
                Weekends are not counted: a merchant who takes two days to price a Friday request is
                giving you the numbers on the Tuesday.
            </p>

            <!--
                Said plainly rather than implied by the heading. This is the one form on the page that
                is not about the person filling it in, and the cost of not saying so is a colleague
                being chased on a schedule somebody else quietly changed.
            -->
            <p class="mt-1 text-sm text-gray-500">
                These apply to everyone in your business, not just to you.
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('business.preferences.update'))"
            class="mt-6 space-y-6"
        >
            <div>
                <InputLabel for="quoting_days" value="Quoting" />

                <div class="flex items-center gap-2 mt-1">
                    <TextInput
                        id="quoting_days"
                        type="number"
                        min="0"
                        max="365"
                        step="1"
                        class="block w-24"
                        v-model="form.quoting_days"
                        :disabled="! canEditBusinessPreferences"
                        required
                    />

                    <span class="text-sm text-gray-600">working days</span>
                </div>

                <p class="mt-1 text-sm text-gray-500">
                    From sending the quote requests to having the prices in.
                </p>

                <InputError class="mt-2" :message="form.errors.quoting_days" />
            </div>

            <div>
                <InputLabel for="delivery_days" value="Delivering" />

                <div class="flex items-center gap-2 mt-1">
                    <TextInput
                        id="delivery_days"
                        type="number"
                        min="0"
                        max="365"
                        step="1"
                        class="block w-24"
                        v-model="form.delivery_days"
                        :disabled="! canEditBusinessPreferences"
                        required
                    />

                    <span class="text-sm text-gray-600">working days</span>
                </div>

                <p class="mt-1 text-sm text-gray-500">
                    From placing the order to the longest-lead material arriving.
                </p>

                <InputError class="mt-2" :message="form.errors.delivery_days" />
            </div>

            <!--
                The sum, which is the figure anybody actually feels: a project is chased this many days
                before its material is wanted. Hidden while either box is empty rather than printing a
                number made out of a blank.
            -->
            <p v-if="criticalPathDays !== null" class="text-sm text-gray-600">
                A project is chased
                <span class="font-semibold">{{ criticalPathDays }} {{ dayWord(criticalPathDays) }}</span>
                before its material is needed at the workshop.
            </p>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing || ! canEditBusinessPreferences">
                    Save
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-gray-600"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
