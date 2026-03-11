<?php
/**
 * Date Input Component
 *
 * A unified date input component for consistent date/datetime handling
 * across the application. Supports both date and datetime-local types.
 *
 * Usage as include (set variables before including):
 *   $date_name = 'start_date';
 *   $date_value = '2026-01-15';
 *   $date_label = t('Start Date');
 *   include BASE_PATH . '/includes/components/date-input.php';
 *
 * Usage as function (preferred):
 *   echo render_date_input('start_date', '2026-01-15', [
 *       'label' => t('Start Date'),
 *       'required' => true
 *   ]);
 *
 * Variables (for include method):
 * - $date_name (string, required) - Input name attribute
 * - $date_value (string, optional) - Input value (Y-m-d or Y-m-d\TH:i format)
 * - $date_label (string, optional) - Label text
 * - $date_type (string, optional) - 'date' or 'datetime-local', defaults to 'date'
 * - $date_id (string, optional) - Input ID, defaults to $date_name
 * - $date_required (bool, optional) - Whether field is required
 * - $date_hint (string, optional) - Helper text below input
 * - $date_min (string, optional) - Minimum date
 * - $date_max (string, optional) - Maximum date
 */

// Allow both include-based and function-based usage
if (!function_exists('render_date_input')) {
    /**
     * Render a date input field with consistent styling
     *
     * @param string $name Input name attribute
     * @param string $value Input value (date format: Y-m-d, datetime format: Y-m-d\TH:i)
     * @param array $options {
     *     @type string $type       Input type: 'date' or 'datetime-local' (default: 'date')
     *     @type string $id         Input ID attribute (default: same as $name)
     *     @type string $label      Label text (optional)
     *     @type bool   $required   Whether the field is required (default: false)
     *     @type string $hint       Helper text displayed below input (optional)
     *     @type string $min        Minimum allowed date (optional)
     *     @type string $max        Maximum allowed date (optional)
     *     @type string $class      Additional CSS classes (optional)
     *     @type array  $attrs      Additional HTML attributes as key=>value (optional)
     * }
     * @return string HTML output
     */
    function render_date_input($name, $value = '', $options = []) {
        $type = $options['type'] ?? 'date';
        $id = $options['id'] ?? $name;
        $label = $options['label'] ?? null;
        $required = !empty($options['required']);
        $hint = $options['hint'] ?? null;
        $min = $options['min'] ?? null;
        $max = $options['max'] ?? null;
        $class = $options['class'] ?? '';
        $attrs = $options['attrs'] ?? [];

        // Build attributes string
        $attr_parts = [];
        $attr_parts[] = 'type="' . e($type) . '"';
        $attr_parts[] = 'id="' . e($id) . '"';
        $attr_parts[] = 'name="' . e($name) . '"';
        $attr_parts[] = 'value="' . e($value) . '"';
        $attr_parts[] = 'class="form-input' . ($class ? ' ' . e($class) : '') . '"';

        if ($required) {
            $attr_parts[] = 'required';
        }
        if ($min !== null) {
            $attr_parts[] = 'min="' . e($min) . '"';
        }
        if ($max !== null) {
            $attr_parts[] = 'max="' . e($max) . '"';
        }

        // Add custom attributes
        foreach ($attrs as $attr_name => $attr_value) {
            if ($attr_value === true) {
                $attr_parts[] = e($attr_name);
            } elseif ($attr_value !== false && $attr_value !== null) {
                $attr_parts[] = e($attr_name) . '="' . e($attr_value) . '"';
            }
        }

        $attrs_str = implode(' ', $attr_parts);

        // Build output
        $html = '';

        if ($label !== null) {
            $html .= '<label for="' . e($id) . '" class="block text-sm font-medium text-gray-700 mb-1">';
            $html .= e($label);
            if ($required) {
                $html .= ' <span class="text-red-500">*</span>';
            }
            $html .= '</label>';
        }

        $html .= '<input ' . $attrs_str . '>';

        if ($hint !== null) {
            $html .= '<p class="mt-1 text-xs text-gray-500">' . e($hint) . '</p>';
        }

        return $html;
    }

    /**
     * Format a datetime string for datetime-local input
     *
     * Converts database datetime format to HTML datetime-local format
     *
     * @param string $datetime Database datetime string (Y-m-d H:i:s)
     * @return string Formatted for datetime-local input (Y-m-d\TH:i)
     */
}

// Include-based usage support (when file is included with variables set)
if (isset($date_name)) {
    $date_value = $date_value ?? '';
    $date_options = [
        'type' => $date_type ?? 'date',
        'id' => $date_id ?? $date_name,
        'label' => $date_label ?? null,
        'required' => $date_required ?? false,
        'hint' => $date_hint ?? null,
        'min' => $date_min ?? null,
        'max' => $date_max ?? null,
    ];

    echo render_date_input($date_name, $date_value, $date_options);

    // Clean up variables to prevent conflicts
    unset($date_name, $date_value, $date_type, $date_id, $date_label,
          $date_required, $date_hint, $date_min, $date_max, $date_options);
}

