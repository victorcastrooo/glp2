<?php
/**
 * App.php - Arquivo de configurações gerais da aplicação
 * 
 * Este arquivo contém todas as configurações principais do sistema, incluindo
 * constantes globais, funções auxiliares, configurações de ambiente e mais.
 */

// Impedir acesso direto ao arquivo
if (!defined('BASE_PATH')) {
    exit('Acesso direto não permitido');
}

/**
 * -------------------------------------------------------------------------
 * CONFIGURAÇÕES BÁSICAS DA APLICAÇÃO
 * -------------------------------------------------------------------------
 */
$config = [
    // Informações da aplicação
    'name' => 'E-commerce de Canabidiol',      // Nome do site
    'version' => '1.0.0',                      // Versão atual da aplicação
    'description' => 'E-commerce de medicamentos à base de canabidiol', // Descrição do site
    
    // Configurações de ambiente
    'environment' => 'development',            // development, testing, production
    'debug' => true,                          // Exibir erros? (true em desenvolvimento, false em produção)
    
    // Configurações de URL
    'base_url' => 'http://localhost/canabidiol-ecommerce/',  // URL base do site
    'asset_url' => 'http://localhost/canabidiol-ecommerce/public/assets/', // URL para assets
    
    // Configurações de diretório
    'root_path' => dirname(dirname(__DIR__)),  // Caminho raiz da aplicação
    'app_path' => dirname(__DIR__),            // Caminho para a pasta app
    'public_path' => dirname(dirname(__DIR__)) . '/public', // Caminho para a pasta public
    
    // Configurações regionais
    'timezone' => 'America/Sao_Paulo',         // Fuso horário padrão
    'locale' => 'pt_BR',                       // Localização para formatação de números, datas, etc.
    'charset' => 'UTF-8',                      // Charset da aplicação
    
    // Configurações de controladores
    'default_controller' => 'HomeController',  // Controlador padrão
    'default_method' => 'index',               // Método padrão dentro dos controladores
    'namespace_controllers' => 'App\\Controllers', // Namespace base para controladores
    
    // Configurações de sessão
    'session' => [
        'name' => 'canabidiol_session',        // Nome da sessão
        'lifetime' => 7200,                    // Tempo de vida da sessão em segundos (2 horas)
        'path' => '/',                         // Caminho da sessão
        'domain' => '',                        // Domínio da sessão (vazio = domínio atual)
        'secure' => false,                     // Usar apenas HTTPS? (true em produção)
        'httponly' => true,                    // Impedir acesso via JavaScript
        'samesite' => 'Lax'                    // Controle de cookies de terceiros (Lax, Strict, None)
    ],
    
    // Configurações de upload
    'upload' => [
        'path' => dirname(dirname(__DIR__)) . '/public/uploads/', // Pasta para uploads
        'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'], // Tipos permitidos
        'max_size' => 5242880,                 // Tamanho máximo em bytes (5MB)
        'encrypt_name' => true                 // Criptografar nomes de arquivos?
    ],
    
    // Configurações de log
    'log_path' => dirname(dirname(__DIR__)) . '/logs/', // Caminho para logs
    'log_threshold' => 4,                      // Nível mínimo de log (1=Error, 2=Debug, 3=Info, 4=All)
    
    // Configurações de segurança
    'security' => [
        'csrf_protection' => true,             // Ativar proteção CSRF?
        'csrf_token_name' => 'csrf_token',     // Nome do token CSRF
        'csrf_cookie_name' => 'csrf_cookie',   // Nome do cookie CSRF
        'csrf_expire' => 7200,                 // Tempo de expiração do token (2 horas)
        'xss_clean' => true,                   // Filtrar dados contra XSS?
        'password_min_length' => 8,            // Tamanho mínimo de senhas
        'password_hash_algo' => PASSWORD_DEFAULT, // Algoritmo de hash de senhas
        'password_hash_options' => [           // Opções para o algoritmo de hash
            'cost' => 12                       // Custo do algoritmo (quanto maior, mais seguro e mais lento)
        ]
    ],
    
    // Configurações de email
    'email' => [
        'from_email' => 'no-reply@canabidiol-ecommerce.com.br',
        'from_name' => 'E-commerce de Canabidiol',
        'smtp_host' => 'smtp.gmail.com',        // Servidor SMTP
        'smtp_port' => 587,                     // Porta SMTP
        'smtp_crypto' => 'tls',                 // Criptografia (tls ou ssl)
        'smtp_user' => '',                      // Usuário SMTP
        'smtp_pass' => '',                      // Senha SMTP
        'mailtype' => 'html',                   // Tipo de email (html ou text)
        'charset' => 'UTF-8',                   // Charset do email
        'newline' => "\r\n"                     // Caractere de nova linha
    ]
];

