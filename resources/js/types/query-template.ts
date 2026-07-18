export type QueryTemplateValue = string | number | boolean | null;

export type QueryTemplateParameterOption = {
    value: string;
    label: string;
};

export type QueryTemplateParameterDefinition = {
    key: string;
    label: string;
    description?: string | null;
    type: 'number' | 'integer' | 'string' | 'date' | 'select' | 'boolean';
    required: boolean;
    default?: QueryTemplateValue;
    min?: number;
    max?: number;
    step?: number;
    options?: QueryTemplateParameterOption[];
};

export type QueryTemplateSummary = {
    slug: string;
    name: string;
    description: string | null;
    resource_key: string;
    resource_path: string;
    resource: {
        key: string;
        label: string;
        description?: string;
        domain?: string;
        method?: string;
        path: string;
    } | null;
    category: {
        slug: string;
        name: string;
        color: string | null;
    } | null;
    parameter_definitions: QueryTemplateParameterDefinition[];
};

export type QueryTemplateValues = Record<string, QueryTemplateValue>;
