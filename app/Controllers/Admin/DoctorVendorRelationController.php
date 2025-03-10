<?php
/**
 * DoctorVendorRelationController.php - Controlador para gerenciamento de relações entre médicos e vendedores
 * 
 * Este controlador gerencia o relacionamento entre médicos e vendedores, permitindo
 * criar, editar e gerenciar as associações e taxas de comissão.
 */

namespace App\Controllers\Admin;

use App\Models\DoctorModel;
use App\Models\VendorModel;
use App\Models\DoctorVendorModel;
use App\Models\CommissionModel;

class DoctorVendorRelationController {
    /**
     * @var DoctorModel Modelo de médicos
     */
    private $doctorModel;
    
    /**
     * @var VendorModel Modelo de vendedores
     */
    private $vendorModel;
    
    /**
     * @var DoctorVendorModel Modelo de relação médico-vendedor
     */
    private $doctorVendorModel;
    
    /**
     * @var CommissionModel Modelo de comissões
     */
    private $commissionModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->doctorModel = new DoctorModel();
        $this->vendorModel = new VendorModel();
        $this->doctorVendorModel = new DoctorVendorModel();
        $this->commissionModel = new CommissionModel();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todas as relações médicos-vendedores
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Obter relações com filtros aplicados
        $relations = $this->doctorVendorModel->getAllWithFilters(
            $search,
            $status,
            $doctorId,
            $vendorId,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalRelations = $this->doctorVendorModel->countAllWithFilters(
            $search,
            $status,
            $doctorId,
            $vendorId
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalRelations / $limit);
        
        // Obter médicos e vendedores para filtros
        $doctors = $this->doctorModel->getAllBasic();
        $vendors = $this->vendorModel->getAllBasic();
        
        // Estatísticas rápidas
        $stats = [
            'totalRelations' => $this->doctorVendorModel->countAll(),
            'activeRelations' => $this->doctorVendorModel->countByStatus('active'),
            'totalDoctors' => $this->doctorModel->countAll(),
            'totalVendors' => $this->vendorModel->countAll()
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Relações Médicos-Vendedores',
            'relations' => $relations,
            'doctors' => $doctors,
            'vendors' => $vendors,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'doctorId' => $doctorId,
            'vendorId' => $vendorId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalRelations' => $totalRelations
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctor_vendor/index', $data, 'admin');
    }
    
    /**
     * Exibe o formulário para criar uma nova relação
     */
    public function create() {
        // Obter médicos ativos
        $doctors = $this->doctorModel->getAllByStatus('active');
        
        // Obter vendedores ativos
        $vendors = $this->vendorModel->getAllByStatus('active');
        
        // Preparar dados para a view
        $data = [
            'title' => 'Nova Relação Médico-Vendedor',
            'doctors' => $doctors,
            'vendors' => $vendors,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('admin/doctor_vendor/create', $data, 'admin');
    }
    
    /**
     * Processa o formulário de criação de relação
     */
    public function store() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/relacoes');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/relacoes/nova');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateRelationForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/relacoes/nova');
            return;
        }
        
        // Obter dados do formulário
        $doctorId = (int) $_POST['doctor_id'];
        $vendorId = (int) $_POST['vendor_id'];
        $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
        
        // Verificar se relação já existe
        $existingRelation = $this->doctorVendorModel->getRelation($doctorId, $vendorId);
        
        if ($existingRelation) {
            set_flash_message('error', 'Esta relação já existe. Edite a relação existente para alterar a taxa de comissão.');
            redirect('admin/relacoes/nova');
            return;
        }
        
