<?php
/**
 * CustomerController.php - Controlador para gerenciamento de clientes no admin
 * 
 * Este controlador gerencia todas as operações relacionadas a clientes,
 * incluindo listagem, verificação de documentos ANVISA, detalhes de cliente e
 * gerenciamento de status de clientes.
 */

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\CustomerModel;
use App\Models\DocumentModel;
use App\Models\OrderModel;
use App\Services\EmailService;

class CustomerController {
    /**
     * @var UserModel Modelo de usuários
     */
    private $userModel;
    
    /**
     * @var CustomerModel Modelo de clientes
     */
    private $customerModel;
    
    /**
     * @var DocumentModel Modelo de documentos
     */
    private $documentModel;
    
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * @var EmailService Serviço de e-mail
     */
    private $emailService;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos e serviços
        $this->userModel = new UserModel();
        $this->customerModel = new CustomerModel();
        $this->documentModel = new DocumentModel();
        $this->orderModel = new OrderModel();
        $this->emailService = new EmailService();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todos os clientes
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $anvisa = isset($_GET['anvisa']) ? (int)$_GET['anvisa'] : -1; // -1: todos, 0: não verificado, 1: verificado
        
        // Obter clientes com filtros aplicados
        $customers = $this->customerModel->getAllWithFilters(
            $search,
            $status,
            $anvisa,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalCustomers = $this->customerModel->countAllWithFilters(
            $search,
            $status,
            $anvisa
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalCustomers / $limit);
        
        // Estatísticas rápidas
        $stats = [
            'totalCustomers' => $this->customerModel->countAll(),
            'pendingAnvisa' => $this->customerModel->countByStatus('pending'),
            'activeCustomers' => $this->customerModel->countByStatus('active'),
            'pendingDocuments' => $this->documentModel->countPendingAnvisa()
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Clientes',
            'customers' => $customers,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'anvisa' => $anvisa,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCustomers' => $totalCustomers
        ];
        
        // Renderizar a view
        view_with_layout('admin/customers/index', $data, 'admin');
    }
    
    /**
     * Exibe detalhes de um cliente
     * 
     * @param int $id ID do cliente
     */
    public function details($id) {
        // Obter cliente pelo ID
        $customer = $this->customerModel->getDetailsById($id);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Obter documentos do cliente
        $documents = $this->documentModel->getByUserId($customer['user_id']);
        
        // Obter pedidos do cliente
        $orders = $this->orderModel->getByUserId($customer['user_id'], 10);
        
        // Estatísticas do cliente
        $stats = [
            'orderCount' => $this->orderModel->countByUserId($customer['user_id']),
            'totalSpent' => $this->orderModel->getTotalSpentByUserId($customer['user_id']),
            'lastOrder' => $this->orderModel->getLastOrderByUserId($customer['user_id']),
            'averageOrderValue' => $this->orderModel->getAverageOrderValueByUserId($customer['user_id'])
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes do Cliente',
            'customer' => $customer,
            'documents' => $documents,
            'orders' => $orders,
            'stats' => $stats
        ];
        
        // Renderizar a view
        view_with_layout('admin/customers/details', $data, 'admin');
    }
    
    /**
     * Exibe documentos de um cliente
     * 
     * @param int $id ID do cliente
     */
    public function documents($id) {
        // Obter cliente pelo ID
        $customer = $this->customerModel->getDetailsById($id);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Obter documentos do cliente
        $documents = $this->documentModel->getByUserId($customer['user_id']);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Documentos do Cliente',
            'customer' => $customer,
            'documents' => $documents
        ];
        
