<?php
/**
 * Database.php - Configurações e funções para conexão com o banco de dados
 * 
 * Este arquivo contém todas as configurações necessárias para conectar com
 * o banco de dados, além de funções auxiliares para operações com o banco.
 */

// Impedir acesso direto ao arquivo
if (!defined('BASE_PATH')) {
    exit('Acesso direto não permitido');
}

/**
 * -------------------------------------------------------------------------
 * CONFIGURAÇÕES DO BANCO DE DADOS
 * -------------------------------------------------------------------------
 */
$dbConfig = [
    // Configurações gerais
    'driver'        => 'mysql',           // Driver do banco (mysql, pgsql, sqlite, etc)
    'host'          => getenv('DB_HOST') ?: 'localhost', // Servidor do banco de dados
    'port'          => getenv('DB_PORT') ?: '3306',      // Porta do banco de dados
    'database'      => getenv('DB_DATABASE') ?: 'canabidiol_ecommerce', // Nome do banco
    'username'      => getenv('DB_USERNAME') ?: 'root',   // Usuário do banco
    'password'      => getenv('DB_PASSWORD') ?: '',       // Senha do banco
    
    // Configurações de charset e collation
    'charset'       => 'utf8mb4',          // Charset do banco
    'collation'     => 'utf8mb4_unicode_ci', // Collation do banco
    
    // Configurações de PDO
    'pdo_options'   => [
        PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION, // Modo de erro
        PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,       // Modo de busca padrão
        PDO::ATTR_EMULATE_PREPARES     => false,                  // Não emular prepared statements
        PDO::ATTR_PERSISTENT           => false,                  // Não usar conexões persistentes
    ],
    
    // Configurações de log e debug
    'log_queries'   => true,              // Logar consultas SQL?
    'slow_threshold'=> 1.0,               // Tempo em segundos para considerar uma consulta lenta
    
    // Configurações de pool de conexões
    'max_connections' => 20,              // Número máximo de conexões simultâneas
    'timeout'       => 5,                 // Timeout de conexão em segundos
    
    // Configurações de failover (servidores de backup)
    'failover'      => [],                // Array de configurações de servidores de backup
    
    // Configurações de cache de consultas
    'query_cache'   => false,             // Ativar cache de consultas?
    'cache_dir'     => ROOT_PATH . '/cache/db', // Diretório para armazenar o cache
    'cache_time'    => 60                 // Tempo de cache em segundos
];

/**
 * -------------------------------------------------------------------------
 * CONEXÃO COM O BANCO DE DADOS
 * -------------------------------------------------------------------------
 */

/**
 * Cria e retorna uma conexão PDO com o banco de dados
 * 
 * @return PDO Objeto de conexão PDO
 * @throws PDOException Se não for possível conectar ao banco
 */
function getDbConnection() {
    global $dbConfig;
    
    try {
        // Criar DSN (Data Source Name) para conexão
        $dsn = buildDsn($dbConfig);
        
        // Obter opções do PDO
        $options = $dbConfig['pdo_options'];
        
        // Criar conexão PDO
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        
        // Definir charset se necessário
        if ($dbConfig['driver'] === 'mysql') {
            $pdo->exec("SET NAMES '{$dbConfig['charset']}' COLLATE '{$dbConfig['collation']}'");
            $pdo->exec("SET CHARACTER SET {$dbConfig['charset']}");
        }
        
        // Registrar conexão no log
        if ($dbConfig['log_queries']) {
            logMessage('Database connection established successfully', 'database');
        }
        
        return $pdo;
    } catch (PDOException $e) {
        // Registrar erro no log
        logMessage('Database connection failed: ' . $e->getMessage(), 'error');
        
        // Se estiver em modo de debug, mostrar erro detalhado
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            throw new PDOException('Database connection failed: ' . $e->getMessage());
        } else {
            // Em produção, mostrar mensagem genérica
            die('Não foi possível conectar ao banco de dados. Por favor, tente novamente mais tarde.');
        }
    }
}

