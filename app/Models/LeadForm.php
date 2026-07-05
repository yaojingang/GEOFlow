<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LeadForm extends Model
{
    private const RESERVED_FIELD_NAMES = [
        'source_url',
        'website',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const FIELD_TYPES = [
        'text',
        'phone',
        'email',
        'textarea',
        'select',
        'checkbox',
    ];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'description',
        'submit_button_label',
        'success_message',
        'fields',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'submit_button_label' => '提交',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LeadSubmission::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return list<array{name:string,label:string,type:string,required:bool,options:list<string>}>
     */
    public function normalizedFields(): array
    {
        return collect(is_array($this->fields) ? $this->fields : [])
            ->filter(static fn (mixed $field): bool => is_array($field))
            ->values()
            ->map(static function (array $field, int $index): array {
                $type = in_array((string) ($field['type'] ?? 'text'), self::FIELD_TYPES, true)
                    ? (string) ($field['type'] ?? 'text')
                    : 'text';
                $label = trim((string) ($field['label'] ?? ''));
                $name = self::normalizeFieldName((string) ($field['name'] ?? ''), $label, $index);

                return [
                    'name' => $name,
                    'label' => $label,
                    'type' => $type,
                    'required' => ! empty($field['required']),
                    'options' => collect($field['options'] ?? [])
                        ->map(fn (mixed $option): string => trim((string) $option))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $field): bool => $field['name'] !== '' && $field['label'] !== '')
            ->unique('name')
            ->values()
            ->all();
    }

    private static function normalizeFieldName(string $name, string $label, int $index): string
    {
        $raw = trim($name) !== '' ? trim($name) : Str::slug($label, '_');
        $raw = Str::lower(Str::ascii($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';
        $raw = trim($raw, '_');

        if ($raw === '' && $label !== '') {
            $raw = 'field_'.($index + 1);
        }

        if (in_array($raw, self::RESERVED_FIELD_NAMES, true)) {
            $raw .= '_field';
        }

        return mb_substr($raw, 0, 60);
    }
}
