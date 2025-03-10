<?php
/**
 * DoctorController.php - Controlador para gerenciamento de médicos parceiros pelo vendedor
 * 
 * Este controlador permite que os vendedores gerenciem seus médicos parceiros,
 * visualizem comissões relacionadas a médicos e acompanhem as prescrições.
 */

namespace App\Controllers\Vendor;

use App\Models\DoctorModel;
use App\Models\DoctorVendorModel;
use App\Models\OrderModel;
use App\Models\CommissionModel;
use App\Services\EmailService;

class DoctorController {
    /**
     * @var DoctorModel Modelo de médicos
     */
    private $doctorModel;
    
    /**
     * @var DoctorVendorModel Modelo de relação médico-vendedor
     */
    private $doctorVendorModel;
    
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * @var CommissionModel Modelo de comissões
     */
    private $commissionModel;
    
    /**
     * @var EmailService Serviço de e-mail
     */
    private $emailService;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos e serviços
        $this->doctorModel = new DoctorModel();
        $this->doctorVendorModel = new DoctorVendorModel();
        $this->orderModel = new OrderModel();
        $this->commissionModel = new CommissionModel();
        $this->emailService = new EmailService();
        
        // Verificar se o usuário é vendedor
        if (!is_vendor()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todos os médicos associados ao vendedor
     */
    public function index() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $specialty = $_GET['specialty'] ?? '';
        $orderBy = $_GET['order_by'] ?? 'name';
        $orderDirection = $_GET['order_direction'] ?? 'asc';
        
        // Obter médicos com filtros aplicados
        $doctors = $this->doctorModel->getVendorDoctorsWithFilters(
            $vendorId,
            $search,
            $specialty,
            $orderBy,
            $orderDirection,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalDoctors = $this->doctorModel->countVendorDoctorsWithFilters(
            $vendorId,
            $search,
            $specialty
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalDoctors / $limit);
        
        // Obter especialidades para filtro
        $specialties = $this->doctorModel->getAllSpecialties();
        
        // Obter estatísticas
        $stats = [
            'totalDoctors' => $totalDoctors,
            'totalPrescriptions' => $this->doctorModel->countTotalPrescriptionsByVendor($vendorId),
            'totalCommissions' => $this->doctorModel->getTotalDoctorCommissionsByVendor($vendorId),
            'topDoctors' => $this->doctorModel->getTopDoctorsByVendor($vendorId, 5)
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Meus Médicos Parceiros',
            'doctors' => $doctors,
            'specialties' => $specialties,
            'stats' => $stats,
            'search' => $search,
            'specialty' => $specialty,
            'orderBy' => $orderBy,
            'orderDirection' => $orderDirection,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalDoctors' => $totalDoctors
        ];
        
        // Renderizar a view
        view_with_layout('vendor/doctors/index', $data, 'vendor');
    }
    
    /**
     * Exibe o formulário para cadastrar um novo médico
     */
    public function register() {
        $vendorId = get_current_user_id();
        
        // Obter especialidades médicas comuns
        $specialties = $this->getCommonSpecialties();
        
        // Obter estados brasileiros
        $states = $this->getBrazilianStates();
        
        // Obter taxa de comissão padrão do vendedor
        $defaultCommissionRate = $this->getVendorDefaultCommissionRate($vendorId);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Cadastrar Novo Médico Parceiro',
            'specialties' => $specialties,
            'states' => $states,
            'defaultCommissionRate' => $defaultCommissionRate,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('vendor/doctors/register', $data, 'vendor');
    }
    
    /**
     * Processa o formulário de cadastro de médico
     */
    public function store() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('vendedor/medicos');
            return;
        }
        