/**
 * Constrói o DSN (Data Source Name) para conexão com o banco
 * 
 * @param array $config Configurações do banco de dados
 * @return string DSN para conexão PDO
 */
function buildDsn($config) {
    switch ($config['driver']) {
        case 'mysql':
            return "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
        
        case 'pgsql':
            return "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        
        case 'sqlite':
            return "sqlite:" . ROOT_PATH . "/database/{$config['database']}.sqlite";
        
        case 'sqlsrv':
            return "sqlsrv:Server={$config['host']},{$config['port']};Database={$config['database']}";
        
        default:
            throw new InvalidArgumentException("Driver de banco de dados não suportado: {$config['driver']}");
    }
}

/**
 * -------------------------------------------------------------------------
 * CLASSE DE ABSTRAÇÃO DO BANCO DE DADOS
 * -------------------------------------------------------------------------
 * Esta classe fornece métodos para facilitar operações com o banco de dados
 */
class Database {
    /**
     * @var PDO Instância de conexão PDO
     */
    private $pdo;
    
    /**
     * @var array Configurações do banco de dados
     */
    private $config;
    
    /**
     * @var PDOStatement Última declaração preparada
     */
    private $statement;
    
    /**
     * @var array Registro de consultas executadas
     */
    private $queryLog = [];
    
    /**
     * Construtor
     */
    public function __construct() {
        global $dbConfig;
        $this->config = $dbConfig;
        $this->connect();
    }
    
    /**
     * Estabelece conexão com o banco de dados
     * 
     * @return void
     */
    private function connect() {
        $this->pdo = getDbConnection();
    }
    
    /**
     * Executa uma consulta SQL
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parâmetros para a consulta
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        // Registrar início da consulta
        $startTime = microtime(true);
        
        // Preparar e executar a consulta
        $this->statement = $this->pdo->prepare($sql);
        $this->statement->execute($params);
        
        // Calcular tempo de execução
        $executionTime = microtime(true) - $startTime;
        
        // Registrar consulta no log se habilitado
        if ($this->config['log_queries']) {
            $this->logQuery($sql, $params, $executionTime);
        }
        
        // Verificar se é uma consulta lenta
        if ($executionTime > $this->config['slow_threshold']) {
            $this->logSlowQuery($sql, $params, $executionTime);
        }
        
        return $this->statement;
    }
    
    /**
     * Obtém um único registro do resultado da consulta
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parâmetros para a consulta
     * @param int $fetchMode Modo de busca PDO
     * @return mixed Um único registro ou false se não encontrar
     */
    public function fetchOne($sql, $params = [], $fetchMode = null) {
        $stmt = $this->query($sql, $params);
        
        if ($fetchMode !== null) {
            return $stmt->fetch($fetchMode);
        }
        
        return $stmt->fetch();
    }
    