/**
 * -------------------------------------------------------------------------
 * DEFINIÇÃO DE CONSTANTES GLOBAIS
 * -------------------------------------------------------------------------
 */

// Constantes básicas da aplicação
define('APP_NAME', $config['name']);
define('APP_VERSION', $config['version']);
define('APP_DESCRIPTION', $config['description']);
define('BASE_URL', $config['base_url']);
define('ASSET_URL', $config['asset_url']);
define('ROOT_PATH', $config['root_path']);
define('APP_PATH', $config['app_path']);
define('PUBLIC_PATH', $config['public_path']);
define('UPLOAD_PATH', $config['upload']['path']);
define('LOG_PATH', $config['log_path']);

// Constante de ambiente
define('ENVIRONMENT', $config['environment']);
define('DEBUG_MODE', $config['debug']);

/**
 * -------------------------------------------------------------------------
 * CONFIGURAÇÃO DE AMBIENTE
 * -------------------------------------------------------------------------
 */

// Definir configurações com base no ambiente
switch (ENVIRONMENT) {
    case 'development':
        // Em desenvolvimento, mostramos todos os erros
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        break;
    
    case 'testing':
        // Em testes, mostramos erros, mas sem warnings
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
        ini_set('display_errors', 1);
        break;
    
    case 'production':
        // Em produção, escondemos todos os erros (mas continuamos logando)
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        break;
    
    default:
        // Caso o ambiente não esteja definido corretamente
        header('HTTP/1.1 503 Service Unavailable.', true, 503);
        echo 'Ambiente não definido corretamente.';
        exit;
}

// Configuração de timezone
date_default_timezone_set($config['timezone']);

// Configuração de locale
setlocale(LC_ALL, $config['locale']);

/**
 * -------------------------------------------------------------------------
 * FUNÇÕES GLOBAIS AUXILIARES
 * -------------------------------------------------------------------------
 */

/**
 * Função para redirecionar para uma página
 * 
 * @param string $path Caminho relativo para redirecionar
 * @return void
 */
