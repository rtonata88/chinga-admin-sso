export interface FieldConfig {
    name: string;
    label: string;
    type: 'text' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'multiselect' | 'textarea' | 'toggle' | 'password';
    required?: boolean;
    visible?: boolean;
    order?: number;
    span?: number;
    placeholder?: string;
    helpText?: string;
    disabled?: boolean;
    options?: { label: string; value: string | number }[];
    locked?: boolean;
}

export interface FieldsetConfig {
    id: string;
    label: string;
    icon?: string;
    color?: string;
    collapsed?: boolean;
    order?: number;
    fields: FieldConfig[];
}

export interface FormConfig {
    formName: string;
    fieldsets: FieldsetConfig[];
    gridColumns?: GridColumn[];
    tabOrder?: string[];
}

export interface GridColumn {
    field: string;
    header: string;
    sortable?: boolean;
    filterable?: boolean;
    visible?: boolean;
    width?: string;
    frozen?: boolean;
    type?: 'text' | 'number' | 'date' | 'status' | 'rating' | 'currency' | 'boolean';
}

export interface SavedFilter {
    id?: number;
    filterableType: string;
    name: string;
    isShared?: boolean;
    isFavorite?: boolean;
    isDefault?: boolean;
    criteria: FilterCriterion[];
    sortConfig?: SortConfig;
}

export interface FilterCriterion {
    field: string;
    value: any;
    operator: 'equals' | 'not_equals' | 'contains' | 'starts_with' | 'gt' | 'lt' | 'gte' | 'lte' | 'between' | 'in' | 'is_null' | 'is_not_null';
}

export interface SortConfig {
    field: string;
    order: 'asc' | 'desc';
}

export type StatusVariant = 'active' | 'inactive' | 'suspended' | 'pending' | 'completed' | 'error';
