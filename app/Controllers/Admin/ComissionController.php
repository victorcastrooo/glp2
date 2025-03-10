<?php
/**
 * CommissionController.php - Controlador para gerenciamento de comissões no admin
 * 
 * Este controlador gerencia as solicitações de retirada de comissões dos vendedores,
 * aprovação de pagamentos, e acompanhamento do histórico de comissões.
 */

namespace App\Controllers\Admin;

use App\Models\CommissionModel;
use App\Models\VendorModel;
use App\Models\DoctorModel;
use App\Models\WithdrawalRequestModel;
use App\Services\EmailService;

class CommissionController {
    /**
     * @var CommissionModel Modelo de comissões
     */
    private $commissionModel;
    
    /**
     * @var VendorModel Modelo de vendedores
     */
    private $vendorModel;
    
    /**
     * @var DoctorModel Modelo de médicos
     */
    private $doctorModel;
    
    /**
     * @var WithdrawalRequestModel Modelo de solicitações de retirada
     */
    private $withdrawalRequestModel;
    
    /**
     * @var EmailService Serviço de e-mail
     */
    private $emailService;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos e serviços
        $this->commissionModel = new CommissionModel();
        $this->vendorModel = new VendorModel();
        $this->doctorModel = new DoctorModel();
        $this->withdrawalRequestModel = new WithdrawalRequestModel();
        $this->emailService = new EmailService();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todas as comissões e solicitações de retirada
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Estatísticas rápidas
        $stats = [
            'totalCommissions' => $this->commissionModel->getTotalCommissions(),
            'pendingCommissions' => $this->commissionModel->getTotalPendingCommissions(),
            'paidCommissions' => $this->commissionModel->getTotalPaidCommissions(),
            'pendingWithdrawals' => $this->withdrawalRequestModel->countPendingRequests()
        ];
        
        // Obter solicitações de retirada pendentes
        $pendingWithdrawals = $this->withdrawalRequestModel->getPendingRequests($limit, $offset);
        
