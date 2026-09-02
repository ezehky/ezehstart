<flux:input
    {{
        $attributes->merge([
            'label' => 'Phone Number',
            'placeholder' => 'e.g. 08012345678',
            'badge' => 'required',
        ])
    }}
    clearable
    inputmode="numeric"
/>