        $vendorId = get_current_user_id();
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/medicos/cadastrar');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateDoctorForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/medicos/cadastrar');
            return;
        }
        
        // Verificar se CRM já existe
        $existingDoctor = $this->doctorModel->findByCrm($_POST['crm'], $_POST['crm_state']);
        
        // Se o médico já existe, verificar se já está associado
        if ($existingDoctor) {
            $existingRelation = $this->doctorVendorModel->getRelation($existingDoctor['id'], $vendorId);
            
            if ($existingRelation) {
                $_SESSION['errors'] = ['crm' => 'Este médico já está cadastrado e associado ao seu perfil.'];
                $_SESSION['form_data'] = $_POST;
                redirect('vendedor/medicos/cadastrar');
                return;
            }
            
            // Médico existe mas não está associado ao vendedor, criar relação
            try {
                $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
                
                // Criar relação
                $relationData = [
                    'doctor_id' => $existingDoctor['id'],
                    'vendor_id' => $vendorId,
                    'commission_rate' => $commissionRate,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $this->doctorVendorModel->create($relationData);
                
                // Mensagem de sucesso
                set_flash_message('success', 'Médico associado com sucesso!');
                redirect('vendedor/medicos');
                
            } catch (\Exception $e) {
                // Registrar erro
                log_message('Erro ao associar médico: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                $_SESSION['errors'] = ['general' => 'Erro ao associar médico: ' . $e->getMessage()];
                $_SESSION['form_data'] = $_POST;
                redirect('vendedor/medicos/cadastrar');
            }
            
            return;
        }
        
        // Médico não existe, criar novo
        try {
            // Criar médico
            $doctorData = [
                'user_id' => null, // Não criar conta de usuário pelo vendedor
                'name' => sanitize_string($_POST['name']),
                'crm' => sanitize_string($_POST['crm']),
                'crm_state' => sanitize_string($_POST['crm_state']),
                'specialty' => sanitize_string($_POST['specialty']),
                'phone' => sanitize_string($_POST['phone'] ?? ''),
                'email' => sanitize_string($_POST['email'] ?? ''),
                'status' => 'active',
                'created_by' => $vendorId
            ];
            
            $doctorId = $this->doctorModel->create($doctorData);
            
            // Criar relação médico-vendedor
            $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
            
            $relationData = [
                'doctor_id' => $doctorId,
                'vendor_id' => $vendorId,
                'commission_rate' => $commissionRate,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $this->doctorVendorModel->create($relationData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico cadastrado com sucesso!');
            redirect('vendedor/medicos');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao cadastrar médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao cadastrar médico: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/medicos/cadastrar');
        }
    }
    
    /**
     * Exibe detalhes de um médico
     * 
     * @param int $id ID do médico
     */
    public function details($id) {
        $vendorId = get_current_user_id();
        
        // Verificar se o médico está associado ao vendedor
        $relation = $this->doctorVendorModel->getRelation($id, $vendorId);
        
        if (!$relation) {
            set_flash_message('error', 'Médico não encontrado ou não associado ao seu perfil.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Obter estatísticas
        $stats = [
            'totalPrescriptions' => $this->doctorModel->countPrescriptionsByDoctor($id, $vendorId),
            'totalSales' => $this->doctorModel->countSalesByDoctor($id, $vendorId),
            'totalCommissions' => $this->doctorModel->getDoctorCommissionsTotal($id, $vendorId),
            'lastPrescription' => $this->doctorModel->getLastPrescriptionByDoctor($id, $vendorId)
        ];
        
        // Obter pedidos recentes
        $recentOrders = $this->orderModel->getRecentByDoctor($id, $vendorId, 5);
        
        // Obter comissões
        $commissions = $this->commissionModel->getDoctorCommissionsForVendor($id, $vendorId, 5);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes do Médico',
            'doctor' => $doctor,
            'relation' => $relation,
            'stats' => $stats,
            'recentOrders' => $recentOrders,
            'commissions' => $commissions
        ];
        
        // Renderizar a view
        view_with_layout('vendor/doctors/details', $data, 'vendor');
    }
    
    /**
     * Exibe o formulário para editar a comissão do médico
     * 
     * @param int $id ID do médico
     */
    public function editCommission($id) {
        $vendorId = get_current_user_id();
        
        // Verificar se o médico está associado ao vendedor
        $relation = $this->doctorVendorModel->getRelation($id, $vendorId);
        
        if (!$relation) {
            set_flash_message('error', 'Médico não encontrado ou não associado ao seu perfil.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Preparar dados para a view
        $data = [
            'title' => 'Editar Comissão do Médico',
            'doctor' => $doctor,
            'relation' => $relation,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('vendor/doctors/edit_commission', $data, 'vendor');
    }
    
    /**
     * Processa o formulário de edição de comissão
     * 
     * @param int $id ID do médico
     */
    public function updateCommission($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('vendedor/medicos');
            return;
        }
        
        $vendorId = get_current_user_id();
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/medicos/comissao/' . $id);
            return;
        }
        
        // Verificar se o médico está associado ao vendedor
        $relation = $this->doctorVendorModel->getRelation($id, $vendorId);
        
        if (!$relation) {
            set_flash_message('error', 'Médico não encontrado ou não associado ao seu perfil.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Validar taxa de comissão
        $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
        
        if ($commissionRate < 0 || $commissionRate > 100) {
            $_SESSION['errors'] = ['commission_rate' => 'A taxa de comissão deve estar entre 0 e 100%.'];
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/medicos/comissao/' . $id);
            return;
        }
        
        try {
            // Atualizar relação
            $this->doctorVendorModel->update($relation['id'], [
                'commission_rate' => $commissionRate,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Taxa de comissão atualizada com sucesso!');
            redirect('vendedor/medicos/detalhes/' . $id);
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar taxa de comissão: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao atualizar taxa de comissão: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/medicos/comissao/' . $id);
        }
    }
    
    /**
     * Exibe todas as comissões de um médico
     * 
     * @param int $id ID do médico
     */
    public function commissions($id) {
        $vendorId = get_current_user_id();
        
        // Verificar se o médico está associado ao vendedor
        $relation = $this->doctorVendorModel->getRelation($id, $vendorId);
        
        if (!$relation) {
            set_flash_message('error', 'Médico não encontrado ou não associado ao seu perfil.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Obter comissões
        $commissions = $this->commissionModel->getDoctorCommissionsForVendorWithFilters(
            $id,
            $vendorId,
            $status,
            $startDate,
            $endDate,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalCommissions = $this->commissionModel->countDoctorCommissionsForVendorWithFilters(
            $id,
            $vendorId,
            $status,
            $startDate,
            $endDate
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalCommissions / $limit);
        
        // Obter estatísticas de comissões
        $stats = [
            'totalCommissions' => $this->doctorModel->getDoctorCommissionsTotal($id, $vendorId),
            'pendingCommissions' => $this->doctorModel->getDoctorPendingCommissions($id, $vendorId),
            'paidCommissions' => $this->doctorModel->getDoctorPaidCommissions($id, $vendorId)
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Comissões do Médico',
            'doctor' => $doctor,
            'relation' => $relation,
            'commissions' => $commissions,
            'stats' => $stats,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCommissions' => $totalCommissions
        ];
        
        // Renderizar a view
        view_with_layout('vendor/doctors/commissions', $data, 'vendor');
    }
    
    /**
     * Desassocia um médico do vendedor
     * 
     * @param int $id ID do médico
     */
    public function remove($id) {
        $vendorId = get_current_user_id();
        
        // Verificar se o médico está associado ao vendedor
        $relation = $this->doctorVendorModel->getRelation($id, $vendorId);
        
        if (!$relation) {
            set_flash_message('error', 'Médico não encontrado ou não associado ao seu perfil.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/medicos');
            return;
        }
        
        // Verificar se há comissões associadas
        $hasCommissions = $this->commissionModel->hasDoctorCommissionsForVendor($id, $vendorId);
        
        if ($hasCommissions) {
            set_flash_message('error', 'Não é possível remover este médico porque existem comissões associadas a ele.');
            redirect('vendedor/medicos');
            return;
        }
        
        try {
            // Remover relação
            $this->doctorVendorModel->delete($relation['id']);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico removido com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao remover médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao remover médico: ' . $e->getMessage());
        }
        
        redirect('vendedor/medicos');
    }
    
    /**
     * Exporta lista de médicos em CSV
     */
    public function export() {
        $vendorId = get_current_user_id();
        
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $specialty = $_GET['specialty'] ?? '';
        
        // Obter todos os médicos com filtros
        $doctors = $this->doctorModel->getVendorDoctorsWithFiltersNoLimit(
            $vendorId,
            $search,
            $specialty
        );
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="meus_medicos_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'Nome', 'CRM', 'Estado', 'Especialidade', 'E-mail', 'Telefone',
            'Taxa de Comissão', 'Total de Prescrições', 'Comissões Totais',
            'Data de Associação'
        ]);
        
        // Dados
        foreach ($doctors as $doctor) {
            fputcsv($output, [
                $doctor['name'],
                $doctor['crm'],
                $doctor['crm_state'],
                $doctor['specialty'],
                $doctor['email'] ?? '',
                $doctor['phone'] ?? '',
                number_format($doctor['commission_rate'], 2, ',', '.') . '%',
                $doctor['prescription_count'] ?? 0,
                'R$ ' . number_format($doctor['total_commissions'] ?? 0, 2, ',', '.'),
                date('d/m/Y', strtotime($doctor['relation_created_at']))
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * Validação do formulário de médico
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateDoctorForm($data) {
        $errors = [];
        
        // Nome
        if (empty($data['name'])) {
            $errors['name'] = 'O nome é obrigatório.';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'O nome deve ter no mínimo 3 caracteres.';
        }
        
        // CRM
        if (empty($data['crm'])) {
            $errors['crm'] = 'O CRM é obrigatório.';
        } elseif (!preg_match('/^[0-9]+$/', $data['crm'])) {
            $errors['crm'] = 'O CRM deve conter apenas números.';
        }
        
        // Estado do CRM
        if (empty($data['crm_state'])) {
            $errors['crm_state'] = 'O estado do CRM é obrigatório.';
        } elseif (strlen($data['crm_state']) != 2) {
            $errors['crm_state'] = 'O estado do CRM deve ter 2 caracteres.';
        }
        
        // Especialidade
        if (empty($data['specialty'])) {
            $errors['specialty'] = 'A especialidade é obrigatória.';
        }
        
        // E-mail (se fornecido)
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'E-mail inválido.';
        }
        
        // Telefone (se fornecido)
        if (!empty($data['phone']) && strlen(preg_replace('/[^0-9]/', '', $data['phone'])) < 10) {
            $errors['phone'] = 'Telefone inválido. Deve conter DDD + número.';
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
     * Obtém a taxa de comissão padrão do vendedor
     * 
     * @param int $vendorId ID do vendedor
     * @return float Taxa de comissão padrão
     */
    private function getVendorDefaultCommissionRate($vendorId) {
        $vendorModel = new \App\Models\VendorModel();
        $vendor = $vendorModel->getById($vendorId);
        
        return $vendor ? $vendor['commission_rate'] : 10.0;
    }
    
    /**
     * Retorna lista de especialidades médicas comuns
     * 
     * @return array Lista de especialidades
     */
    private function getCommonSpecialties() {
        return [
            'Acupuntura',
            'Alergia e Imunologia',
            'Anestesiologia',
            'Angiologia',
            'Cancerologia',
            'Cardiologia',
            'Cirurgia Cardiovascular',
            'Cirurgia da Mão',
            'Cirurgia de Cabeça e Pescoço',
            'Cirurgia do Aparelho Digestivo',
            'Cirurgia Geral',
            'Cirurgia Pediátrica',
            'Cirurgia Plástica',
            'Cirurgia Torácica',
            'Cirurgia Vascular',
            'Clínica Médica',
            'Coloproctologia',
            'Dermatologia',
            'Endocrinologia e Metabologia',
            'Endoscopia',
            'Gastroenterologia',
            'Genética Médica',
            'Geriatria',
            'Ginecologia e Obstetrícia',
            'Hematologia e Hemoterapia',
            'Homeopatia',
            'Infectologia',
            'Mastologia',
            'Medicina de Família e Comunidade',
            'Medicina do Trabalho',
            'Medicina de Tráfego',
            'Medicina Esportiva',
            'Medicina Física e Reabilitação',
            'Medicina Intensiva',
            'Medicina Legal e Perícia Médica',
            'Medicina Nuclear',
            'Medicina Preventiva e Social',
            'Nefrologia',
            'Neurocirurgia',
            'Neurologia',
            'Nutrologia',
            'Oftalmologia',
            'Oncologia Clínica',
            'Ortopedia e Traumatologia',
            'Otorrinolaringologia',
            'Patologia',
            'Patologia Clínica/Medicina Laboratorial',
            'Pediatria',
            'Pneumologia',
            'Psiquiatria',
            'Radiologia e Diagnóstico por Imagem',
            'Radioterapia',
            'Reumatologia',
            'Urologia'
        ];
    }
    
    /**
     * Retorna lista de estados brasileiros
     * 
     * @return array Lista de estados com sigla e nome
     */
    private function getBrazilianStates() {
        return [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            '           RJ' => 'Rio de Janeiro',
           'RN' => 'Rio Grande do Norte',
           'RS' => 'Rio Grande do Sul',
           'RO' => 'Rondônia',
           'RR' => 'Roraima',
           'SC' => 'Santa Catarina',
           'SP' => 'São Paulo',
           'SE' => 'Sergipe',
           'TO' => 'Tocantins'
       ];
   }
}
       ];
   }
}