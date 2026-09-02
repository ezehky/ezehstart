<flux:input
    type="number"
    {{
        $attributes->merge([
            'min' => 0,
            'placeholder' => '0-9',
            'step' => 1,
        ])
    }}
/>
