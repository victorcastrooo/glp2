<?php
/**
 * WithdrawalRequestModel.php - Modelo para solicitações de retirada de comissões
 */

namespace App\Models;

class WithdrawalRequestModel {
    /**
     * @var \PDO Conexão com o banco de dados
     */
    private $db;
    
    /**
     * Construtor do modelo
     */
    public function __construct() {
        $this->db = getDbConnection();
    }
    
    /**
     * Cria uma nova solicitação de retirada
     * 
     * @param array $data Dados da solicitação
     * @return int ID da solicitação criada
     */
    public function create($data) {
        $sql = "INSERT INTO withdrawal_requests (
                    vendor_id, amount, status, request_date, notes, created_at
                ) VALUES (
                    :vendor_id, :amount, :status, :request_date, :notes, NOW()
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'vendor_id' => $data['vendor_id'],
            'amount' => $data['amount'],
            'status' => $data['status'] ?? 'pending',
            'request_date' => $data['request_date'] ?? date('Y-m-d H:i:s'),
            'notes' => $data['notes'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Atualiza uma solicitação de retirada
     * 
     * @param int $id ID da solicitação
     * @param array $data Dados atualizados
     * @return bool Sucesso da operação
     */
    public function update($id, $data) {
        $fields = [];
        $params = ['id' => $id];
        
        foreach ($data as $key => $value) {
            if ($key !== 'id') {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }
        
        $sql = "UPDATE withdrawal_requests SET " . implode(', ', $fields) . " WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }
    
    /**
     * Obtém uma solicitação de retirada pelo ID
     * 
     * @param int $id ID da solicitação
     * @return array|false Dados da solicitação ou false se não encontrada
     */
    public function getById($id) {
        $sql = "SELECT * FROM withdrawal_requests WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        return $stmt->fetch();
    }
    
    /**
     * Obtém solicitações de retirada pendentes para administração
     * 
     * @param int $limit Limite de registros
     * @param int $offset Deslocamento para paginação
     * @return array Lista de solicitações pendentes
     */
    public function getPendingRequests($limit = 20, $offset = 0) {
        $sql = "SELECT wr.*, v.company_name, u.name, u.email 
                FROM withdrawal_requests wr
                JOIN vendors v ON wr.vendor_id = v.id
                JOIN users u ON v.user_id = u.id
                WHERE wr.status = 'pending'
                ORDER BY wr.request_date ASC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Conta solicitações pendentes
     * 
     * @return int Número de solicitações pendentes
     */
    public function countPendingRequests() {
        $sql = "SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Verifica se um vendedor tem solicitação pendente
     * 
     * @param int $vendorId ID do vendedor
     * @return array|false Solicitação pendente ou false se não houver
     */
    public function getVendorPendingRequest($vendorId) {
        $sql = "SELECT * FROM withdrawal_requests 
                WHERE vendor_id = :vendor_id AND status = 'pending'
                ORDER BY request_date DESC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['vendor_id' => $vendorId]);
        
        return $stmt->fetch();
    }
    
    /**
     * Obtém o histórico de retiradas de um vendedor
     * 
     * @param int $vendorId ID do vendedor
     * @param int $limit Limite de registros
     * @param int $offset Deslocamento para paginação
     * @return array Histórico de retiradas
     */
    public function getVendorWithdrawalHistory($vendorId, $limit = 10, $offset = 0) {
        $sql = "SELECT * FROM withdrawal_requests 
                WHERE vendor_id = :vendor_id
                ORDER BY request_date DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vendor_id', $vendorId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Conta o total de retiradas de um vendedor
     * 
     * @param int $vendorId ID do vendedor
     * @return int Total de retiradas
     */
    public function countVendorWithdrawals($vendorId) {
        $sql = "SELECT COUNT(*) FROM withdrawal_requests WHERE vendor_id = :vendor_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['vendor_id' => $vendorId]);
        
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Obtém a última retirada concluída de um vendedor
     * 
     * @param int $vendorId ID do vendedor
     * @return array|false Dados da última retirada ou false se não houver
     */
    public function getLastCompletedWithdrawal($vendorId) {
        $sql = "SELECT * FROM withdrawal_requests 
                WHERE vendor_id = :vendor_id AND status = 'completed'
                ORDER BY payment_date DESC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['vendor_id' => $vendorId]);
        
        return $stmt->fetch();
    }
}