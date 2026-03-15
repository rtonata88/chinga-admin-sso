import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { InputTextarea } from 'primereact/inputtextarea';
import { Dropdown } from 'primereact/dropdown';
import { MultiSelect } from 'primereact/multiselect';
import { Calendar } from 'primereact/calendar';
import { InputSwitch } from 'primereact/inputswitch';
import { Password } from 'primereact/password';
import type { FieldConfig } from '@/types/acumatica';

interface Props {
    field: FieldConfig;
    value: any;
    error?: string;
    onChange: (value: any) => void;
}

export default function FieldRenderer({ field, value, error, onChange }: Props) {
    const labelClasses = 'block text-xs font-medium uppercase tracking-wide text-[var(--acu-text-muted)] mb-1';
    const inputClasses = `w-full ${error ? 'p-invalid' : ''}`;

    const renderInput = () => {
        switch (field.type) {
            case 'text':
            case 'email':
            case 'tel':
                return (
                    <InputText
                        value={value || ''}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder={field.placeholder}
                        disabled={field.disabled}
                        className={inputClasses}
                        type={field.type}
                    />
                );

            case 'number':
                return (
                    <InputNumber
                        value={value}
                        onValueChange={(e) => onChange(e.value)}
                        placeholder={field.placeholder}
                        disabled={field.disabled}
                        className={inputClasses}
                    />
                );

            case 'textarea':
                return (
                    <InputTextarea
                        value={value || ''}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder={field.placeholder}
                        disabled={field.disabled}
                        className={inputClasses}
                        rows={3}
                        autoResize
                    />
                );

            case 'select':
                return (
                    <Dropdown
                        value={value}
                        options={field.options || []}
                        onChange={(e) => onChange(e.value)}
                        placeholder={field.placeholder || 'Select...'}
                        disabled={field.disabled}
                        className={inputClasses}
                        showClear
                    />
                );

            case 'multiselect':
                return (
                    <MultiSelect
                        value={value || []}
                        options={field.options || []}
                        onChange={(e) => onChange(e.value)}
                        placeholder={field.placeholder || 'Select...'}
                        disabled={field.disabled}
                        className={inputClasses}
                        display="chip"
                        filter
                    />
                );

            case 'date':
                return (
                    <Calendar
                        value={value ? new Date(value) : null}
                        onChange={(e) => onChange(e.value)}
                        placeholder={field.placeholder}
                        disabled={field.disabled}
                        className={inputClasses}
                        dateFormat="yy-mm-dd"
                        showIcon
                    />
                );

            case 'toggle':
                return (
                    <InputSwitch
                        checked={!!value}
                        onChange={(e) => onChange(e.value)}
                        disabled={field.disabled}
                    />
                );

            case 'password':
                return (
                    <Password
                        value={value || ''}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder={field.placeholder}
                        disabled={field.disabled}
                        className={inputClasses}
                        toggleMask
                        feedback={false}
                    />
                );

            default:
                return (
                    <InputText
                        value={value || ''}
                        onChange={(e) => onChange(e.target.value)}
                        className={inputClasses}
                    />
                );
        }
    };

    return (
        <div>
            <label className={labelClasses}>
                {field.label}
                {field.required && <span className="text-red-500 ml-0.5">*</span>}
            </label>
            {renderInput()}
            {field.helpText && !error && (
                <small className="block mt-1 text-xs text-[var(--acu-text-light)]">{field.helpText}</small>
            )}
            {error && <small className="block mt-1 text-xs text-red-600">{error}</small>}
        </div>
    );
}
