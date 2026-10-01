<script setup>
    //General Imports
    import {computed, ref, watch} from "vue";
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        show: Boolean,
        /**
         * One row of the quotes/orders table - this screen reads its goodsReceipt block. Null between
         * openings.
         */
        row: Object,
        supplierName: String,
        /**
         * The nonconformance reasons, from App\Enums\GoodsReceiptNonconformanceEnums::options(). Sent
         * once for the whole modal rather than per row - they are the same seven every time.
         */
        nonconformanceOptions: {
            type: Array,
            default: () => [],
        },
    });

    //Forms
    /*
     * heat_numbers is keyed by bar id, which is what the controller scopes against the order's own
     * bars. The three checks start as null rather than false: "nobody has answered" is a real state
     * and the one every delivery starts in - see Order::receiptAccepted.
     */
    const form = useForm({
        docket_number: null,
        quantity_verified: null,
        grade_verified: null,
        nonconformance: null,
        note: null,
        heat_numbers: {},
    });

    //Variables
    const emit = defineEmits(['closeModal','refresh']);

    //Computed
    const receipt = computed(() => props.row?.goodsReceipt ?? null);
    const bars = computed(() => receipt.value?.bars ?? []);

    const selectedReason = computed(
        () => props.nonconformanceOptions.find(option => option.value === form.nonconformance) ?? null
    );

    //A note is only demanded for "Other" - see the enum's requiresNote()
    const noteRequired = computed(() => selectedReason.value?.requiresNote === true);

    /*
     * The contradiction the request refuses, said before the round trip rather than after it: a receipt
     * cannot pass both checks and also name something wrong with the load.
     */
    const contradicts = computed(
        () => form.quantity_verified === true
            && form.grade_verified === true
            && !!form.nonconformance
    );

    const heatNumbersRecorded = computed(
        () => bars.value.filter(bar => !!form.heat_numbers[bar.id]).length
    );

    //Methods
    function resetFromRow(){
        form.reset();
        form.clearErrors();

        /*
         * Seeded from whatever is already on the bars. The form posts every bar whether or not it has
         * been filled in, and the request drops the blanks - so a second visit shows what was typed
         * the first time instead of a row of empty boxes that would look like nothing was recorded.
         */
        const heatNumbers = {};
        bars.value.forEach(bar => heatNumbers[bar.id] = bar.heat_number ?? '');
        form.heat_numbers = heatNumbers;
    }

    function answer(field, value){
        form[field] = value;

        /*
         * Clearing the fault when both checks come back clean, rather than letting the server refuse
         * it. The person has just said the load was right; making them hunt for the select they set
         * two minutes ago is the kind of form that gets abandoned at a gate.
         */
        if(form.quantity_verified === true && form.grade_verified === true){
            form.nonconformance = null;
        }
    }

    function bookIn(){
        form.post(route('order.mark.delivered', receipt.value.order_id), {
            preserveScroll: true,
            onSuccess: () => {
                emit('refresh');
                emit('closeModal');
            },
        });
    }

    function checkLabel(value){
        if(value === true){
            return 'Yes';
        }

        return value === false ? 'No' : 'Not checked';
    }

    //Watchers
    //A fresh fetch replaces the row object, so the boxes have to be re-seeded from it
    watch(() => props.row, () => resetFromRow(), {immediate: true});
    watch(() => props.show, (isOpen) => {
        if(isOpen){
            resetFromRow();
        }
    });
</script>

