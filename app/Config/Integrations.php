<?php
/**
 * Integrations.php - Configurações de integrações com serviços externos
 * 
 * Este arquivo contém as configurações para integrações com Mercado Pago,
 * NFE.io, serviços de frete e outros serviços externos usados pelo sistema.
 */

// Impedir acesso direto ao arquivo
if (!defined('BASE_PATH')) {
    exit('Acesso direto não permitido');
}

/**
 * Configurações das integrações
 */
$integrationsConfig = [
    // Mercado Pago (pagamentos)
    'mercadopago' => [
        'enabled' => true,
        'sandbox' => true, // true para ambiente de teste, false para produção
        'public_key' => getenv('MP_PUBLIC_KEY') ?: '',
        'access_token' => getenv('MP_ACCESS_TOKEN') ?: '',
        'client_id' => getenv('MP_CLIENT_ID') ?: '',
        'client_secret' => getenv('MP_CLIENT_SECRET') ?: '',
        'webhook_url' => BASE_URL . 'api/webhooks/mercadopago',
        'success_url' => BASE_URL . 'pagamentos/confirmacao/',
        'failure_url' => BASE_URL . 'pagamentos/cancelado/',
        'pending_url' => BASE_URL . 'pagamentos/pendente/',
        'payment_methods' => [
            'credit_card' => true,
            'boleto' => true,
            'pix' => true,
        ],
        'installments' => [
            'max' => 12,
            'min_value' => 5.00
        ]
    ],
    
    // NFE.io (notas fiscais)
    'nfeio' => [
        'enabled' => true,
        'api_key' => getenv('NFEIO_API_KEY') ?: '',
        'company_id' => getenv('NFEIO_COMPANY_ID') ?: '',
        'webhook_url' => BASE_URL . 'api/webhooks/nfeio',
        'auto_issue' => true, // Emitir nota automaticamente após pagamento aprovado
        'environment' => 'development', // development ou production
        'company_info' => [
            'name' => 'Canabidiol E-commerce LTDA',
            'document' => '00.000.000/0001-00', // CNPJ
            'legal_name' => 'Empresa de Medicamentos a Base de Canabidiol LTDA',
            'email' => 'fiscal@canabidiol-ecommerce.com.br',
            'address' => [
                'street' => 'Av. Paulista',
                'number' => '1000',
                'complement' => 'Sala 123',
                'district' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zipcode' => '01310-100'
            ]
        ]
    ],
    
    // Serviços de frete
    'shipping' => [
        'correios' => [
            'enabled' => true,
            'user' => getenv('CORREIOS_USER') ?: '',
            'password' => getenv('CORREIOS_PASSWORD') ?: '',
            'origin_zipcode' => '01310-100',
            'services' => ['PAC', 'SEDEX'],
            'company_info' => [
                'name' => 'Canabidiol E-commerce',
                'phone' => '(11) 99999-9999',
                'email' => 'contato@canabidiol-ecommerce.com.br'
            ],
            'default_package' => [
                'weight' => 0.3, // kg
                'length' => 16,  // cm
                'width' => 11,   // cm
                'height' => 5    // cm
            ]
        ],
        'transportadora' => [
            'enabled' => true,
            'name' => 'Transportadora Expressa',
            'api_url' => 'https://api.transportadora.com.br',
            'api_key' => getenv('TRANSPORTADORA_API_KEY') ?: '',
            'min_days' => 3,
            'max_days' => 7
        ],
        'free_shipping' => [
            'enabled' => true,
            'min_value' => 300.00 // Valor mínimo para frete grátis
        ]
    ],
    
    // Serviço de CEP
    'zipcode' => [
        'provider' => 'viacep', // viacep, apicep, etc.
        'viacep' => [
            'url' => 'https://viacep.com.br/ws/{zipcode}/json/'
        ],
        'cache_time' => 2592000 // 30 dias em segundos
    ],
    
    // Certificado digital para notas fiscais
    'certificate' => [
        'path' => ROOT_PATH . '/certificates/certificate.pfx',
        'password' => getenv('CERTIFICATE_PASSWORD') ?: ''
    ],
    
    // Integração com serviço de SMS
    'sms' => [
        'enabled' => false,
        'provider' => 'twilio',
        'twilio' => [
            'account_sid' => getenv('TWILIO_SID') ?: '',
            'auth_token' => getenv('TWILIO_TOKEN') ?: '',
            'from_number' => getenv('TWILIO_NUMBER') ?: ''
        ]
    ],
    
    // Integração com serviço de email
    'email' => [
        'provider' => 'smtp', // smtp, sendgrid, mailgun, etc.
        'smtp' => [
            'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
            'port' => getenv('SMTP_PORT') ?: 587,
            'username' => getenv('SMTP_USER') ?: '',
            'password' => getenv('SMTP_PASS') ?: '',
            'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls'
        ],
        'sendgrid' => [
            'api_key' => getenv('SENDGRID_API_KEY') ?: ''
        ],
        'from_email' => 'no-reply@canabidiol-ecommerce.com.br',
        'from_name' => 'E-commerce de Canabidiol'
    ]
];

