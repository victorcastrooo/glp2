<?php
/**
* Security.php - Configurações e funções de segurança da aplicação
* 
* Este arquivo contém configurações de segurança, funções para proteção contra
* ataques comuns (XSS, CSRF, SQL Injection), validação de dados e sanitização.
*/

// Impedir acesso direto ao arquivo
if (!defined('BASE_PATH')) {
   exit('Acesso direto não permitido');
}

/**
* Configurações de Segurança
*/
$securityConfig = [
   // Proteção CSRF
   'csrf_protection' => true,
   'csrf_token_name' => 'csrf_token',
   'csrf_cookie_name' => 'csrf_cookie',
   'csrf_expire' => 7200,  // 2 horas em segundos
   
   // Proteção XSS
   'xss_clean' => true,
   
   // Cabeçalhos de segurança
   'security_headers' => true,
   
   // Configurações de senha
   'password_min_length' => 8,
   'password_hash_algo' => PASSWORD_DEFAULT,
   'password_hash_options' => [
       'cost' => 12
   ],
   
   // Bloqueio de conta
   'max_login_attempts' => 5,
   'lockout_time' => 900,  // 15 minutos em segundos
   
   // Rate limiting
   'enable_rate_limiting' => true,
   'rate_limit_requests' => 100,  // requisições
   'rate_limit_window' => 60,     // segundos
   
   // Sanitização de entrada
   'sanitize_input' => true,
   
   // Configurações de sessão
   'session_secure' => false,     // true em produção com HTTPS
   'session_httponly' => true,
   'session_regenerate' => true,
   'session_samesite' => 'Lax'    // Lax, Strict ou None
];

/**
* Configurar cabeçalhos de segurança
*/
function setSecurityHeaders() {
   // Proteção contra clickjacking
   header('X-Frame-Options: SAMEORIGIN');
   
   // Proteção XSS para browsers modernos
   header('X-XSS-Protection: 1; mode=block');
   
   // Impedir MIME-type sniffing
   header('X-Content-Type-Options: nosniff');
   
   // Política de segurança de conteúdo
   header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://api.mercadopago.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self' https://api.mercadopago.com; font-src 'self' https://cdnjs.cloudflare.com; object-src 'none'");
   
   // Política de referrer
   header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
* Gera um token CSRF novo
*/
function generateCsrfToken() {
   global $securityConfig;
   
   $token = bin2hex(random_bytes(32));
   $_SESSION[$securityConfig['csrf_token_name']] = $token;
   
   return $token;
}

/**
* Verifica se o token CSRF é válido
*/
function verifyCsrfToken($token) {
   global $securityConfig;
   
   if (!isset($_SESSION[$securityConfig['csrf_token_name']])) {
       return false;
   }
   
   $sessionToken = $_SESSION[$securityConfig['csrf_token_name']];
   
   // Usar comparação time-safe para evitar timing attacks
   return hash_equals($sessionToken, $token);
}

/**
* Sanitiza uma string contra XSS
*/
function xssClean($data) {
   if (is_string($data)) {
       return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
   }
   
   if (is_array($data)) {
       foreach ($data as $key => $value) {
           $data[$key] = xssClean($value);
       }
   }
   
   return $data;
}

/**
* Sanitiza dados de entrada para uso seguro
*/
function sanitizeInput($data, $xssClean = true) {
   if (is_string($data)) {
       $data = trim($data);
       
       if ($xssClean) {
           $data = xssClean($data);
       }
   } elseif (is_array($data)) {
       foreach ($data as $key => $value) {
           $data[$key] = sanitizeInput($value, $xssClean);
       }
   }
   
   return $data;
}

/**
* Sanitiza nomes de arquivos para upload seguro
*/
function sanitizeFilename($filename) {
   // Remover caracteres que não são alfanuméricos, underscore ou ponto
   $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $filename);
   
   // Evitar nomes de arquivo maliciosos
   $filename = str_replace('..', '', $filename);
   
   return $filename;
}

/**
* Valida endereço de email
*/
function validateEmail($email) {
   if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
       return true;
   }
   return false;
}

/**
* Valida uma senha conforme requisitos de segurança
*/
function validatePassword($password) {
   global $securityConfig;
   
   $errors = [];
   
   // Verificar tamanho mínimo
   if (strlen($password) < $securityConfig['password_min_length']) {
       $errors[] = "A senha deve ter pelo menos {$securityConfig['password_min_length']} caracteres.";
   }
   
   // Verificar complexidade
   if (!preg_match('/[A-Z]/', $password)) {
       $errors[] = "A senha deve conter pelo menos uma letra maiúscula.";
   }
   
   if (!preg_match('/[a-z]/', $password)) {
       $errors[] = "A senha deve conter pelo menos uma letra minúscula.";
   }
   
   if (!preg_match('/[0-9]/', $password)) {
       $errors[] = "A senha deve conter pelo menos um número.";
   }
   
   if (!preg_match('/[^A-Za-z0-9]/', $password)) {
       $errors[] = "A senha deve conter pelo menos um caractere especial.";
   }
   
   return empty($errors) ? true : $errors;
}

/**
* Gera um hash seguro para senha
*/
function hashPassword($password) {
   global $securityConfig;
   
   return password_hash(
       $password,
       $securityConfig['password_hash_algo'],
       $securityConfig['password_hash_options']
   );
}

