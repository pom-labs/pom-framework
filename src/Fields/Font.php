<?php

namespace POMFramework\Fields;

use POMFramework\POM_Framework;

class Font {

    public static function render_field(array $args): void {
        echo '<div class="form-group">';

        if (!empty($args['label'])) {
            echo '<label for="' . esc_attr($args['id']) . '">' . esc_html($args['label']) . '</label>';
        }

        if (!empty($args['description']) && $args['description_position'] === 'below_label') {
            echo '<small class="description form-text text-muted">' . wp_kses_post($args['description']) . '</small>';
        }

        echo (new POM_Framework())::add_field([
            'type' => 'repeater',
            'title' => __('Fonts', 'pom-framework'),
            'description' => __('Add font families and their variants.', 'pom-framework'),
            'placeholder' => __('Font family', 'pom-framework'),
            'name' => $args['name'],
            'value' => $args['value'],
            'limit' => 5,
            'fields' => [
                [
                    'type' => 'Text',
                    'used_for_title' => true,
                    'label' => __('Font name', 'pom-framework'),
                    'description' => __('Used as the font-family name.', 'pom-framework'),
                    'name' => 'font_name',
                    'class' => 'regular-text',
                    'value' => '',
                ],
                [
                    'type' => 'Select',
                    'label' => __('Font type', 'pom-framework'),
                    'description' => __('Choose normal or variable font files.', 'pom-framework'),
                    'name' => 'font_type',
                    'options' => [
                        'normal' => __('Normal', 'pom-framework'),
                        'variable' => __('Variable', 'pom-framework'),
                    ],
                    'default' => 'normal',
                ],
                [
                    'type' => 'Select',
                    'label' => __('Font fallback', 'pom-framework'),
                    'description' => __('Fallback stack used when the custom font is unavailable.', 'pom-framework'),
                    'name' => 'font_fallback',
                    'options' => [
                        'sans_serif' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"',
                        'serif' => '"Times New Roman", Times, serif', // "Font 1 title", FALLBACK;
                        'monospace' => 'SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
                    ]
                ],
                [
                    'type' => 'repeater',
                    'label' => __('Font variants', 'pom-framework'),
                    'title' => __('Font variant', 'pom-framework'),
                    'description' => __('Add variants with weight, style, and files.', 'pom-framework'),
                    'placeholder' => __('Variant', 'pom-framework'),
                    'name' => 'font_variant',
                    'limit' => 18,
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['normal'],
                            ],
                        ],
                    ],
                    'fields' => [
                        [
                            'type' => 'Text',
                            'used_for_title' => true,
                            'label' => __('Variant name', 'pom-framework'),
                            'description' => __('Label for this variant (e.g., Regular 400).', 'pom-framework'),
                            'name' => 'variant_name',
                            'class' => 'regular-text',
                            'value' => '',
                        ],
                        [
                            'type' => 'Select',
                            'label' => __('Font weight', 'pom-framework'),
                            'description' => __('CSS font-weight for this variant.', 'pom-framework'),
                            'name' => 'font_weight',
                            'options' => [
                                '100' => __('100, thin', 'pom-framework'),
                                '200' => __('200, extra light', 'pom-framework'),
                                'lighter' => __('100, lighter', 'pom-framework'),
                                '300' => __('300, light', 'pom-framework'),
                                '400' => __('400, normal', 'pom-framework'),
                                '500' => __('500, medium', 'pom-framework'),
                                '600' => __('600, semibold', 'pom-framework'),
                                '700' => __('700, bold', 'pom-framework'),
                                '800' => __('800, extra bold', 'pom-framework'),
                                '900' => __('900, black', 'pom-framework'),
                                'bolder' => __('900, bolder', 'pom-framework'),
                            ],
                        ],
                        [
                            'type' => 'Select',
                            'label' => __('Font style', 'pom-framework'),
                            'description' => __('CSS font-style for this variant.', 'pom-framework'),
                            'name' => 'font_style',
                            'options' => [
                                'normal' => __('Normal', 'pom-framework'),
                                'italic' => __('Italic', 'pom-framework'),
                                'oblique' => __('Oblique', 'pom-framework'),
                            ],
                        ],
                        [
                            'type' => 'Font_Picker',
                            'label' => __('Font files', 'pom-framework'),
                            'description' => __('Select font files for this variant.', 'pom-framework'),
                            'name' => 'font_variants',
                        ],
                    ]
                ],
                [
                    'type' => 'Font_Picker',
                    'label' => __('Variable font files', 'pom-framework'),
                    'description' => __('Upload WOFF2 and WOFF files for the variable font.', 'pom-framework'),
                    'name' => 'font_variable_files',
                    'allowed_extensions' => ['woff2', 'woff'],
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['variable'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'Text',
                    'label' => __('Font weight range', 'pom-framework'),
                    'description' => __('Use two values, e.g. "100 900".', 'pom-framework'),
                    'placeholder' => '100 900',
                    'name' => 'font_variable_weight_range',
                    'class' => 'regular-text',
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['variable'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'Text',
                    'label' => __('Font stretch range', 'pom-framework'),
                    'description' => __('Use two percentages, e.g. "75% 125%".', 'pom-framework'),
                    'placeholder' => '75% 125%',
                    'name' => 'font_variable_stretch_range',
                    'class' => 'regular-text',
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['variable'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'Select',
                    'label' => __('Font style', 'pom-framework'),
                    'description' => __('CSS font-style for this variable font.', 'pom-framework'),
                    'name' => 'font_variable_style',
                    'options' => [
                        'normal' => __('Normal', 'pom-framework'),
                        'italic' => __('Italic', 'pom-framework'),
                        'oblique' => __('Oblique', 'pom-framework'),
                    ],
                    'default' => 'normal',
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['variable'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'Select',
                    'label' => __('Font display', 'pom-framework'),
                    'description' => __('Controls font loading behavior.', 'pom-framework'),
                    'name' => 'font_variable_display',
                    'options' => [
                        'auto' => __('Auto', 'pom-framework'),
                        'block' => __('Block', 'pom-framework'),
                        'swap' => __('Swap', 'pom-framework'),
                        'fallback' => __('Fallback', 'pom-framework'),
                        'optional' => __('Optional', 'pom-framework'),
                    ],
                    'default' => 'swap',
                    'dependency' => [
                        [
                            [
                                'field' => 'font_type',
                                'values' => ['variable'],
                            ],
                        ],
                    ],
                ],
            ]
        ]);

        if (!empty($args['description']) && $args['description_position'] === 'under_field') {
            echo '<small class="description form-text text-muted">' . wp_kses_post($args['description']) . '</small>';
        }

        echo '</div>';
    }

}