function redirect($path) {
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/**
 * Função para carregar uma view
 * 
 * @param string $viewPath Caminho da view
 * @param array $data Dados a serem extraídos na view
 * @param bool $return Se deve retornar o conteúdo da view ao invés de exibir
 * @return string|void Se $return for true, retorna o conteúdo da view
 */
function view($viewPath, $data = [], $return = false) {
    // Extrair os dados para variáveis
    extract($data);
    
    // Criar caminho completo para o arquivo da view
    $viewFile = APP_PATH . '/Views/' . $viewPath . '.php';
    
    // Verificar se a view existe
    if (!file_exists($viewFile)) {
        die('View não encontrada: ' . $viewPath);
    }
    
    // Se deve retornar o conteúdo
    if ($return) {
        ob_start();
        require $viewFile;
        return ob_get_clean();
    }
    
    // Caso contrário, apenas inclui o arquivo
    require $viewFile;
}

/**
 * Carrega uma view dentro de um layout
 * 
 * @param string $viewPath Caminho da view
 * @param array $data Dados para a view
 * @param string $layout Nome do layout
 * @return void
 */
function view_with_layout($viewPath, $data = [], $layout = 'main') {
    // Renderiza a view e a salva no array de dados
    $data['content'] = view($viewPath, $data, true);
    
    // Carrega o layout com a view dentro
    view('layouts/' . $layout, $data);
}

/**
 * Função para definir uma mensagem flash
 * 
 * @param string $type Tipo da mensagem (success, error, warning, info)
 * @param string $message Conteúdo da mensagem
 * @return void
 */
function set_flash_message($type, $message) {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Função para obter mensagens flash
 * 
 * @param bool $clear Se deve limpar as mensagens após obter
 * @return array Array com as mensagens flash
 */
function get_flash_messages($clear = true) {
    $messages = $_SESSION['flash_messages'] ?? [];
    
    if ($clear) {
        $_SESSION['flash_messages'] = [];
    }
    
    return $messages;
}

/**
 * Função para exibir mensagens flash
 * 
 * @return void
 */
function show_flash_messages() {
    $messages = get_flash_messages();
    
    if (!empty($messages)) {
        foreach ($messages as $message) {
            echo '<div class="alert alert-' . $message['type'] . '">' . $message['message'] . '</div>';
        }
    }
}

/**
 * Sanitiza uma string de entrada
 * 
 * @param string $string String a ser sanitizada
 * @return string String sanitizada
 */
function sanitize_string($string) {
    return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica se o usuário está autenticado
 * 
 * @return bool
 */
function is_authenticated() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * Verifica se o usuário é um administrador
 * 
 * @return bool
 */
function is_admin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

/**
 * Verifica se o usuário é um vendedor
 * 
 * @return bool
 */
function is_vendor() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'vendor';
}

/**
 * Verifica se o usuário é um cliente
 * 
 * @return bool
 */
function is_customer() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'customer';
}

/**
 * Verifica se o cliente está com status ativo
 * 
 * @return bool
 */
function is_active_customer() {
    return is_customer() && $_SESSION['user']['status'] === 'active';
}

/**
 * Retorna o ID do usuário atual
 * 
 * @return int|null ID do usuário ou null se não estiver autenticado
 */
function get_current_user_id() {
    return $_SESSION['user']['id'] ?? null;
}

/**
 * Formata um valor monetário para exibição
 * 
 * @param float $value Valor a ser formatado
 * @param bool $withSymbol Se deve incluir o símbolo da moeda
 * @return string Valor formatado
 */
function format_currency($value, $withSymbol = true) {
    $formatted = number_format($value, 2, ',', '.');
    return $withSymbol ? 'R$ ' . $formatted : $formatted;
}

/**
 * Registra uma mensagem de log
 * 
 * @param string $message Mensagem a ser registrada
 * @param string $level Nível do log (error, warning, info, debug)
 * @return bool Sucesso ou falha ao registrar
 */
function log_message($message, $level = 'info') {
    $logFile = LOG_PATH . date('Y-m-d') . '_' . $level . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
    
    return file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Gera um token CSRF
 * 
 * @return string Token gerado
 */
function generate_csrf_token() {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Verifica se um token CSRF é válido
 * 
 * @param string $token Token a ser verificado
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === $token;
}

/**
 * Exibe um campo hidden com o token CSRF
 * 
 * @return void
 */
function csrf_field() {
    $token = generate_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Carrega variáveis de ambiente do arquivo .env
 */
function load_environment_variables() {
    $envFile = ROOT_PATH . '/.env';
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignorar comentários
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remover aspas se existirem
            if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
                $value = substr($value, 1, -1);
            }
            
            // Definir variável de ambiente
            putenv("{$name}={$value}");
            
            // Também disponibilizar via $_ENV e $_SERVER
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Carregar variáveis de ambiente
load_environment_variables();

// Retornar array de configurações para uso em outras partes do sistema
return $config;