        // Renderizar a view
        view_with_layout('admin/customers/documents', $data, 'admin');
    }
    
    /**
     * Exibe detalhes do documento para aprovação/rejeição
     * 
     * @param int $id ID do documento
     */
    public function viewDocument($id) {
        // Obter documento pelo ID
        $document = $this->documentModel->getById($id);
        
        if (!$document) {
            set_flash_message('error', 'Documento não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Obter cliente associado ao documento
        $customer = $this->customerModel->getByUserId($document['user_id']);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Preparar dados para a view
        $data = [
            'title' => 'Verificar Documento',
            'document' => $document,
            'customer' => $customer
        ];
        
        // Renderizar a view
        view_with_layout('admin/customers/view_document', $data, 'admin');
    }
    
    /**
     * Processa a aprovação de um documento ANVISA
     * 
     * @param int $id ID do documento
     */
    public function approveDocument($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/clientes/documentos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Obter documento pelo ID
        $document = $this->documentModel->getById($id);
        
        if (!$document) {
            set_flash_message('error', 'Documento não encontrado.');
            redirect('admin/clientes/documentos');
            return;
        }
        
        // Verificar se o documento já foi processado
        if ($document['status'] !== 'pending') {
            set_flash_message('error', 'Este documento já foi processado.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Verificar se é um documento ANVISA
        if ($document['type'] !== 'anvisa_authorization') {
            set_flash_message('error', 'Este documento não é uma autorização ANVISA.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Obter notas do admin
        $notes = sanitize_string($_POST['notes'] ?? '');
        
        // Iniciar transação
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Atualizar status do documento
            $this->documentModel->update($id, [
                'status' => 'approved',
                'approved_by' => get_current_user_id(),
                'approved_at' => date('Y-m-d H:i:s'),
                'notes' => $notes
            ]);
            
            // Atualizar cliente com verificação ANVISA
            $this->customerModel->updateAnvisaStatus($document['user_id'], true, get_current_user_id());
            
            // Atualizar status do usuário para ativo
            $this->userModel->update($document['user_id'], [
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Confirmar transação
            $db->commit();
            
            // Enviar e-mail de confirmação ao cliente
            $customer = $this->customerModel->getByUserId($document['user_id']);
            $this->emailService->sendAnvisaApprovalEmail($customer);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Documento ANVISA aprovado com sucesso! O cliente foi ativado.');
            
        } catch (\Exception $e) {
            // Reverter transação em caso de erro
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao aprovar documento ANVISA: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao aprovar documento: ' . $e->getMessage());
        }
        
        // Redirecionar para a lista de documentos pendentes
        redirect('admin/documentos/anvisa');
    }
    
    /**
     * Processa a rejeição de um documento ANVISA
     * 
     * @param int $id ID do documento
     */
    public function rejectDocument($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/clientes/documentos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Obter documento pelo ID
        $document = $this->documentModel->getById($id);
        
        if (!$document) {
            set_flash_message('error', 'Documento não encontrado.');
            redirect('admin/clientes/documentos');
            return;
        }
        
        // Verificar se o documento já foi processado
        if ($document['status'] !== 'pending') {
            set_flash_message('error', 'Este documento já foi processado.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Verificar se é um documento ANVISA
        if ($document['type'] !== 'anvisa_authorization') {
            set_flash_message('error', 'Este documento não é uma autorização ANVISA.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        // Obter motivo da rejeição
        $reason = sanitize_string($_POST['rejection_reason'] ?? '');
        
        if (empty($reason)) {
            set_flash_message('error', 'É necessário informar o motivo da rejeição.');
            redirect('admin/clientes/documento/' . $id);
            return;
        }
        
        try {
            // Atualizar status do documento
            $this->documentModel->update($id, [
                'status' => 'rejected',
                'approved_by' => get_current_user_id(),
                'approved_at' => date('Y-m-d H:i:s'),
                'notes' => $reason
            ]);
            
            // Enviar e-mail de rejeição ao cliente
            $customer = $this->customerModel->getByUserId($document['user_id']);
            $this->emailService->sendAnvisaRejectionEmail($customer, $reason);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Documento ANVISA rejeitado. O cliente foi notificado por e-mail.');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao rejeitar documento ANVISA: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao rejeitar documento: ' . $e->getMessage());
        }
        
        // Redirecionar para a lista de documentos pendentes
        redirect('admin/documentos/anvisa');
    }
    
    /**
     * Ativa um cliente
     * 
     * @param int $id ID do cliente
     */
    public function activate($id) {
        // Obter cliente pelo ID
        $customer = $this->customerModel->getById($id);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/clientes');
            return;
        }
        
        try {
            // Atualizar status do usuário
            $this->userModel->update($customer['user_id'], [
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Cliente ativado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao ativar cliente: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao ativar cliente: ' . $e->getMessage());
        }
        
        redirect('admin/clientes');
    }
    
    /**
     * Desativa um cliente
     * 
     * @param int $id ID do cliente
     */
    public function deactivate($id) {
        // Obter cliente pelo ID
        $customer = $this->customerModel->getById($id);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/clientes');
            return;
        }
        
        try {
            // Atualizar status do usuário
            $this->userModel->update($customer['user_id'], [
                'status' => 'inactive',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Cliente desativado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao desativar cliente: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao desativar cliente: ' . $e->getMessage());
        }
        
        redirect('admin/clientes');
    }
    
    /**
     * Bloqueia um cliente
     * 
     * @param int $id ID do cliente
     */
    public function block($id) {
        // Obter cliente pelo ID
        $customer = $this->customerModel->getById($id);
        
        if (!$customer) {
            set_flash_message('error', 'Cliente não encontrado.');
            redirect('admin/clientes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/clientes');
            return;
        }
        
        try {
            // Atualizar status do usuário
            $this->userModel->update($customer['user_id'], [
                'status' => 'blocked',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Cliente bloqueado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao bloquear cliente: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao bloquear cliente: ' . $e->getMessage());
        }
        
        redirect('admin/clientes');
    }
    
    /**
     * Exporta lista de clientes em CSV
     */
    public function export() {
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $anvisa = isset($_GET['anvisa']) ? (int)$_GET['anvisa'] : -1;
        
        // Obter todos os clientes com filtros
        $customers = $this->customerModel->getAllWithFiltersNoLimit($search, $status, $anvisa);
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'ID', 'Nome', 'E-mail', 'Telefone', 'CPF', 'Data de Nascimento',
            'ANVISA Verificado', 'Total Gasto', 'Total Pedidos', 'Status',
            'Data de Cadastro'
        ]);
        
        // Dados
        foreach ($customers as $customer) {
            // Formatar status
            $statusTranslated = '';
            switch ($customer['status']) {
                case 'active': $statusTranslated = 'Ativo'; break;
                case 'inactive': $statusTranslated = 'Inativo'; break;
                case 'pending': $statusTranslated = 'Pendente'; break;
                case 'blocked': $statusTranslated = 'Bloqueado'; break;
                default: $statusTranslated = $customer['status'];
            }
            
            fputcsv($output, [
                $customer['id'],
                $customer['name'],
                $customer['email'],
                $customer['phone'],
                $customer['document'],
                $customer['date_of_birth'] ? date('d/m/Y', strtotime($customer['date_of_birth'])) : '',
                $customer['anvisa_verified'] ? 'Sim' : 'Não',
                'R$ ' . number_format($customer['total_spent'] ?? 0, 2, ',', '.'),
                $customer['order_count'] ?? 0,
                $statusTranslated,
                date('d/m/Y', strtotime($customer['created_at']))
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * Gera relatório de clientes
     */
    public function report() {
        // Parâmetros de relatório
        $period = $_GET['period'] ?? 'month';
        $type = $_GET['type'] ?? 'new_customers';
        
        // Definir datas com base no período
        list($startDate, $endDate) = $this->getDateRangeFromPeriod($period);
        
        // Obter dados do relatório
        $reportData = [];
        
        switch ($type) {
            case 'new_customers':
                $reportData = $this->customerModel->getNewCustomersReport($startDate, $endDate);
                break;
                
            case 'customer_value':
                $reportData = $this->customerModel->getCustomerValueReport($startDate, $endDate);
                break;
                
            case 'customer_anvisa':
                $reportData = $this->customerModel->getAnvisaVerificationReport($startDate, $endDate);
                break;
                
            case 'customer_status':
                $reportData = $this->customerModel->getCustomerStatusReport();
                break;
        }
        
        // Preparar dados para a view
        $data = [
            'title' => 'Relatório de Clientes',
            'reportData' => $reportData,
            'period' => $period,
            'type' => $type,
            'startDate' => $startDate,
            'endDate' => $endDate
        ];
        
        // Renderizar a view
        view_with_layout('admin/customers/report', $data, 'admin');
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