<template>
    <Modal
        v-if="show && receipt"
        :fakeModal="false"
        labelledby="goods-receipt-title"
        @closeModal="$emit('closeModal')"
    >
        <!-- header -->
        <div class="px-5 pb-3 pt-2">
            <h3 id="goods-receipt-title" class="text-xl font-medium leading-6 text-gray-900">
                {{ receipt.received ? 'Goods receipt' : 'Book this delivery in' }}
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ supplierName }}
            </p>
        </div>

        <!-- body -->
        <div
            :style="{width: 'min(640px, calc(100vw - 2rem))'}"
            class="max-h-[70vh] overflow-y-auto px-5 pb-5"
        >
            <!--
                Already booked in. Read-only, and deliberately: a receipt records what somebody saw at
                a particular moment, so there is nothing here to edit. The same reasoning stops a
                certificate being deleted off a placed order.
            -->
            <template v-if="receipt.received">
                <p
                    v-if="receipt.accepted === true"
                    class="flex gap-2 rounded-lg border border-green-200 bg-green-50 p-2.5 text-xs leading-relaxed text-green-800"
                >
                    <i class="fa-solid fa-circle-check mt-0.5 flex-none text-green-600"></i>
                    <span>Checked and accepted.</span>
                </p>
                <p
                    v-else-if="receipt.accepted === false"
                    class="flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
                >
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                    <span>
                        Booked in with a problem recorded. The steel counts as delivered and the nest
                        will use it — chase this with the supplier separately.
                    </span>
                </p>
                <p
                    v-else
                    class="flex gap-2 rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-xs leading-relaxed text-gray-700"
                >
                    <i class="fa-regular fa-circle-question mt-0.5 flex-none text-gray-400"></i>
                    <span>Booked in, and the checks were not answered.</span>
                </p>

                <dl class="mt-4 grid grid-cols-[auto,1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-gray-500">Received</dt>
                    <dd class="text-gray-900">{{ receipt.received_at }}</dd>

                    <dt class="text-gray-500">By</dt>
                    <dd class="text-gray-900">{{ receipt.received_by ?? '—' }}</dd>

                    <dt class="text-gray-500">Docket</dt>
                    <dd class="text-gray-900">{{ receipt.docket_number ?? '—' }}</dd>

                    <dt class="text-gray-500">Quantity correct</dt>
                    <dd class="text-gray-900">{{ checkLabel(receipt.quantity_verified) }}</dd>

                    <dt class="text-gray-500">Grade correct</dt>
                    <dd class="text-gray-900">{{ checkLabel(receipt.grade_verified) }}</dd>

                    <template v-if="receipt.nonconformance">
                        <dt class="text-gray-500">Problem</dt>
                        <dd class="text-gray-900">{{ receipt.nonconformance }}</dd>
                    </template>

                    <template v-if="receipt.note">
                        <dt class="text-gray-500">Note</dt>
                        <dd class="whitespace-pre-line text-gray-900">{{ receipt.note }}</dd>
                    </template>
                </dl>
            </template>

            <!--
                Delivered before any of this existed, or booked in by something that skipped the form.
                Said out loud rather than drawn as an empty receipt - "arrived, and nobody recorded a
                check" is the honest reading, and it is the answer an auditor gets.
            -->
            <template v-else-if="receipt.deliveredWithoutReceipt">
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-xs leading-relaxed text-amber-800">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-amber-500"></i>
                    <span>
                        This delivery is marked as arrived, but no goods receipt was recorded against
                        it — there is no date, nobody's name, and no record of the load being checked.
                        Deliveries marked before this screen existed all read like this, and it cannot
                        be filled in after the fact.
                    </span>
                </p>
            </template>

            <!-- the form -->
            <template v-else>
                <p class="rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
                    What came off the truck, as you saw it. The date and your name go on the record
                    automatically. Nothing here blocks the delivery — the steel is in the yard either
                    way, and the nest will use it.
                </p>

                <!-- docket -->
                <section class="mt-4">
                    <label for="docket-number" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Delivery docket
                    </label>
                    <p class="mt-1 text-xs text-gray-500">
                        The number on the paperwork the driver handed over — how this delivery is found
                        again in the filing cabinet.
                    </p>
                    <input
                        id="docket-number"
                        v-model="form.docket_number"
                        type="text"
                        class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="e.g. DN-884213"
                    />
                    <p v-if="form.errors.docket_number" class="mt-1.5 text-xs font-medium text-red-600">
                        {{ form.errors.docket_number }}
                    </p>
                </section>

                <!-- the two checks -->
                <section class="mt-5">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Checks
                    </h4>
                    <p class="mt-1 text-xs text-gray-500">
                        "Not checked" is a real answer and the one that is recorded if you leave it —
                        it is not the same as "no".
                    </p>

                    <div
                        v-for="check in [
                            {field: 'quantity_verified', label: 'Quantity matches the order'},
                            {field: 'grade_verified', label: 'Grade and size match the order'},
                        ]"
                        :key="check.field"
                        class="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white p-2.5"
                    >
                        <span class="text-sm text-gray-800">{{ check.label }}</span>
                        <div class="flex flex-none gap-1">
                            <button
                                v-for="option in [
                                    {value: true, label: 'Yes'},
                                    {value: false, label: 'No'},
                                    {value: null, label: 'Not checked'},
                                ]"
                                :key="String(option.value)"
                                type="button"
                                @click="answer(check.field, option.value)"
                                class="rounded px-2 py-1 text-xs font-medium"
                                :class="form[check.field] === option.value
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- nonconformance -->
                <section class="mt-5">
                    <label for="receipt-nonconformance" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Anything wrong with it?
                    </label>
                    <p class="mt-1 text-xs text-gray-500">
                        Which of these keeps happening, to which merchant, is the thing worth knowing.
                    </p>
                    <select
                        id="receipt-nonconformance"
                        v-model="form.nonconformance"
                        :disabled="form.quantity_verified === true && form.grade_verified === true"
                        class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100"
                    >
                        <option :value="null">Nothing — the load was fine</option>
                        <option
                            v-for="option in nonconformanceOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p v-if="selectedReason" class="mt-1.5 text-xs text-gray-500">
                        {{ selectedReason.hint }}
                    </p>
                    <p v-if="form.errors.nonconformance" class="mt-1.5 text-xs font-medium text-red-600">
                        {{ form.errors.nonconformance }}
                    </p>
                </section>

                <!-- note -->
                <section class="mt-4">
                    <label for="receipt-note" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Note {{ noteRequired ? '(required)' : '(optional)' }}
                    </label>
                    <textarea
                        id="receipt-note"
                        v-model="form.note"
                        rows="2"
                        class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :placeholder="noteRequired
                            ? 'Say what happened — a reason of &quot;Other&quot; on its own cannot be acted on later'
                            : 'e.g. two bars short, Kev is chasing Monday\'s load'"
                    ></textarea>
                    <p v-if="form.errors.note" class="mt-1.5 text-xs font-medium text-red-600">
                        {{ form.errors.note }}
                    </p>
                </section>

                <!--
                    Heat numbers. The one place in the application where a part can be tied to the heat
                    it was rolled from rather than to a list of certificates it might have come off -
                    so it belongs on the screen that is open while the docket is in somebody's hand.
                -->
                <section v-if="bars.length > 0" class="mt-5">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Heat numbers
                        <span class="ml-1 font-normal normal-case tracking-normal text-gray-400">
                            {{ heatNumbersRecorded }} of {{ bars.length }} recorded
                        </span>
                    </h4>
                    <p class="mt-1 text-xs text-gray-500">
                        Off the mill certificate, one per bar. Optional — without them the trail still
                        reports which certificates the steel could have come from, which is as far as
                        it can go. With them, every cut off that bar names its own heat.
                    </p>

                    <ul class="mt-2.5 space-y-1.5">
                        <li
                            v-for="bar in bars"
                            :key="bar.id"
                            class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-2"
                        >
                            <span class="w-28 flex-none truncate text-sm text-gray-800" :title="bar.label">
                                {{ bar.label }}
                            </span>
                            <span class="w-20 flex-none text-xs tabular-nums text-gray-400">
                                {{ bar.length }}mm
                            </span>
                            <input
                                v-model="form.heat_numbers[bar.id]"
                                type="text"
                                class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                :placeholder="'Heat / cast number'"
                                :aria-label="'Heat number for ' + bar.label + ' ' + bar.length + 'mm'"
                            />
                        </li>
                    </ul>
                </section>

                <p
                    v-if="contradicts"
                    class="mt-4 flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
                >
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                    <span>
                        Both checks say the load was correct, so it cannot also record a problem.
                        Clear whichever one is wrong.
                    </span>
                </p>
            </template>
        </div>

        <template #footer>
            <button
                v-if="receipt.canReceive"
                type="button"
                @click="bookIn()"
                :disabled="form.processing || contradicts"
                class="mt-3 inline-flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
            >
                {{ form.processing ? 'Booking in...' : 'Book in' }}
            </button>
            <button
                type="button"
                @click="$emit('closeModal')"
                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
            >
                {{ receipt.canReceive ? 'Cancel' : 'Done' }}
            </button>
        </template>
    </Modal>
</template>
