<?
/**
 * Redefine a comissão disponível de um vendedor após pagamento
 * 
 * @param int $vendorId ID do vendedor
 * @param string $paymentDate Data do pagamento
 * @return bool Sucesso da operação
 */
public function resetAvailableCommission($vendorId, $paymentDate) {
    // Definir todas as comissões não atribuídas com data anterior ao pagamento como pagas
    $sql = "UPDATE commissions 
            SET status = 'paid', payment_date = :payment_date, updated_at = NOW()
            WHERE vendor_id = :vendor_id 
            AND status = 'pending'
            AND created_at <= :payment_date";
    
    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
        'vendor_id' => $vendorId,
        'payment_date' => $paymentDate
    ]);
}?>