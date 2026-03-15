<?php

return [
    'defaults' => [
        'fieldset_colors' => [
            'primary' => '#3B82F6',
            'secondary' => '#8B5CF6',
            'success' => '#10B981',
            'warning' => '#F59E0B',
            'info' => '#06B6D4',
            'neutral' => '#6B7280',
        ],
        'grid' => [
            'rows_per_page' => 20,
            'rows_per_page_options' => [10, 20, 50, 100],
            'resizable_columns' => true,
            'reorderable_columns' => true,
            'export_formats' => ['csv', 'xlsx', 'pdf'],
        ],
    ],
    'personalization' => [
        'enabled' => true,
        'allow_user_overrides' => true,
        'admin_can_lock_fields' => true,
    ],
];