/**
* Verifica uma senha
*/
function verifyPassword($password, $hash) {
   return password_verify($password, $hash);
}

/**
* Registra tentativa de login falha
*/
function recordFailedLogin($userId) {
   global $securityConfig;
   
   $db = getDbConnection();
   
   // Obter tentativas atuais
   $sql = "SELECT login_attempts, last_attempt_time FROM users WHERE id = ?";
   $stmt = $db->prepare($sql);
   $stmt->execute([$userId]);
   $user = $stmt->fetch();
   
   // Calcular nova quantidade de tentativas
   $attempts = $user['login_attempts'] + 1;
   
   // Bloquear conta se excedeu limites
   $locked = 0;
   if ($attempts >= $securityConfig['max_login_attempts']) {
       $locked = 1;
   }
   
   // Atualizar registro
   $sql = "UPDATE users SET login_attempts = ?, last_attempt_time = NOW(), locked = ? WHERE id = ?";
   $stmt = $db->prepare($sql);
   $stmt->execute([$attempts, $locked, $userId]);
   
   return $attempts;
}

/**
* Reseta contagem de tentativas de login
*/
function resetLoginAttempts($userId) {
   $db = getDbConnection();
   
   $sql = "UPDATE users SET login_attempts = 0, locked = 0 WHERE id = ?";
   $stmt = $db->prepare($sql);
   $stmt->execute([$userId]);
}

/**
* Verifica se uma conta está bloqueada
*/
function isAccountLocked($userId) {
   global $securityConfig;
   
   $db = getDbConnection();
   
   $sql = "SELECT locked, last_attempt_time FROM users WHERE id = ?";
   $stmt = $db->prepare($sql);
   $stmt->execute([$userId]);
   $user = $stmt->fetch();
   
   if (!$user['locked']) {
       return false;
   }
   
   // Verificar se o tempo de bloqueio já passou
   $lockoutTime = strtotime($user['last_attempt_time']) + $securityConfig['lockout_time'];
   
   if (time() > $lockoutTime) {
       // Desbloquear conta
       resetLoginAttempts($userId);
       return false;
   }
   
   return true;
}

/**
* Verificação de rate limit
*/
function checkRateLimit($ip, $route) {
   global $securityConfig;
   
   if (!$securityConfig['enable_rate_limiting']) {
       return true;
   }
   
   $db = getDbConnection();
   
   // Limpar registros antigos
   $sql = "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)";
   $stmt = $db->prepare($sql);
   $stmt->execute([$securityConfig['rate_limit_window']]);
   
   // Contar requisições
   $sql = "SELECT COUNT(*) as count FROM rate_limits WHERE ip = ? AND route = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
   $stmt = $db->prepare($sql);
   $stmt->execute([$ip, $route, $securityConfig['rate_limit_window']]);
   $result = $stmt->fetch();
   
   // Verificar se excedeu o limite
   if ($result['count'] >= $securityConfig['rate_limit_requests']) {
       return false;
   }
   
   // Registrar requisição
   $sql = "INSERT INTO rate_limits (ip, route, created_at) VALUES (?, ?, NOW())";
   $stmt = $db->prepare($sql);
   $stmt->execute([$ip, $route]);
   
   return true;
}

/**
* Gera um token aleatório seguro
*/
function generateRandomToken($length = 32) {
   return bin2hex(random_bytes($length / 2));
}

/**
* Valida um documento CPF
*/
function validateCPF($cpf) {
   // Extrai somente os números
   $cpf = preg_replace('/[^0-9]/is', '', $cpf);
   
   // Verifica se foi informado 11 dígitos
   if (strlen($cpf) != 11) {
       return false;
   }
   
   // Verifica se foi informada uma sequência de dígitos repetidos
   if (preg_match('/(\d)\1{10}/', $cpf)) {
       return false;
   }
   
   // Faz o cálculo para validar o CPF
   for ($t = 9; $t < 11; $t++) {
       for ($d = 0, $c = 0; $c < $t; $c++) {
           $d += $cpf[$c] * (($t + 1) - $c);
       }
       $d = ((10 * $d) % 11) % 10;
       if ($cpf[$c] != $d) {
           return false;
       }
   }
   
   return true;
}

/**
* Valida um documento CNPJ
*/
function validateCNPJ($cnpj) {
   $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
   
   if (strlen($cnpj) != 14) {
       return false;
   }
   
   // Valida primeiro dígito verificador
   $sum = 0;
   $multiplier = 5;
   for ($i = 0; $i < 12; $i++) {
       $sum += $cnpj[$i] * $multiplier;
       $multiplier = ($multiplier == 2) ? 9 : $multiplier - 1;
   }
   $remainder = $sum % 11;
   $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;
   
   // Valida segundo dígito verificador
   $sum = 0;
   $multiplier = 6;
   for ($i = 0; $i < 13; $i++) {
       $sum += $cnpj[$i] * $multiplier;
       $multiplier = ($multiplier == 2) ? 9 : $multiplier - 1;
   }
   $remainder = $sum % 11;
   $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;
   
   return (($cnpj[12] == $digit1) && ($cnpj[13] == $digit2));
}

// Aplicar cabeçalhos de segurança se configurado
if ($securityConfig['security_headers']) {
   setSecurityHeaders();
}

// Retornar a configuração de segurança
return $securityConfig;