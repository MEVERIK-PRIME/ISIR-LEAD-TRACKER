<?php

return [
    'sources' => [
        'public_ws_url' => env('ISIR_PUBLIC_WS_URL', 'https://isir.justice.cz/isir_public_ws/webservice'),
        'document_base_url' => env('ISIR_DOCUMENT_BASE_URL', 'https://isir.justice.cz/isir/common/stat.do'),
        'use_hlidac_statu' => env('ENABLE_HLIDAC_STATU', true),
    ],

    'filter' => [
        'section' => 'B',
        'proceeding' => 'konkurs',
        'final_report_token' => env('ISIR_FINAL_REPORT_TOKEN', 'konec'),
        'lead_min_claim_amount' => (int) env('LEAD_MIN_CLAIM_AMOUNT', 300000),
        'lead_max_claim_amount' => (int) env('LEAD_MAX_CLAIM_AMOUNT', 600000),
    ],

    'qualification' => [
        'creditor_name_blacklist' => [
            'banka',
            'bank',
            'pojistovna',
            'insurance',
            'ministerstvo',
            'urad',
            'úřad',
            'financni urad',
            'finanční úřad',
            'celni urad',
            'celní úřad',
            'sprava socialniho zabezpeceni',
            'správa sociálního zabezpečení',
            'zdravotni pojistovna',
            'zdravotní pojišťovna',
            'statni fond',
            'státní fond',
            'obec',
            'mesto',
            'město',
            'kraj',
            'ceska republika',
            'česká republika',
        ],
        'excluded_legal_form_codes' => ['325', '331', '801', '804'],
        'excluded_nace_codes' => ['64190', '64920', '651'],
        'allow_natural_person_without_ico' => true,
    ],

    'llm' => [
        'primary_provider' => env('LLM_PRIMARY_PROVIDER', 'gemini'),
        'fallback_provider' => env('LLM_FALLBACK_PROVIDER', 'groq'),
        'enable_gemini' => env('ENABLE_GEMINI', true),
        'enable_groq_fallback' => env('ENABLE_GROQ_FALLBACK', true),
    ],

    'sync' => [
        'default_provider' => env('ISIR_SYNC_PROVIDER', 'isir_public_ws'),
        'default_stream' => env('ISIR_SYNC_STREAM', 'events'),
        'batch_size' => (int) env('ISIR_SYNC_BATCH_SIZE', 250),
    ],

    'queue' => [
        'orchestrator_queue' => env('ISIR_ORCHESTRATOR_QUEUE', 'default'),
        'redis_connection' => env('WORKER_TASK_REDIS_CONNECTION', 'default'),
        'worker_task_queue' => env('WORKER_TASK_QUEUE', 'isir:tasks'),
    ],
];