/**
 * Inicializa o SDK do Mercado Pago
 */
function initMercadoPago() {
    global $integrationsConfig;
    
    if (!$integrationsConfig['mercadopago']['enabled']) {
        return false;
    }
    
    if (!class_exists('MercadoPago\SDK')) {
        // Carregar biblioteca do Mercado Pago via Composer autoload
        require_once ROOT_PATH . '/vendor/autoload.php';
    }
    
    // Configurar SDK
    \MercadoPago\SDK::setAccessToken($integrationsConfig['mercadopago']['access_token']);
    
    // Configurar modo sandbox se necessário
    if ($integrationsConfig['mercadopago']['sandbox']) {
        \MercadoPago\SDK::setMode(\MercadoPago\SDK::SANDBOX_MODE);
    } else {
        \MercadoPago\SDK::setMode(\MercadoPago\SDK::PRODUCTION_MODE);
    }
    
    return true;
}

/**
 * Obtém URL da API correta para o NFE.io com base no ambiente
 */
function getNfeioApiUrl() {
    global $integrationsConfig;
    
    if ($integrationsConfig['nfeio']['environment'] === 'production') {
        return 'https://api.nfe.io';
    }
    
    return 'https://api.sandbox.nfe.io';
}

/**
 * Converte CEP para formato padrão apenas com números
 */
function formatZipcode($zipcode) {
    // Remove todos os caracteres não numéricos
    return preg_replace('/[^0-9]/', '', $zipcode);
}

/**
 * Busca informações de endereço por CEP
 */
function getAddressByZipcode($zipcode) {
    global $integrationsConfig;
    
    // Formatar CEP
    $zipcode = formatZipcode($zipcode);
    
    if (strlen($zipcode) !== 8) {
        return ['error' => 'CEP inválido'];
    }
    
    // Verificar se está em cache
    $cacheKey = "zipcode_{$zipcode}";
    $cachedData = getCache($cacheKey);
    
    if ($cachedData) {
        return $cachedData;
    }
    
    // Buscar do serviço
    $provider = $integrationsConfig['zipcode']['provider'];
    
    switch ($provider) {
        case 'viacep':
            $url = str_replace('{zipcode}', $zipcode, $integrationsConfig['zipcode']['viacep']['url']);
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            
            if (isset($data['erro']) && $data['erro'] === true) {
                return ['error' => 'CEP não encontrado'];
            }
            
            $result = [
                'zipcode' => $zipcode,
                'street' => $data['logradouro'] ?? '',
                'complement' => $data['complemento'] ?? '',
                'neighborhood' => $data['bairro'] ?? '',
                'city' => $data['localidade'] ?? '',
                'state' => $data['uf'] ?? ''
            ];
            
            // Salvar em cache
            setCache($cacheKey, $result, $integrationsConfig['zipcode']['cache_time']);
            
            return $result;
            
        // Implementar outros provedores aqui
        default:
            return ['error' => 'Provedor de CEP não suportado'];
    }
}

/**
 * Funções de cache para armazenamento temporário de dados
 */
function getCache($key) {
    if (!isset($_SESSION['cache'][$key])) {
        return false;
    }
    
    $data = $_SESSION['cache'][$key];
    
    // Verificar expiração
    if ($data['expires'] < time()) {
        unset($_SESSION['cache'][$key]);
        return false;
    }
    
    return $data['value'];
}

function setCache($key, $value, $ttl = 3600) {
    if (!isset($_SESSION['cache'])) {
        $_SESSION['cache'] = [];
    }
    
    $_SESSION['cache'][$key] = [
        'value' => $value,
        'expires' => time() + $ttl
    ];
}

// Retornar a configuração das integrações
return $integrationsConfig;