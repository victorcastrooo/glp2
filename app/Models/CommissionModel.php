<?php
/**
 * Obtenha o resumo financeiro de um vendedor
 * 
 * @param int $vendorId ID do vendedor
 * @return array Resumo financeiro
 */
public function getVendorFinancialSummary($vendorId) {
    // Total ganho (todas as comissões)
    $sql1 = "SELECT COALESCE(SUM(amount), 0) as total_earned 
             FROM commissions 
             WHERE vendor_id = :vendor_id";
    
    $stmt1 = $this->db->prepare($sql1);
    $stmt1->execute(['vendor_id' => $vendorId]);
    $totalEarned = (float) $stmt1->fetchColumn();
    
    // Total pago (comissões pagas)
    $sql2 = "SELECT COALESCE(SUM(amount), 0) as total_paid 
             FROM commissions 
             WHERE vendor_id = :vendor_id AND status = 'paid'";
    
    $stmt2 = $this->db->prepare($sql2);
    $stmt2->execute(['vendor_id' => $vendorId]);
    $totalPaid = (float) $stmt2->fetchColumn();
    
    // Total em processamento (comissões em solicitação de retirada)
    $sql3 = "SELECT COALESCE(SUM(amount), 0) as total_processing 
             FROM commissions 
             WHERE vendor_id = :vendor_id AND status = 'processing'";
    
    $stmt3 = $this->db->prepare($sql3);
    $stmt3->execute(['vendor_id' => $vendorId]);
    $totalProcessing = (float) $stmt3->fetchColumn();
    
    // Total disponível para retirada (comissões pendentes)
    $sql4 = "SELECT COALESCE(SUM(amount), 0) as available_commission 
             FROM commissions 
             WHERE vendor_id = :vendor_id AND status = 'pending'";
    
    $stmt4 = $this->db->prepare($sql4);
    $stmt4->execute(['vendor_id' => $vendorId]);
    $availableCommission = (float) $stmt4->fetchColumn();
    
    return [
        'total_earned' => $totalEarned,
        'total_paid' => $totalPaid,
        'total_processing' => $totalProcessing,
        'available_commission' => $availableCommission
    ];
}

/**
 * Atribui comissões a uma solicitação de retirada
 * 
 * @param int $vendorId ID do vendedor
 * @param int $withdrawalId ID da solicitação de retirada
 * @param float $amount Valor solicitado
 * @return bool Sucesso da operação
 */
public function assignCommissionsToWithdrawal($vendorId, $withdrawalId, $amount) {
    // Obter comissões pendentes
    $sql = "SELECT id, amount FROM commissions 
            WHERE vendor_id = :vendor_id AND status = 'pending'
            ORDER BY created_at ASC";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['vendor_id' => $vendorId]);
    $pendingCommissions = $stmt->fetchAll();
    
    $remaining = $amount;
    $assignedCommissions = [];
    
    // Atribuir comissões até atingir o valor solicitado
    foreach ($pendingCommissions as $commission) {
        if ($remaining <= 0) {
            break;
        }
        
        $assignedCommissions[] = $commission['id'];
        $remaining -= $commission['amount'];
    }
    
    // Atualizar status das comissões selecionadas
    if (!empty($assignedCommissions)) {
        $placeholders = implode(',', array_fill(0, count($assignedCommissions), '?'));
        
        $sql = "UPDATE commissions 
                SET status = 'processing', withdrawal_id = ?, updated_at = NOW() 
                WHERE id IN ({$placeholders})";
        
        $params = array_merge([$withdrawalId], $assignedCommissions);
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    return false;
}

/**
 * Redefine comissões de uma solicitação de retirada para status pendente
 * 
 * @param int $withdrawalId ID da solicitação de retirada
 * @return bool Sucesso da operação
 */
public function resetCommissionsByWithdrawalId($withdrawalId) {
    $sql = "UPDATE commissions 
            SET status = 'pending', withdrawal_id = NULL, updated_at = NOW() 
            WHERE withdrawal_id = :withdrawal_id";
    
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['withdrawal_id' => $withdrawalId]);
}

/**
 * Atualiza status das comissões de uma solicitação de retirada
 * 
 * @param int $withdrawalId ID da solicitação de retirada
 * @param string $status Novo status
 * @return bool Sucesso da operação
 */
public function updateStatusByWithdrawalId($withdrawalId, $status) {
    $sql = "UPDATE commissions 
            SET status = :status, updated_at = NOW() 
            WHERE withdrawal_id = :withdrawal_id";
    
    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
        'status' => $status,
        'withdrawal_id' => $withdrawalId
    ]);
}

/**
 * Obtém comissões incluídas em uma solicitação de retirada
 * 
 * @param int $withdrawalId ID da solicitação de retirada
 * @return array Lista de comissões
 */
public function getCommissionsByWithdrawalId($withdrawalId) {
    $sql = "SELECT c.*, o.order_number, u.name as customer_name, d.name as doctor_name
            FROM commissions c
            LEFT JOIN orders o ON c.order_id = o.id
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN doctors d ON c.doctor_id = d.id
            WHERE c.withdrawal_id = :withdrawal_id
            ORDER BY c.created_at ASC";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['withdrawal_id' => $withdrawalId]);
    
    return $stmt->fetchAll();
}

?>