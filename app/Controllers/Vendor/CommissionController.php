<?php
/**
 * CommissionController.php - Controlador de comissões para área do vendedor
 * 
 * Este controlador gerencia a visualização de comissões, solicitações de retirada
 * e acompanhamento dos pagamentos para o vendedor.
 */

namespace App\Controllers\Vendor;

use App\Models\CommissionModel;
use App\Models\WithdrawalRequestModel;
use App\Models\OrderModel;

class CommissionController {
    /**
     * @var CommissionModel Modelo de comissões
     */
    private $commissionModel;
    
    /**
     * @var WithdrawalRequestModel Modelo de solicitações de retirada
     */
    private $withdrawalRequestModel;
    
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->commissionModel = new CommissionModel();
        $this->withdrawalRequestModel = new WithdrawalRequestModel();
        $this->orderModel = new OrderModel();
        
        // Verificar se o usuário é vendedor
        if (!is_vendor()) {
            redirect('login');
        }
    }
    
    /**
     * Mostra o painel principal de comissões
     */
    public function index() {
        $vendorId = get_current_user_id();
        
        // Obter resumo financeiro
        $financialSummary = $this->commissionModel->getVendorFinancialSummary($vendorId);
        
        // Obter comissões pendentes
        $pendingCommissions = $this->commissionModel->getVendorPendingCommissions($vendorId, 10);
        
        // Obter último pagamento
        $lastPayment = $this->withdrawalRequestModel->getLastCompletedWithdrawal($vendorId);
        
        // Verificar se há solicitações pendentes
        $pendingRequest = $this->withdrawalRequestModel->getVendorPendingRequest($vendorId);
        
        // Obter histórico recente de retiradas
        $withdrawalHistory = $this->withdrawalRequestModel->getVendorWithdrawalHistory($vendorId, 5);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Minhas Comissões',
            'financialSummary' => $financialSummary,
            'pendingCommissions' => $pendingCommissions,
            'lastPayment' => $lastPayment,
            'pendingRequest' => $pendingRequest,
            'withdrawalHistory' => $withdrawalHistory,
            'canRequestWithdrawal' => $financialSummary['available_commission'] >= 50 && !$pendingRequest
        ];
        
        // Renderizar a view
        view_with_layout('vendor/commissions/index', $data, 'vendor');
    }
    
    /**
     * Exibe histórico de comissões
     */
    public function history() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Obter comissões com filtros
        $commissions = $this->commissionModel->getVendorCommissionsWithFilters(
            $vendorId,
            $status,
            $startDate,
            $endDate,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalCommissions = $this->commissionModel->countVendorCommissionsWithFilters(
            $vendorId,
            $status,
            $startDate,
            $endDate
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalCommissions / $limit);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Histórico de Comissões',
            'commissions' => $commissions,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCommissions' => $totalCommissions
        ];
        
        // Renderizar a view
        view_with_layout('vendor/commissions/history', $data, 'vendor');
    }
    
    /**
     * Exibe histórico de retiradas
     */
    public function withdrawals() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de paginação
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        // Obter histórico de retiradas
        $withdrawals = $this->withdrawalRequestModel->getVendorWithdrawalHistory($vendorId, $limit, $offset);
        
        // Obter contagem total para paginação
        $totalWithdrawals = $this->withdrawalRequestModel->countVendorWithdrawals($vendorId);
        
        // Calcular total de páginas
        $totalPages = ceil($totalWithdrawals / $limit);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Histórico de Retiradas',
            'withdrawals' => $withdrawals,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalWithdrawals' => $totalWithdrawals
        ];
        
        // Renderizar a view
        view_with_layout('vendor/commissions/withdrawals', $data, 'vendor');
    }
    
    /**
     * Exibe formulário para solicitar retirada
     */
    public function requestWithdrawal() {
        $vendorId = get_current_user_id();
        
        // Verificar se há solicitação pendente
        $pendingRequest = $this->withdrawalRequestModel->getVendorPendingRequest($vendorId);
        
        if ($pendingRequest) {
            set_flash_message('error', 'Você já possui uma solicitação de retirada pendente.');
            redirect('vendedor/comissoes');
            return;
        }
        
        // Obter comissões disponíveis
        $financialSummary = $this->commissionModel->getVendorFinancialSummary($vendorId);
        $availableAmount = $financialSummary['available_commission'];
        
        // Verificar valor mínimo
        if ($availableAmount < 50) {
            set_flash_message('error', 'Você precisa ter pelo menos R$ 50,00 em comissões disponíveis para solicitar uma retirada.');
            redirect('vendedor/comissoes');
            return;
        }
        
        // Se for POST, processar solicitação
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validar token CSRF
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
                redirect('vendedor/comissoes/solicitar-retirada');
                return;
            }
            
            // Validar valor solicitado
            $requestedAmount = floatval(str_replace(',', '.', $_POST['amount'] ?? '0'));
            
            if ($requestedAmount <= 0) {
                set_flash_message('error', 'O valor solicitado deve ser maior que zero.');
                redirect('vendedor/comissoes/solicitar-retirada');
                return;
            }
            
            if ($requestedAmount > $availableAmount) {
                set_flash_message('error', 'O valor solicitado não pode ser maior que o disponível.');
                redirect('vendedor/comissoes/solicitar-retirada');
                return;
            }
            
            // Iniciar transação
            $db = getDbConnection();
            $db->beginTransaction();
            
            try {
                // Criar solicitação de retirada
                $withdrawalData = [
                    'vendor_id' => $vendorId,
                    'amount' => $requestedAmount,
                    'status' => 'pending',
                    'request_date' => date('Y-m-d H:i:s'),
                    'notes' => sanitize_string($_POST['notes'] ?? '')
                ];
                
                $withdrawalId = $this->withdrawalRequestModel->create($withdrawalData);
                
                // Marcar comissões como "em processamento"
                $this->commissionModel->assignCommissionsToWithdrawal($vendorId, $withdrawalId, $requestedAmount);
                
                $db->commit();
                
                set_flash_message('success', 'Solicitação de retirada enviada com sucesso! Aguarde a aprovação do administrador.');
                redirect('vendedor/comissoes');
                
            } catch (\Exception $e) {
                $db->rollBack();
                
                // Registrar erro
                log_message('Erro ao solicitar retirada: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                set_flash_message('error', 'Erro ao solicitar retirada: ' . $e->getMessage());
                redirect('vendedor/comissoes/solicitar-retirada');
            }
            
            return;
        }
        
        // Preparar dados para a view
        $data = [
            'title' => 'Solicitar Retirada',
            'availableAmount' => $availableAmount,
            'bankInfo' => $this->getVendorBankInfo($vendorId),
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('vendor/commissions/request_withdrawal', $data, 'vendor');
    }
    
    /**
     * Cancelar uma solicitação de retirada pendente
     * 
     * @param int $id ID da solicitação
     */
    public function cancelWithdrawal($id) {
        $vendorId = get_current_user_id();
        
        // Obter solicitação
        $withdrawal = $this->withdrawalRequestModel->getById($id);
        
        if (!$withdrawal || $withdrawal['vendor_id'] != $vendorId) {
            set_flash_message('error', 'Solicitação não encontrada ou não pertence a você.');
            redirect('vendedor/comissoes/retiradas');
            return;
        }
        
        // Verificar se está pendente
        if ($withdrawal['status'] !== 'pending') {
            set_flash_message('error', 'Apenas solicitações pendentes podem ser canceladas.');
            redirect('vendedor/comissoes/retiradas');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/comissoes/retiradas');
            return;
        }
        // Iniciar transação
       $db = getDbConnection();
       $db->beginTransaction();
       
       try {
           // Atualizar status da solicitação
           $this->withdrawalRequestModel->update($id, [
               'status' => 'cancelled',
               'notes' => ($withdrawal['notes'] ? $withdrawal['notes'] . ' | ' : '') . 'Cancelado pelo vendedor',
               'updated_at' => date('Y-m-d H:i:s')
           ]);
           
           // Retornar comissões para estado pendente
           $this->commissionModel->resetCommissionsByWithdrawalId($id);
           
           $db->commit();
           
           set_flash_message('success', 'Solicitação de retirada cancelada com sucesso!');
           
       } catch (\Exception $e) {
           $db->rollBack();
           
           // Registrar erro
           log_message('Erro ao cancelar solicitação: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao cancelar solicitação: ' . $e->getMessage());
       }
       
       redirect('vendedor/comissoes/retiradas');
   }
   
   /**
    * Exibe relatório de comissões
    */
   public function report() {
       $vendorId = get_current_user_id();
       
       // Parâmetros de filtros
       $period = $_GET['period'] ?? 'month';
       $groupBy = $_GET['group_by'] ?? 'day';
       
       // Definir datas com base no período
       list($startDate, $endDate) = $this->getDateRangeFromPeriod($period);
       
       // Obter dados para o relatório
       $reportData = $this->commissionModel->getVendorCommissionReport($vendorId, $startDate, $endDate, $groupBy);
       
       // Preparar dados para a view
       $data = [
           'title' => 'Relatório de Comissões',
           'reportData' => $reportData,
           'period' => $period,
           'groupBy' => $groupBy,
           'startDate' => $startDate,
           'endDate' => $endDate
       ];
       
       // Renderizar a view
       view_with_layout('vendor/commissions/report', $data, 'vendor');
   }
   
   /**
    * Exporta comissões em CSV
    */
   public function export() {
       $vendorId = get_current_user_id();
       
       // Parâmetros de filtros
       $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
       $endDate = $_GET['end_date'] ?? date('Y-m-d');
       $status = $_GET['status'] ?? 'all';
       
       // Obter dados para o relatório
       $commissions = $this->commissionModel->getVendorCommissionsForExport($vendorId, $status, $startDate, $endDate);
       
       // Configurar cabeçalhos HTTP
       header('Content-Type: text/csv; charset=utf-8');
       header('Content-Disposition: attachment; filename="minhas_comissoes_' . date('Y-m-d') . '.csv"');
       
       // Criar handle de arquivo para output
       $output = fopen('php://output', 'w');
       
       // Adicionar BOM para UTF-8
       fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
       
       // Cabeçalhos
       fputcsv($output, [
           'ID', 'Pedido', 'Cliente', 'Médico', 'Valor do Pedido',
           'Taxa', 'Valor da Comissão', 'Status', 'Data do Pedido', 'Data de Pagamento'
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
               $commission['order_number'],
               $commission['customer_name'],
               $commission['doctor_name'] ?? 'N/A',
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
    * Obtém informações bancárias do vendedor
    * 
    * @param int $vendorId ID do vendedor
    * @return string Informações bancárias formatadas
    */
   private function getVendorBankInfo($vendorId) {
       $vendor = $this->vendorModel->getById($vendorId);
       
       if (!$vendor || empty($vendor['bank_info'])) {
           return 'Informações bancárias não cadastradas. Atualize seu perfil.';
       }
       
       return $vendor['bank_info'];
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