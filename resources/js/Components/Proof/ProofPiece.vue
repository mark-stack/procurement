<script setup>
    //General Imports
    import {computed} from "vue";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        /** One bar or one drawn offcut, as Services\NestingProof shapes it. */
        piece: Object,
        /**
         * The longest piece anywhere in this scenario.
         *
         * Every drawing on the tab is scaled against the same number rather than to its own width, which is
         * the whole visual argument: a second-generation offcut has to LOOK like what is left of the bar
         * three steps ago. Scaled individually they all come out the same length and the chain disappears.
         */
        scaleMm: Number,
    });

    //Variables
    /*
     * Below this share of the full scale a segment is narrower than its own label. Those are named in the
     * caption under the drawing instead, which is what the live nesting screens do with small cuts.
     */
    const LABEL_FLOOR_PCT = 5.5;

    //Computed
    //This piece's width as a share of the widest piece in the scenario
    const widthPct = computed(() => Math.max(1, props.piece.lengthMm / props.scaleMm * 100));

    /*
     * Segments carry their share of their own piece, which is what the css needs. Whether there is room to
     * write in one depends on its share of the SCREEN, so that is worked out here rather than in the markup.
     */
    const segments = computed(() => props.piece.segments.map(segment => ({
        ...segment,
        roomForLabel: (segment.pct * widthPct.value / 100) >= LABEL_FLOOR_PCT,
    })));

    const unlabelled = computed(() => segments.value.filter(segment => !segment.roomForLabel));

    //Methods
    function formatMm(value) {
        return `${Number(value).toLocaleString()}mm`;
    }

    function segmentClass(segment) {
        if(segment.kind === 'cut'){
            return 'bg-sky-100 text-sky-900';
        }

        //">=" the scrap threshold, so this is the block that becomes an Offcut row with a mark on it
        if(segment.kind === 'banked'){
            return 'bg-emerald-100 text-emerald-900';
        }

        return 'bg-rose-100 text-rose-900';
    }
</script>

<template>
    <div class="py-2">
        <!-- Drawing. Border on the piece, hairlines between segments: one blade pass each -->
        <div class="w-full">
            <div
                class="flex h-9 overflow-hidden rounded-sm border-2 border-gray-900 bg-white"
                :style="{width: widthPct + '%'}"
            >
                <div
                    v-for="(segment, index) in segments"
                    :key="index"
                    class="flex items-center justify-center overflow-hidden border-gray-900 text-[11px] font-semibold leading-none"
                    :class="[segmentClass(segment), index > 0 ? 'border-l-2' : '']"
                    :style="{width: segment.pct + '%'}"
                >
                    <template v-if="segment.roomForLabel">
                        <span v-if="segment.kind === 'cut'" class="truncate px-1">
                            {{ segment.lengthMm.toLocaleString() }}<span class="ml-1 font-bold">{{ segment.letter }}</span>
                        </span>
                        <span v-else-if="segment.kind === 'banked'" class="truncate px-1">
                            {{ segment.lengthMm.toLocaleString() }}
                            <span v-if="piece.bankedMark" class="ml-1 font-bold italic">{{ piece.bankedMark }}</span>
                        </span>
                        <span v-else class="truncate px-1">{{ segment.lengthMm.toLocaleString() }} binned</span>
                    </template>
                </div>
            </div>
        </div>

        <!-- Caption: the arithmetic, spelled out so the drawing can be checked rather than trusted -->
        <p class="mt-1.5 text-xs leading-relaxed text-gray-600">
            <span class="tabular-nums">
                {{ formatMm(piece.balance.parts) }} of parts
                + {{ formatMm(piece.balance.kerf) }} of saw kerf
                + {{ formatMm(piece.balance.drop) }}
                <template v-if="piece.dropMm === 0">left</template>
                <template v-else-if="piece.banked">banked</template>
                <template v-else>binned</template>
                = <strong class="font-semibold" :class="piece.balance.ok ? 'text-gray-900' : 'text-red-700'">
                    {{ formatMm(piece.balance.total) }}
                </strong>
            </span>
            <span v-if="!piece.balance.ok" class="ml-1 font-semibold text-red-700">
                &mdash; which is not the {{ formatMm(piece.lengthMm) }} it came off.
            </span>
            <span v-if="unlabelled.length > 0" class="ml-1 text-gray-500">
                Too narrow to label:
                <template v-for="(segment, index) in unlabelled" :key="index">
                    <template v-if="index > 0">, </template>
                    <strong class="font-semibold text-gray-700">{{ segment.lengthMm.toLocaleString() }}</strong>
                    <template v-if="segment.kind === 'cut'"> ({{ segment.letter }})</template>
                    <template v-else-if="segment.kind === 'banked'"> banked</template>
                    <template v-else> binned</template>
                </template>.
            </span>
        </p>
    </div>
</template>