    /**
     * Obtém todos os registros do resultado da consulta
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parâmetros para a consulta
     * @param int $fetchMode Modo de busca PDO
     * @return array Registros encontrados
     */
    public function fetchAll($sql, $params = [], $fetchMode = null) {
        $stmt = $this->query($sql, $params);
        
        if ($fetchMode !== null) {
            return $stmt->fetchAll($fetchMode);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Insere um registro na tabela
     * 
     * @param string $table Nome da tabela
     * @param array $data Dados a serem inseridos (coluna => valor)
     * @return int|bool ID do último registro inserido ou false em caso de erro
     */
    public function insert($table, $data) {
        // Obter colunas e valores
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        // Construir consulta
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        // Executar consulta
        $stmt = $this->query($sql, array_values($data));
        
        if ($stmt) {
            return $this->pdo->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * Atualiza registros na tabela
     * 
     * @param string $table Nome da tabela
     * @param array $data Dados a serem atualizados (coluna => valor)
     * @param string $where Condição WHERE
     * @param array $params Parâmetros para a condição WHERE
     * @return int Número de registros afetados
     */
    public function update($table, $data, $where, $params = []) {
        // Construir parte SET da consulta
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = ?";
            $values[] = $value;
        }
        
        // Adicionar parâmetros WHERE ao array de valores
        $values = array_merge($values, $params);
        
        // Construir consulta
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$where}";
        
        // Executar consulta
        $stmt = $this->query($sql, $values);
        
        return $stmt->rowCount();
    }
    
    /**
     * Exclui registros da tabela
     * 
     * @param string $table Nome da tabela
     * @param string $where Condição WHERE
     * @param array $params Parâmetros para a condição WHERE
     * @return int Número de registros afetados
     */
    public function delete($table, $where, $params = []) {
        // Construir consulta
        $sql = "DELETE FROM {$table} WHERE {$where}";
        
        // Executar consulta
        $stmt = $this->query($sql, $params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Obtém um registro pelo ID
     * 
     * @param string $table Nome da tabela
     * @param int $id ID do registro
     * @param string $idColumn Nome da coluna ID (padrão: 'id')
     * @return array|bool Registro encontrado ou false
     */
    public function find($table, $id, $idColumn = 'id') {
        $sql = "SELECT * FROM {$table} WHERE {$idColumn} = ? LIMIT 1";
        
        return $this->fetchOne($sql, [$id]);
    }
    
    /**
     * Verifica se um registro existe
     * 
     * @param string $table Nome da tabela
     * @param string $where Condição WHERE
     * @param array $params Parâmetros para a condição WHERE
     * @return bool Verdadeiro se existir, falso caso contrário
     */
    public function exists($table, $where, $params = []) {
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        
        $result = $this->fetchOne($sql, $params);
        
        return $result !== false;
    }
    
    /**
     * Conta registros na tabela
     * 
     * @param string $table Nome da tabela
     * @param string $where Condição WHERE opcional
     * @param array $params Parâmetros para a condição WHERE
     * @return int Número de registros
     */
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        
        $result = $this->fetchOne($sql, $params);
        
        return (int) $result['count'];
    }
    
    /**
     * Inicia uma transação
     * 
     * @return bool
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Confirma uma transação
     * 
     * @return bool
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Reverte uma transação
     * 
     * @return bool
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Verifica se há uma transação ativa
     * 
     * @return bool
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Registra uma consulta no log
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parâmetros da consulta
     * @param float $executionTime Tempo de execução em segundos
     */
    private function logQuery($sql, $params, $executionTime) {
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'execution_time' => $executionTime,
            'timestamp' => microtime(true)
        ];
        
        // Limitar tamanho do log
        if (count($this->queryLog) > 100) {
            array_shift($this->queryLog);
        }
    }
    
    /**
     * Registra uma consulta lenta no log
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parâmetros da consulta
     * @param float $executionTime Tempo de execução em segundos
     */
    private function logSlowQuery($sql, $params, $executionTime) {
        $message = "Consulta lenta ({$executionTime}s): {$sql}";
        logMessage($message, 'database');
    }
    
    /**
     * Obtém o log de consultas
     * 
     * @return array Log de consultas
     */
    public function getQueryLog() {
        return $this->queryLog;
    }
    
    /**
     * Escapa um valor para uso seguro em consulta SQL
     * 
     * @param string $value Valor a ser escapado
     * @return string Valor escapado
     */
    public function escape($value) {
        return $this->pdo->quote($value);
    }
    
    /**
     * Obtém a instância PDO diretamente
     * 
     * @return PDO
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Fecha a conexão com o banco de dados
     */
    public function close() {
        $this->pdo = null;
    }
}

/**
 * Função auxiliar para registrar mensagem no log
 * 
 * @param string $message Mensagem a ser registrada
 * @param string $type Tipo de log
 * @return void
 */
function logMessage($message, $type = 'info') {
    // Verificar se a função global existe
    if (function_exists('log_message')) {
        log_message($message, $type);
    } else {
        // Implementação básica
        $logFile = ROOT_PATH . "/logs/{$type}.log";
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// Retornar a configuração do banco de dados para uso em outras partes do sistema
return $dbConfig;