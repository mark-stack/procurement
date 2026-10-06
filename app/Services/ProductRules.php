<?php

namespace App\Services;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use Illuminate\Validation\Rule;

/**
 * What a valid product looks like, in one place.
 *
 * Both ways into the catalogue use this: the admin form one row at a time, and the JSON importer a
 * batch at a time. They were not allowed to drift - a rule tightened on the form and not on the
 * importer would just mean the importer became the way to get bad data in.
 */
class ProductRules
{
    /**
     * Everything either route may write. business_id is not here: this is the platform catalogue,
     * and a product handed a business_id would disappear into one business's private price book.
     */
    public const EDITABLE = [
        'description', 'product_category', 'material', 'grade', 'surface', 'certificates',
        'nominal_length', 'precise_length', 'nominal_width', 'precise_width',
        'nominal_height', 'precise_height', 'wall',
        'pack_size_1', 'pack_size_2', 'pack_size_3', 'kg_per_m', 'baseline_supplier',
        'accepted_reason', 'deprecated',
    ];

    /**
     * Columns the CATEGORY decides, never the row.
     *
     * Every one of the 1,150 rows the spreadsheet held already agreed with its category's
     * implementation on both, and a row that disagreed would nest by the wrong algorithm or be
     * measured in the wrong unit while looking perfectly ordinary on screen. Neither can be typed
     * or imported - they are filled in from the implementation.
     */
    public const DERIVED = ['nesting_algo', 'nominal_units'];

    /**
     * Stored as strings because the spreadsheet always did and every consumer compares them as the
     * strings they are, but nothing except a number belongs in one - "9000mm" in a nominal length
     * matches no piece spec ever written.
     */
    public const MEASUREMENTS = [
        'nominal_length', 'precise_length', 'nominal_width', 'precise_width',
        'nominal_height', 'precise_height', 'wall', 'kg_per_m',
    ];

    public const PACK_SIZES = ['pack_size_1', 'pack_size_2', 'pack_size_3'];

    /**
     * Columns the products table refuses to hold null, and which have held '' for every blank since
     * the first import.
     */
    public const NOT_NULL = ['description', 'material', 'grade', 'surface'];

    /**
     * @param  bool  $tolerateBlankSpec  True when editing something that already exists. The
     *                                   catalogue inherited rows with no grade at all, and refusing
     *                                   to save one would mean its mass per metre could never be
     *                                   corrected without first rewriting a column the product is
     *                                   matched on. Nothing new needs to start out unmatchable.
     * @return array<string, mixed>
     */
    public static function rules(bool $tolerateBlankSpec): array
    {
        $blankSpecRule = $tolerateBlankSpec ? 'present' : 'required';

        $rules = [
            /*
             * Three real RHS products in the catalogue carry no description at all, so it is
             * nullable rather than required - but it is the only human-readable handle on a row.
             */
            'description' => ['nullable', 'string', 'max:255'],

            'product_category' => ['required', Rule::enum(ProductEnums::class)],

            /*
             * Enum-or-blank. One inherited row holds "SS316" in its material column, which is a
             * GRADE - the screen flags it rather than hiding it, and this is what stops another
             * being saved.
             */
            'material' => [$blankSpecRule, 'nullable', Rule::enum(MaterialEnums::class)],
            'grade' => [$blankSpecRule, 'nullable', Rule::enum(GradeEnums::class)],
            'surface' => [$blankSpecRule, 'nullable', Rule::enum(SurfaceEnums::class)],

            /*
             * Null, not false, for a cell nobody has answered yet. where('certificates', true) and
             * where('certificates', false) both exclude null, which is what silently dropped
             * products out of the mill certificate sense check - so "no" and "nobody said" have to
             * stay distinguishable.
             */
            'certificates' => ['present', 'nullable', 'boolean'],

            'baseline_supplier' => ['nullable', 'string', 'max:1000'],

            /*
             * Why a row the trust report flags is being kept as it is - see Services\CatalogueTrust.
             *
             * 'nullable' rather than 'present', unlike every other column here. Everything else on
             * this list describes the product, so a body that omitted one would be leaving a column
             * alone by accident; this one describes a DECISION about the product, and a form or an
             * import that does not mention it is not making that decision. Requiring it present
             * would also mean every JSON file exported before this column existed was refused.
             */
            'accepted_reason' => ['nullable', 'string', 'max:1000'],

            'deprecated' => ['required', 'boolean'],
        ];

        foreach (self::MEASUREMENTS as $column) {
            $rules[$column] = ['present', 'nullable', 'numeric', 'min:0'];
        }

        foreach (self::PACK_SIZES as $column) {
            $rules[$column] = ['present', 'nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    /**
     * Single purpose: turn the blank strings a form sends for every untouched field into the nulls
     * the rules expect. 'nullable' does not catch '', so "" would fail 'numeric' on every
     * measurement the category does not use.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function blanksToNull(array $input): array
    {
        foreach (self::EDITABLE as $column) {
            if (($input[$column] ?? null) === '') {
                $input[$column] = null;
            }
        }

        return $input;
    }

    /**
     * Single purpose: the attributes to write - validated input, plus the two columns the category
     * decides, plus the blank strings the NOT NULL columns have always held.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated): array
    {
        $attributes = array_intersect_key($validated, array_flip(self::EDITABLE));

        $implementation = (new DataClassificationService)
            ->findImplementationFromProductCategory((string) ($attributes['product_category'] ?? ''));

        if ($implementation) {
            $attributes['nesting_algo'] = $implementation->config()['algorithm']->value;
            $attributes['nominal_units'] = $implementation->config()['measurementUnit']->value;
        }

        foreach (self::NOT_NULL as $column) {
            $attributes[$column] ??= '';
        }

        return $attributes;
    }
}