        try {
            // Criar relação
            $relationData = [
                'doctor_id' => $doctorId,
                'vendor_id' => $vendorId,
                'commission_rate' => $commissionRate,
                'status' => $_POST['status'] ?? 'active',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $this->doctorVendorModel->create($relationData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Relação médico-vendedor criada com sucesso!');
            redirect('admin/relacoes');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao criar relação médico-vendedor: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao criar relação: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/relacoes/nova');
        }
    }
    
    /**
     * Exibe o formulário para editar uma relação
     * 
     * @param int $id ID da relação
     */
    public function edit($id) {
        // Obter relação pelo ID
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Obter médico e vendedor
        $doctor = $this->doctorModel->getById($relation['doctor_id']);
        $vendor = $this->vendorModel->getById($relation['vendor_id']);
        
        // Estatísticas da relação
        $stats = [
            'totalSales' => $this->doctorVendorModel->countSalesByRelation($id),
            'totalCommissions' => $this->doctorVendorModel->getTotalCommissionsByRelation($id)
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Editar Relação Médico-Vendedor',
            'relation' => $relation,
            'doctor' => $doctor,
            'vendor' => $vendor,
            'stats' => $stats,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('admin/doctor_vendor/edit', $data, 'admin');
    }
    
    /**
     * Processa o formulário de edição de relação
     * 
     * @param int $id ID da relação
     */
    public function update($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/relacoes');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/relacoes/editar/' . $id);
            return;
        }
        
        // Obter relação existente
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Validar taxa de comissão
        $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
        
        if ($commissionRate < 0 || $commissionRate > 100) {
            $_SESSION['errors'] = ['commission_rate' => 'A taxa de comissão deve estar entre 0 e 100%.'];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/relacoes/editar/' . $id);
            return;
        }
        
        try {
            // Atualizar relação
            $relationData = [
                'commission_rate' => $commissionRate,
                'status' => $_POST['status'] ?? 'active',
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->doctorVendorModel->update($id, $relationData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Relação médico-vendedor atualizada com sucesso!');
            redirect('admin/relacoes');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar relação médico-vendedor: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao atualizar relação: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/relacoes/editar/' . $id);
        }
    }
    
    /**
     * Exibe detalhes de uma relação
     * 
     * @param int $id ID da relação
     */
    public function details($id) {
        // Obter relação pelo ID
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Obter médico e vendedor
        $doctor = $this->doctorModel->getById($relation['doctor_id']);
        $vendor = $this->vendorModel->getById($relation['vendor_id']);
        
        // Estatísticas da relação
        $stats = [
            'totalSales' => $this->doctorVendorModel->countSalesByRelation($id),
            'totalCommissions' => $this->doctorVendorModel->getTotalCommissionsByRelation($id),
            'lastSale' => $this->doctorVendorModel->getLastSaleByRelation($id)
        ];
        
        // Obter histórico de comissões
        $commissions = $this->commissionModel->getCommissionsByRelation($id, 10);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes da Relação Médico-Vendedor',
            'relation' => $relation,
            'doctor' => $doctor,
            'vendor' => $vendor,
            'stats' => $stats,
            'commissions' => $commissions
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctor_vendor/details', $data, 'admin');
    }
    
    /**
     * Ativa uma relação
     * 
     * @param int $id ID da relação
     */
    public function enable($id) {
        // Obter relação pelo ID
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/relacoes');
            return;
        }
        
        try {
            // Atualizar status da relação
            $this->doctorVendorModel->update($id, [
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Relação ativada com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao ativar relação: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao ativar relação: ' . $e->getMessage());
        }
        
        redirect('admin/relacoes');
    }
    
    /**
     * Desativa uma relação
     * 
     * @param int $id ID da relação
     */
    public function disable($id) {
        // Obter relação pelo ID
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/relacoes');
            return;
        }
        
        try {
            // Atualizar status da relação
            $this->doctorVendorModel->update($id, [
                'status' => 'inactive',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Relação desativada com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao desativar relação: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao desativar relação: ' . $e->getMessage());
        }
        
        redirect('admin/relacoes');
    }
    
    /**
     * Exclui uma relação
     * 
     * @param int $id ID da relação
     */
    public function delete($id) {
        // Obter relação pelo ID
        $relation = $this->doctorVendorModel->getById($id);
        
        if (!$relation) {
            set_flash_message('error', 'Relação não encontrada.');
            redirect('admin/relacoes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/relacoes');
            return;
        }
        
        // Verificar se há comissões associadas
        $hasCommissions = $this->commissionModel->hasCommissionsByRelation($relation['doctor_id'], $relation['vendor_id']);
        
        if ($hasCommissions) {
            set_flash_message('error', 'Esta relação não pode ser excluída porque existem comissões associadas a ela.');
            redirect('admin/relacoes');
            return;
        }
        
        try {
            // Excluir relação
            $this->doctorVendorModel->delete($id);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Relação excluída com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao excluir relação: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao excluir relação: ' . $e->getMessage());
        }
        
        redirect('admin/relacoes');
    }
    
    /**
     * Exporta lista de relações em CSV
     */
    public function export() {
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Obter todas as relações com filtros
        $relations = $this->doctorVendorModel->getAllWithFiltersNoLimit($search, $status, $doctorId, $vendorId);
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="relacoes_medicos_vendedores_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'ID', 'Médico', 'CRM', 'Vendedor', 'Taxa de Comissão', 
            'Status', 'Total de Vendas', 'Total de Comissões', 'Data de Criação'
        ]);
        
        // Dados
        foreach ($relations as $relation) {
            // Formatar status
            $statusTranslated = '';
            switch ($relation['status']) {
                case 'active': $statusTranslated = 'Ativo'; break;
                case 'inactive': $statusTranslated = 'Inativo'; break;
                default: $statusTranslated = $relation['status'];
            }
            
            fputcsv($output, [
                $relation['id'],
                $relation['doctor_name'],
                $relation['crm'] . ' - ' . $relation['crm_state'],
                $relation['vendor_name'],
                number_format($relation['commission_rate'], 2, ',', '.') . '%',
                $statusTranslated,
                $relation['sales_count'] ?? 0,
                'R$ ' . number_format($relation['total_commissions'] ?? 0, 2, ',', '.'),
                date('d/m/Y', strtotime($relation['created_at']))
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * Gera relatório de comissões por relação
     */
    public function report() {
        // Parâmetros de relatório
        $period = $_GET['period'] ?? 'month';
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Definir datas com base no período
        list($startDate, $endDate) = $this->getDateRangeFromPeriod($period);
        
        // Obter dados do relatório
        $reportData = $this->doctorVendorModel->getCommissionReport(
            $startDate,
            $endDate,
            $doctorId,
            $vendorId
        );
        
        // Obter médicos e vendedores para filtros
        $doctors = $this->doctorModel->getAllBasic();
        $vendors = $this->vendorModel->getAllBasic();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Relatório de Comissões por Relação',
            'reportData' => $reportData,
            'period' => $period,
            'doctorId' => $doctorId,
            'vendorId' => $vendorId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'doctors' => $doctors,
            'vendors' => $vendors
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctor_vendor/report', $data, 'admin');
    }
    
    /**
     * Validação do formulário de relação
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateRelationForm($data) {
        $errors = [];
        
        // Médico
        if (empty($data['doctor_id'])) {
            $errors['doctor_id'] = 'Selecione um médico.';
        }
        
        // Vendedor
        if (empty($data['vendor_id'])) {
            $errors['vendor_id'] = 'Selecione um vendedor.';
        }
        
        // Taxa de comissão
        if (!isset($data['commission_rate']) || $data['commission_rate'] === '') {
            $errors['commission_rate'] = 'A taxa de comissão é obrigatória.';
        } else {
            $commissionRate = floatval(str_replace(',', '.', $data['commission_rate']));
            
            if ($commissionRate < 0 || $commissionRate > 100) {
                $errors['commission_rate'] = 'A taxa de comissão deve estar entre 0 e 100%.';
            }
        }
        
        return $errors;
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