        // Obter vendedores para filtro
        $vendors = $this->vendorModel->getAllBasic();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Comissões',
            'stats' => $stats,
            'pendingWithdrawals' => $pendingWithdrawals,
            'vendors' => $vendors,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'vendorId' => $vendorId,
            'currentPage' => $page
        ];
        
        // Renderizar a view
        view_with_layout('admin/commissions/index', $data, 'admin');
    }
    
    /**
     * Exibe solicitações de retirada pendentes
     */
    public function pendingWithdrawals() {
        // Parâmetros de paginação
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        // Obter solicitações pendentes
        $pendingWithdrawals = $this->withdrawalRequestModel->getPendingRequests($limit, $offset);
        
        // Obter contagem total para paginação
        $totalRequests = $this->withdrawalRequestModel->countPendingRequests();
        
        // Calcular total de páginas
        $totalPages = ceil($totalRequests / $limit);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Solicitações de Retirada Pendentes',
            'pendingWithdrawals' => $pendingWithdrawals,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalRequests' => $totalRequests
        ];
        
        // Renderizar a view
        view_with_layout('admin/commissions/pending_withdrawals', $data, 'admin');
    }
    
    /**
     * Exibe detalhes de uma solicitação de retirada
     * 
     * @param int $id ID da solicitação
     */
    public function withdrawalDetails($id) {
        // Obter solicitação pelo ID
        $withdrawal = $this->withdrawalRequestModel->getById($id);
        
        if (!$withdrawal) {
            set_flash_message('error', 'Solicitação não encontrada.');
            redirect('admin/comissoes/solicitacoes');
            return;
        }
        
        // Obter detalhes do vendedor
        $vendor = $this->vendorModel->getDetailsById($withdrawal['vendor_id']);
        
        // Obter comissões incluídas nesta solicitação
        $commissions = $this->commissionModel->getCommissionsByWithdrawalId($id);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes da Solicitação de Retirada',
            'withdrawal' => $withdrawal,
            'vendor' => $vendor,
            'commissions' => $commissions
        ];
        
        // Renderizar a view
        view_with_layout('admin/commissions/withdrawal_details', $data, 'admin');
    }
    
    /**
     * Processa o pagamento de uma solicitação de retirada
     * 
     * @param int $id ID da solicitação
     */
    public function processWithdrawal($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/comissoes/solicitacoes');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/comissoes/solicitacao/' . $id);
            return;
        }
        
        // Obter solicitação pelo ID
        $withdrawal = $this->withdrawalRequestModel->getById($id);
        
        if (!$withdrawal) {
            set_flash_message('error', 'Solicitação não encontrada.');
            redirect('admin/comissoes/solicitacoes');
            return;
        }
        
        // Verificar se a solicitação já foi processada
        if ($withdrawal['status'] !== 'pending') {
            set_flash_message('error', 'Esta solicitação já foi processada.');
            redirect('admin/comissoes/solicitacao/' . $id);
            return;
        }
        
        // Obter dados do formulário
        $paymentMethod = sanitize_string($_POST['payment_method'] ?? '');
        $paymentDetails = $_POST['payment_details'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Validar dados
        if (empty($paymentMethod)) {
            set_flash_message('error', 'O método de pagamento é obrigatório.');
            redirect('admin/comissoes/solicitacao/' . $id);
            return;
        }
        
        // Iniciar transação
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Atualizar status da solicitação
            $this->withdrawalRequestModel->update($id, [
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'payment_details' => $paymentDetails,
                'payment_date' => date('Y-m-d H:i:s'),
                'notes' => $notes,
                'processed_by' => get_current_user_id(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Atualizar status das comissões relacionadas
            $this->commissionModel->updateStatusByWithdrawalId($id, 'paid');
            
            // Zerar saldo disponível do vendedor
            $this->vendorModel->resetAvailableCommission($withdrawal['vendor_id'], $withdrawal['payment_date']);
            
            // Confirmar transação
            $db->commit();
            
            // Enviar e-mail de confirmação ao vendedor
            $vendor = $this->vendorModel->getDetailsById($withdrawal['vendor_id']);
            $this->emailService->sendCommissionPaymentConfirmation($vendor, $withdrawal);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Pagamento processado com sucesso! O vendedor foi notificado por e-mail.');
            redirect('admin/comissoes/solicitacoes');
            
        } catch (\Exception $e) {
            // Reverter transação em caso de erro
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao processar pagamento: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao processar pagamento: ' . $e->getMessage());
            redirect('admin/comissoes/solicitacao/' . $id);
        }
    }
    
    /**
     * Rejeita uma solicitação de retirada
     * 
     * @param int $id ID da solicitação
     */
    public function rejectWithdrawal($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/comissoes/solicitacoes');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/comissoes/solicitacao/' . $id);
            return;
        }
        
        // Obter solicitação pelo ID
        $withdrawal = $this->withdrawalRequestModel->getById($id);
        
        if (!$withdrawal) {
            set_flash_message('error', 'Solicitação não encontrada.');
            redirect('admin/comissoes/solicitacoes');
            return;
        }
        
        // Verificar se a solicitação já foi processada
        if ($withdrawal['status'] !== 'pending') {
            set_flash_message('error', 'Esta solicitação já foi processada.');
            redirect('admin/comissoes/solicitacao/' . $id);
            return;
        }
        
        // Obter motivo da rejeição
        $reason = $_POST['rejection_reason'] ?? '';
        
        // Iniciar transação
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Atualizar status da solicitação
            $this->withdrawalRequestModel->update($id, [
                'status' => 'rejected',
                'notes' => $reason,
                'processed_by' => get_current_user_id(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Retornar comissões para estado pendente
            $this->commissionModel->resetCommissionsByWithdrawalId($id);
            
            // Confirmar transação
            $db->commit();
            
            // Enviar e-mail de notificação ao vendedor
            $vendor = $this->vendorModel->getDetailsById($withdrawal['vendor_id']);
            $this->emailService->sendCommissionRejectionNotification($vendor, $withdrawal, $reason);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Solicitação rejeitada com sucesso! O vendedor foi notificado por e-mail.');
            redirect('admin/comissoes/solicitacoes');
            
        } catch (\Exception $e) {
            // Reverter transação em caso de erro
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao rejeitar solicitação: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao rejeitar solicitação: ' . $e->getMessage());
            redirect('admin/comissoes/solicitacao/' . $id);
        }
    }
    
    /**
     * Exibe relatório geral de comissões
     */
    public function report() {
        // Parâmetros de filtros
        $period = $_GET['period'] ?? 'month';
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        $groupBy = $_GET['group_by'] ?? 'month';
        
        // Definir datas com base no período
        list($startDate, $endDate) = $this->getDateRangeFromPeriod($period);
        
        // Obter dados do relatório
        $reportData = $this->commissionModel->getCommissionReport($startDate, $endDate, $vendorId, $groupBy);
        
        // Obter vendedores para filtro
        $vendors = $this->vendorModel->getAllBasic();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Relatório de Comissões',
            'reportData' => $reportData,
            'period' => $period,
            'vendorId' => $vendorId,
            'groupBy' => $groupBy,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'vendors' => $vendors
        ];
        
        // Renderizar a view
        view_with_layout('admin/commissions/report', $data, 'admin');
    }
    
    /**
     * Exporta relatório de comissões em CSV
     */
    public function exportReport() {
        // Parâmetros de filtros
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        $status = $_GET['status'] ?? 'all';
        
        // Obter dados para o relatório
        $commissions = $this->commissionModel->getCommissionsForReport($startDate, $endDate, $vendorId, $status);
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="comissoes_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'ID', 'Vendedor', 'Médico', 'Pedido', 'Cliente', 'Valor Pedido',
            'Taxa', 'Valor Comissão', 'Status', 'Data Pedido', 'Data Pagamento'
        ]);
        
        // Dados
        foreach ($commissions as $commission) {
            // Traduzir status
            $statusTranslated = '';
            switch ($commission['status']) {
                case 'pending': $statusTranslated = 'Pendente'; break;
                case 'processing': $statusTranslated = 'Em Processamento'; break;
                case 'paid': $statusTranslated = 'Pago'; break;
                case 'cancelled': $statusTranslated = 'Cancelado'; break;
                default: $statusTranslated = $commission['status'];
            }
            
            fputcsv($output, [
                $commission['id'],
                $commission['vendor_name'],
                $commission['doctor_name'] ?? 'N/A',
                $commission['order_number'],
                $commission['customer_name'],
                'R$ ' . number_format($commission['order_amount'], 2, ',', '.'),
                number_format($commission['rate'], 2, ',', '.') . '%',
                'R$ ' . number_format($commission['amount'], 2, ',', '.'),
                $statusTranslated,
                date('d/m/Y', strtotime($commission['created_at'])),
                $commission['payment_date'] ? date('d/m/Y', strtotime($commission['payment_date'])) : '-'
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * Retorna o intervalo de datas com base no período selecionado
     * 
     * @param string $period Período selecionado (month, quarter, year, etc)
     * @return array Array com data inicial e final
     */
    private function getDateRangeFromPeriod($period) {
        $endDate = date('Y-m-d');
        $startDate = '';
        
        switch ($period) {
            case 'week':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            
            case 'month':
                $startDate = date('Y-m-01');
                break;
            
            case 'quarter':
                $startDate = date('Y-m-d', strtotime('first day of -3 months'));
                break;
            
            case 'year':
                $startDate = date('Y-01-01');
                break;
            
            case 'lastmonth':
                $startDate = date('Y-m-01', strtotime('first day of last month'));
                $endDate = date('Y-m-t', strtotime('last day of last month'));
                break;
            
            case 'lastyear':
                $year = date('Y') - 1;
                $startDate = "$year-01-01";
                $endDate = "$year-12-31";
                break;
            
            default:
                $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        
        return [$startDate, $endDate];
    }
}