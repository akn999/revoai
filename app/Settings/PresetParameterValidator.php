<?php

namespace App\Settings;

use Illuminate\Support\Facades\Validator;

/**
 * Validates advanced image parameters against a model's JSON-schema-like catalog entry (FR-IMG-005).
 * Supported keywords: type, minimum, maximum, enum, maxLength.
 */
class PresetParameterValidator
{
    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $values
     *
     * @throws SettingsException
     */
    public function validate(?array $schema, array $values, string $prefix = 'params'): array
    {
        $properties = (array) data_get($schema, 'properties', []);
        $rules = [];

        foreach ($properties as $name => $definition) {
            $rule = ['nullable'];
            $type = $definition['type'] ?? 'string';
            $rule[] = match ($type) {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                default => 'string',
            };

            if (isset($definition['minimum'])) {
                $rule[] = 'min:'.$definition['minimum'];
            }

            if (isset($definition['maximum'])) {
                $rule[] = 'max:'.$definition['maximum'];
            }

            if (isset($definition['maxLength'])) {
                $rule[] = 'max:'.$definition['maxLength'];
            }

            if (isset($definition['enum'])) {
                $rule[] = 'in:'.implode(',', $definition['enum']);
            }

            $rules[$name] = $rule;
        }

        $unknown = array_diff(array_keys($values), array_keys($properties));
        $validator = Validator::make($values, $rules);

        if ($unknown !== [] || $validator->fails()) {
            $errors = [];

            foreach ($unknown as $name) {
                $errors["{$prefix}.{$name}"] = ["The {$name} parameter is not supported by this model."];
            }

            foreach ($validator->errors()->toArray() as $name => $messages) {
                $errors["{$prefix}.{$name}"] = $messages;
            }

            throw new SettingsException('Invalid parameters.', $errors);
        }

        return $values;
    }
}
