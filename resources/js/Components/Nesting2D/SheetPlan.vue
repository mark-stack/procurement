<script setup>
    //General Imports
    import {computed} from "vue";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        /** One sheet or one drawn remnant, as Services\TwoDimensionalNestingProof shapes it. */
        piece: Object,
        /**
         * The widest piece anywhere in this scenario.
         *
         * Every drawing on the tab is scaled against the same number rather than to its own width, which
         * is the whole visual argument: a third-generation remnant has to LOOK like what is left of the
         * sheet four steps ago. Scaled individually they all come out the same size and the chain
         * disappears. The same reasoning as ProofPiece, which does this in one dimension.
         */
        scaleMm: Number,
    });

    //Variables
    /*
     * Text inside the drawing is sized against the SCENARIO's scale rather than the piece's own, so a
     * label on a small remnant is the same size on screen as a label on a full sheet. The svg is given a
     * viewBox in millimetres, so a font-size here is in millimetres too - and dividing the scale by 45
     * lands at roughly 11px once the widest piece is drawn at its full width.
     */
    const FONT_MM = computed(() => props.scaleMm / 45);

    //Computed
    //This piece's width as a share of the widest piece in the scenario
    const widthPct = computed(() => Math.max(4, props.piece.width / props.scaleMm * 100));

    /*
     * Every rectangle on the drawing, in one list, each knowing what it is and whether there is room to
     * write in it. Worked out here rather than in the markup because "is there room" is a question about
     * the rendered size, and the markup only knows millimetres.
     */
    const rectangles = computed(() => [
        ...props.piece.placements.map(part => ({
            ...part,
            kind: 'part',
            //The size as CUT, which for a turned part is not the size as ordered - hence the glyph
            label: `${part.width}×${part.height}`,
            badge: part.rotated ? `${part.letter} ↻` : part.letter,
        })),
        ...props.piece.banked.map(rect => ({
            ...rect,
            kind: 'banked',
            label: `${rect.width}×${rect.height}`,
            badge: rect.mark,
        })),
        ...props.piece.scrap.map(rect => ({
            ...rect,
            kind: 'scrap',
            label: `${rect.width}×${rect.height}`,
            badge: null,
        })),
    ].map(rect => ({
        ...rect,
        roomForLabel: rect.width >= FONT_MM.value * 5 && rect.height >= FONT_MM.value * 2.2,
        roomForBadge: rect.width >= FONT_MM.value * 5 && rect.height >= FONT_MM.value * 4,
    })));

    //The ones too small to write in are named underneath instead, which is what the 1D drawings do
    const unlabelled = computed(() => rectangles.value.filter(rect => !rect.roomForLabel));

    //Methods
    function m2(mm2) {
        return `${(Number(mm2) / 1000000).toFixed(3)}m²`;
    }

    function fillFor(kind) {
        if(kind === 'part'){
            return '#e0f2fe';
        }

        //Passed all three tests, so this is the rectangle that would become an inventory record with a mark
        if(kind === 'banked'){
            return '#d1fae5';
        }

        return '#ffe4e6';
    }

    function strokeFor(kind) {
        if(kind === 'part'){
            return '#0369a1';
        }

        if(kind === 'banked'){
            return '#047857';
        }

        return '#be123c';
    }

    function textFor(kind) {
        if(kind === 'part'){
            return '#0c4a6e';
        }

        if(kind === 'banked'){
            return '#065f46';
        }

        return '#881337';
    }
</script>

<template>
    <div class="py-2">
        <!-- Drawing. Border on the piece, a line around every rectangle: one torch pass each -->
        <div class="w-full">
            <div :style="{width: widthPct + '%'}">
                <svg
                    :viewBox="`0 0 ${piece.width} ${piece.height}`"
                    class="block w-full rounded-sm border-2 border-gray-900 bg-white"
                    preserveAspectRatio="xMidYMid meet"
                    role="img"
                    :aria-label="`${piece.width} by ${piece.height} millimetre piece carrying ${piece.placements.length} parts`"
                >
                    <g v-for="(rect, index) in rectangles" :key="index">
                        <rect
                            :x="rect.x"
                            :y="rect.y"
                            :width="rect.width"
                            :height="rect.height"
                            :fill="fillFor(rect.kind)"
                            :stroke="strokeFor(rect.kind)"
                            :stroke-width="Math.max(1, scaleMm / 600)"
                        />
                        <text
                            v-if="rect.roomForLabel"
                            :x="rect.x + rect.width / 2"
                            :y="rect.y + rect.height / 2 + (rect.roomForBadge && rect.badge ? -FONT_MM * 0.3 : FONT_MM * 0.35)"
                            :font-size="FONT_MM"
                            :fill="textFor(rect.kind)"
                            text-anchor="middle"
                            font-weight="600"
                        >{{ rect.label }}</text>
                        <text
                            v-if="rect.roomForBadge && rect.badge"
                            :x="rect.x + rect.width / 2"
                            :y="rect.y + rect.height / 2 + FONT_MM * 1.2"
                            :font-size="FONT_MM"
                            :fill="textFor(rect.kind)"
                            text-anchor="middle"
                            font-weight="700"
                            :font-style="rect.kind === 'banked' ? 'italic' : 'normal'"
                        >{{ rect.badge }}</text>
                    </g>
                </svg>
            </div>
        </div>

        <!-- Caption: the arithmetic, spelled out so the drawing can be checked rather than trusted -->
        <p class="mt-1.5 text-xs leading-relaxed text-gray-600">
            <span class="tabular-nums">
                {{ m2(piece.balance.parts) }} of parts
                + {{ m2(piece.balance.kerf) }} of kerf
                + {{ m2(piece.balance.banked) }} racked
                + {{ m2(piece.balance.scrap) }} binned
                = <strong class="font-semibold" :class="piece.balance.ok ? 'text-gray-900' : 'text-red-700'">
                    {{ m2(piece.balance.total) }}
                </strong>
            </span>
            <span v-if="!piece.balance.ok" class="ml-1 font-semibold text-red-700">
                &mdash; which is not the {{ m2(piece.areaMm2) }} it came off.
            </span>
            <span v-if="unlabelled.length > 0" class="ml-1 text-gray-500">
                Too small to label:
                <template v-for="(rect, index) in unlabelled" :key="index">
                    <template v-if="index > 0">, </template>
                    <strong class="font-semibold text-gray-700">{{ rect.label }}</strong>
                    <template v-if="rect.kind === 'part'"> ({{ rect.badge }})</template>
                    <template v-else-if="rect.kind === 'banked'"> racked as {{ rect.badge }}</template>
                    <template v-else> binned</template>
                </template>.
            </span>
        </p>
    </div>
</template>
