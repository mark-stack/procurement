<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    //Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Every entry in config/TableTemplates.php, reduced to what identifies it: the source
     * that produced the spreadsheet and the entry's label. The pair is unique across the
     * config, and it is what a recorded template points at.
     *
     * Detection reads the config and nothing else, so this is one-way on purpose - a row
     * names a config entry, a config entry knows nothing about any row.
     *
     * @return array<int, array{source: string, config_label: string, nominal_units: string}>
     */
    public static function detectionOptions(): array
    {
        $options = [];

        foreach (config('TableTemplates') as $entry) {
            $options[] = [
                'source' => $entry['source'],
                'config_label' => $entry['label'],
                //Shown next to the option so the recorded units can be read against it
                'nominal_units' => $entry['nominalUnits'],
            ];
        }

        return $options;
    }

    /**
     * The config entry this row documents, or null if it names none - either because it
     * predates source/config_label, or because the entry it named has since been renamed
     * or removed. The screen shows the difference, which is the whole point of recording
     * the pair.
     *
     * @return array<string, mixed>|null
     */
    public function detectionConfig(): ?array
    {
        if (! $this->source || ! $this->config_label) {
            return null;
        }

        foreach (config('TableTemplates') as $entry) {
            if ($entry['source'] === $this->source && $entry['label'] === $this->config_label) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The screenshot's media type, read off the data URL it is stored as.
     *
     * Only the types StoreTemplateRequest accepts can be stored, so this is a lookup
     * rather than a guess - and it deliberately never answers image/svg+xml, which would
     * let a stored screenshot carry script when served as a file.
     */
    public function screenshotMimeType(): ?string
    {
        if (! preg_match('/^data:(image\/(?:png|jpeg|jpg|gif|webp));base64,/', (string) $this->screenshot, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The screenshot as the bytes of an image file, or null if what is stored is not a
     * data URL this app wrote. Rows created before the data-URL rule existed can hold
     * anything at all, so the caller has to handle null.
     */
    public function decodedScreenshot(): ?string
    {
        if (! $this->screenshotMimeType()) {
            return null;
        }

        $base64 = substr((string) $this->screenshot, strpos((string) $this->screenshot, ',') + 1);

        //strict: reject anything that is not valid base64 rather than decoding it loosely
        $bytes = base64_decode($base64, true);

        return $bytes === false ? null : $bytes;
    }
}
