<?php
/**
 * DoctorController.php - Controlador para gerenciamento de médicos no admin
 * 
 * Este controlador gerencia todas as operações relacionadas a médicos parceiros,
 * incluindo cadastro, edição, associação com vendedores, e acompanhamento de comissões.
 */

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\DoctorModel;
use App\Models\VendorModel;
use App\Models\DoctorVendorModel;
use App\Models\CommissionModel;
use App\Services\EmailService;

class DoctorController {
    /**
     * @var UserModel Modelo de usuários
     */
    private $userModel;
    
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
     * @var EmailService Serviço de e-mail
     */
    private $emailService;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos e serviços
        $this->userModel = new UserModel();
        $this->doctorModel = new DoctorModel();
        $this->vendorModel = new VendorModel();
        $this->doctorVendorModel = new DoctorVendorModel();
        $this->commissionModel = new CommissionModel();
        $this->emailService = new EmailService();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todos os médicos
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Obter médicos com filtros aplicados
        $doctors = $this->doctorModel->getAllWithFilters(
            $search,
            $status,
            $vendorId,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalDoctors = $this->doctorModel->countAllWithFilters(
            $search,
            $status,
            $vendorId
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalDoctors / $limit);
        
        // Obter vendedores para filtro
        $vendors = $this->vendorModel->getAllBasic();
        
        // Estatísticas rápidas
        $stats = [
            'totalDoctors' => $this->doctorModel->countAll(),
            'activeDoctors' => $this->doctorModel->countByStatus('active'),
            'totalPrescriptions' => $this->doctorModel->countTotalPrescriptions(),
            'totalCommissions' => $this->doctorModel->getTotalCommissions()
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Médicos',
            'doctors' => $doctors,
            'vendors' => $vendors,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'vendorId' => $vendorId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalDoctors' => $totalDoctors
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctors/index', $data, 'admin');
    }
    
    /**
     * Exibe o formulário para criar um novo médico
     */
    public function create() {
        // Obter especialidades médicas comuns
        $specialties = $this->getCommonSpecialties();
        
        // Obter estados brasileiros
        $states = $this->getBrazilianStates();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Novo Médico',
            'specialties' => $specialties,
            'states' => $states,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('admin/doctors/create', $data, 'admin');
    }
    
    /**
     * Processa o formulário de criação de médico
     */
    public function store() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos/novo');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateDoctorForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/medicos/novo');
            return;
        }
        
        // Verificar se CRM já existe
        $existingDoctor = $this->doctorModel->findByCrm($_POST['crm'], $_POST['crm_state']);
        
        if ($existingDoctor) {
            $_SESSION['errors'] = ['crm' => 'Este CRM já está cadastrado para este estado.'];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/medicos/novo');
            return;
        }
        
        // Criar conta de usuário se opção selecionada
        $userId = null;
        
        if (isset($_POST['create_account']) && $_POST['create_account'] == 1) {
            // Verificar se e-mail foi fornecido
            if (empty($_POST['email'])) {
                $_SESSION['errors'] = ['email' => 'O e-mail é obrigatório para criar uma conta.'];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/novo');
                return;
            }
            
            // Verificar se e-mail já existe
            $existingUser = $this->userModel->findByEmail($_POST['email']);
            
            if ($existingUser) {
                $_SESSION['errors'] = ['email' => 'Este e-mail já está em uso.'];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/novo');
                return;
            }
            
            // Gerar senha aleatória
            $password = $this->generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Criar usuário
            try {
                $userData = [
                    'name' => sanitize_string($_POST['name']),
                    'email' => sanitize_string($_POST['email']),
                    'password' => $hashedPassword,
                    'role' => 'doctor',
                    'status' => $_POST['status'] ?? 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $userId = $this->userModel->create($userData);
                
                // Enviar e-mail com credenciais
                $emailData = [
                    'name' => $_POST['name'],
                    'email' => $_POST['email'],
                    'password' => $password,
                    'login_url' => BASE_URL . 'login'
                ];
                
                $this->emailService->sendDoctorWelcomeEmail($emailData);
                
            } catch (\Exception $e) {
                // Registrar erro
                log_message('Erro ao criar usuário para médico: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                $_SESSION['errors'] = ['general' => 'Erro ao criar conta de usuário: ' . $e->getMessage()];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/novo');
                return;
            }
        }
        
        // Criar médico
        try {
            $doctorData = [
                'user_id' => $userId,
                'name' => sanitize_string($_POST['name']),
                'crm' => sanitize_string($_POST['crm']),
                'crm_state' => sanitize_string($_POST['crm_state']),
                'specialty' => sanitize_string($_POST['specialty']),
                'phone' => sanitize_string($_POST['phone'] ?? ''),
                'email' => sanitize_string($_POST['email'] ?? ''),
                'status' => $_POST['status'] ?? 'active',
                'created_by' => get_current_user_id()
            ];
            
            $doctorId = $this->doctorModel->create($doctorData);
            
            // Associar médico a vendedores se selecionado
            if (!empty($_POST['vendors']) && is_array($_POST['vendors'])) {
                foreach ($_POST['vendors'] as $vendorId) {
                    // Obter taxa de comissão padrão do vendedor
                    $vendor = $this->vendorModel->getById($vendorId);
                    $commissionRate = $vendor ? $vendor['commission_rate'] : 5.00;
                    
                    $relationData = [
                        'doctor_id' => $doctorId,
                        'vendor_id' => (int) $vendorId,
                        'commission_rate' => $commissionRate,
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $this->doctorVendorModel->create($relationData);
                }
            }
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico cadastrado com sucesso!');
            redirect('admin/medicos');
            
        } catch (\Exception $e) {
            // Se já criou o usuário, mas falhou ao criar o médico, remover o usuário
            if ($userId) {
                $this->userModel->delete($userId);
            }
            
            // Registrar erro
            log_message('Erro ao cadastrar médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao cadastrar médico: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/medicos/novo');
        }
    }
    
    /**
     * Exibe o formulário para editar um médico
     * 
     * @param int $id ID do médico
     */
    public function edit($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Obter especialidades médicas comuns
        $specialties = $this->getCommonSpecialties();
        
        // Obter estados brasileiros
        $states = $this->getBrazilianStates();
        
        // Obter vendedores associados
        $relatedVendors = $this->doctorVendorModel->getVendorsByDoctor($id);
        
        // Obter dados do usuário se existir
        $user = null;
        if ($doctor['user_id']) {
            $user = $this->userModel->getById($doctor['user_id']);
        }
        
        // Preparar dados para a view
        $data = [
            'title' => 'Editar Médico',
            'doctor' => $doctor,
            'user' => $user,
            'relatedVendors' => $relatedVendors,
            'specialties' => $specialties,
            'states' => $states,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? []
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('admin/doctors/edit', $data, 'admin');
    }
    
    /**
     * Processa o formulário de edição de médico
     * 
     * @param int $id ID do médico
     */
    public function update($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos/editar/' . $id);
            return;
        }
        
        // Obter médico existente
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateDoctorForm($_POST, true, $id);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/medicos/editar/' . $id);
            return;
        }
        
        // Verificar CRM para evitar duplicação
        if ($doctor['crm'] != $_POST['crm'] || $doctor['crm_state'] != $_POST['crm_state']) {
            $existingDoctor = $this->doctorModel->findByCrm($_POST['crm'], $_POST['crm_state']);
            
            if ($existingDoctor && $existingDoctor['id'] != $id) {
                $_SESSION['errors'] = ['crm' => 'Este CRM já está cadastrado para este estado.'];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/editar/' . $id);
                return;
            }
        }
        
        // Atualizar conta de usuário se existir
        if ($doctor['user_id']) {
            // Atualizar dados do usuário
            try {
                $userData = [
                    'name' => sanitize_string($_POST['name']),
                    'status' => $_POST['status'] ?? 'active',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                // Atualizar e-mail apenas se for alterado
                if (!empty($_POST['email']) && $doctor['email'] != $_POST['email']) {
                    // Verificar se o e-mail está em uso
                    $existingUser = $this->userModel->findByEmail($_POST['email']);
                    
                    if ($existingUser && $existingUser['id'] != $doctor['user_id']) {
                        $_SESSION['errors'] = ['email' => 'Este e-mail já está em uso.'];
                        $_SESSION['form_data'] = $_POST;
                        redirect('admin/medicos/editar/' . $id);
                        return;
                    }
                    
                    $userData['email'] = sanitize_string($_POST['email']);
                }
                
                // Atualizar senha se fornecida
                if (!empty($_POST['password'])) {
                    $userData['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                
                $this->userModel->update($doctor['user_id'], $userData);
                
            } catch (\Exception $e) {
                // Registrar erro
                log_message('Erro ao atualizar usuário do médico: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                $_SESSION['errors'] = ['general' => 'Erro ao atualizar conta de usuário: ' . $e->getMessage()];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/editar/' . $id);
                return;
            }
        }
        // Criar conta de usuário se solicitado
        else if (isset($_POST['create_account']) && $_POST['create_account'] == 1) {
            // Verificar se e-mail foi fornecido
            if (empty($_POST['email'])) {
                $_SESSION['errors'] = ['email' => 'O e-mail é obrigatório para criar uma conta.'];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/editar/' . $id);
                return;
            }
            
            // Verificar se e-mail já existe
            $existingUser = $this->userModel->findByEmail($_POST['email']);
            
            if ($existingUser) {
                $_SESSION['errors'] = ['email' => 'Este e-mail já está em uso.'];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/editar/' . $id);
                return;
            }
            
            // Gerar senha aleatória
            $password = $this->generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Criar usuário
            try {
                $userData = [
                    'name' => sanitize_string($_POST['name']),
                    'email' => sanitize_string($_POST['email']),
                    'password' => $hashedPassword,
                    'role' => 'doctor',
                    'status' => $_POST['status'] ?? 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $userId = $this->userModel->create($userData);
                
                // Atualizar médico com ID do usuário
                $this->doctorModel->update($id, ['user_id' => $userId]);
                
                // Enviar e-mail com credenciais
                $emailData = [
                    'name' => $_POST['name'],
                    'email' => $_POST['email'],
                    'password' => $password,
                    'login_url' => BASE_URL . 'login'
                ];
                
                $this->emailService->sendDoctorWelcomeEmail($emailData);
                
            } catch (\Exception $e) {
                // Registrar erro
                log_message('Erro ao criar usuário para médico: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                $_SESSION['errors'] = ['general' => 'Erro ao criar conta de usuário: ' . $e->getMessage()];
                $_SESSION['form_data'] = $_POST;
                redirect('admin/medicos/editar/' . $id);
                return;
            }
        }
        
        // Atualizar médico
        try {
            $doctorData = [
                'name' => sanitize_string($_POST['name']),
                'crm' => sanitize_string($_POST['crm']),
                'crm_state' => sanitize_string($_POST['crm_state']),
                'specialty' => sanitize_string($_POST['specialty']),
                'phone' => sanitize_string($_POST['phone'] ?? ''),
                'email' => sanitize_string($_POST['email'] ?? ''),
                'status' => $_POST['status'] ?? 'active'
            ];
            
            $this->doctorModel->update($id, $doctorData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico atualizado com sucesso!');
            redirect('admin/medicos');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao atualizar médico: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('admin/medicos/editar/' . $id);
        }
    }
    
    /**
     * Exibe detalhes de um médico
     * 
     * @param int $id ID do médico
     */
    public function details($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Obter vendedores associados
        $relatedVendors = $this->doctorVendorModel->getVendorsByDoctor($id);
        
        // Obter estatísticas do médico
        $stats = [
            'totalPrescriptions' => $this->doctorModel->countPrescriptionsByDoctor($id),
            'totalSales' => $this->doctorModel->countSalesByDoctor($id),
            'totalCommissions' => $this->doctorModel->getTotalCommissionsByDoctor($id),
            'lastPrescription' => $this->doctorModel->getLastPrescriptionByDoctor($id)
        ];
        
        // Obter histórico de comissões
        $commissions = $this->commissionModel->getDoctorCommissions($id, 10);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes do Médico',
            'doctor' => $doctor,
            'relatedVendors' => $relatedVendors,
            'stats' => $stats,
            'commissions' => $commissions
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctors/details', $data, 'admin');
    }
    
    /**
     * Ativa um médico
     * 
     * @param int $id ID do médico
     */
    public function enable($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos');
            return;
        }
        
        try {
            // Atualizar status do médico
            $this->doctorModel->update($id, [
                'status' => 'active'
            ]);
            
            // Atualizar status do usuário se existir
            if ($doctor['user_id']) {
                $this->userModel->update($doctor['user_id'], [
                    'status' => 'active',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico ativado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao ativar médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao ativar médico: ' . $e->getMessage());
        }
        
        redirect('admin/medicos');
    }
    
    /**
     * Desativa um médico
     * 
     * @param int $id ID do médico
     */
    public function disable($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos');
            return;
        }
        
        try {
            // Atualizar status do médico
            $this->doctorModel->update($id, [
                'status' => 'inactive'
            ]);
            
            // Atualizar status do usuário se existir
            if ($doctor['user_id']) {
                $this->userModel->update($doctor['user_id'], [
                    'status' => 'inactive',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            // Mensagem de sucesso
            set_flash_message('success', 'Médico desativado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao desativar médico: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao desativar médico: ' . $e->getMessage());
        }
        
        redirect('admin/medicos');
    }
    
    /**
     * Exibe a página de gerenciamento de vendedores associados
     * 
     * @param int $id ID do médico
     */
    public function vendors($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Obter vendedores associados
        $relatedVendors = $this->doctorVendorModel->getVendorsByDoctor($id);
        
        // Obter vendedores disponíveis para adicionar
        $availableVendors = $this->vendorModel->getAvailableVendorsForDoctor($id);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Vendedores Associados ao Médico',
            'doctor' => $doctor,
            'relatedVendors' => $relatedVendors,
            'availableVendors' => $availableVendors,
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctors/vendors', $data, 'admin');
    }
    
    /**
     * Adiciona um vendedor à associação com médico
     */
    public function addVendor() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos');
            return;
        }
        
        // Obter dados do formulário
        $doctorId = (int) $_POST['doctor_id'];
        $vendorId = (int) $_POST['vendor_id'];
        $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
        
        // Validar dados
        if ($doctorId <= 0 || $vendorId <= 0) {
            set_flash_message('error', 'Dados inválidos.');
            redirect('admin/medicos');
            return;
        }
        
        // Verificar se relação já existe
        $existingRelation = $this->doctorVendorModel->getRelation($doctorId, $vendorId);
        
        if ($existingRelation) {
            set_flash_message('error', 'Este vendedor já está associado a este médico.');
            redirect('admin/medicos/vendedores/' . $doctorId);
            return;
        }
        
        try {
            // Criar relação
            $relationData = [
                'doctor_id' => $doctorId,
                'vendor_id' => $vendorId,
                'commission_rate' => $commissionRate,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $this->doctorVendorModel->create($relationData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Vendedor adicionado ao médico com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao adicionar relação médico-vendedor: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao adicionar vendedor: ' . $e->getMessage());
        }
        
        redirect('admin/medicos/vendedores/' . $doctorId);
    }
    
    /**
     * Remove um vendedor da associação com médico
     * 
     * @param int $relationId ID da relação
     * @param int $doctorId ID do médico
     */
    public function removeVendor($relationId, $doctorId) {
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos/vendedores/' . $doctorId);
            return;
        }
        
        // Verificar se relação existe e pertence ao médico
        $relation = $this->doctorVendorModel->getById($relationId);
        
        if (!$relation || $relation['doctor_id'] != $doctorId) {
            set_flash_message('error', 'Relação não encontrada ou inválida.');
            redirect('admin/medicos/vendedores/' . $doctorId);
            return;
        }
        
        try {
            // Remover relação
            $this->doctorVendorModel->delete($relationId);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Vendedor removido do médico com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao remover relação médico-vendedor: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao remover vendedor: ' . $e->getMessage());
        }
        
        redirect('admin/medicos/vendedores/' . $doctorId);
    }
    
    /**
     * Atualiza a taxa de comissão para a relação médico-vendedor
     */
    public function updateCommissionRate() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/medicos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/medicos');
            return;
        }
        
        // Obter dados do formulário
        $relationId = (int) $_POST['relation_id'];
        $doctorId = (int) $_POST['doctor_id'];
        $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
        
        // Validar dados
        if ($relationId <= 0 || $doctorId <= 0 || $commissionRate < 0 || $commissionRate > 100) {
            set_flash_message('error', 'Dados inválidos.');
            redirect('admin/medicos/vendedores/' . $doctorId);
            return;
        }
        
        // Verificar se relação existe
        $relation = $this->doctorVendorModel->getById($relationId);
        
        if (!$relation || $relation['doctor_id'] != $doctorId) {
            set_flash_message('error', 'Relação não encontrada ou inválida.');
            redirect('admin/medicos/vendedores/' . $doctorId);
            return;
        }
        
        try {
            // Atualizar taxa de comissão
            $this->doctorVendorModel->update($relationId, [
                'commission_rate' => $commissionRate,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Taxa de comissão atualizada com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar taxa de comissão: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao atualizar taxa de comissão: ' . $e->getMessage());
        }
        
        redirect('admin/medicos/vendedores/' . $doctorId);
    }
    
    /**
     * Exibe o histórico de comissões do médico
     * 
     * @param int $id ID do médico
     */
    public function commissions($id) {
        // Obter médico pelo ID
        $doctor = $this->doctorModel->getById($id);
        
        if (!$doctor) {
            set_flash_message('error', 'Médico não encontrado.');
            redirect('admin/medicos');
            return;
        }
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Obter comissões com filtros
        $commissions = $this->commissionModel->getDoctorCommissionsWithFilters(
            $id,
            $status,
            $startDate,
            $endDate,
            $vendorId,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalCommissions = $this->commissionModel->countDoctorCommissionsWithFilters(
            $id,
            $status,
            $startDate,
            $endDate,
            $vendorId
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalCommissions / $limit);
        
        // Obter vendedores relacionados para filtro
        $relatedVendors = $this->doctorVendorModel->getVendorsByDoctor($id);
        
        // Estatísticas de comissões
        $stats = [
            'totalCommissions' => $this->doctorModel->getTotalCommissionsByDoctor($id),
            'pendingCommissions' => $this->doctorModel->getPendingCommissionsByDoctor($id),
            'paidCommissions' => $this->doctorModel->getPaidCommissionsByDoctor($id)
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Comissões do Médico',
            'doctor' => $doctor,
            'commissions' => $commissions,
            'relatedVendors' => $relatedVendors,
            'stats' => $stats,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'vendorId' => $vendorId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCommissions' => $totalCommissions
        ];
        
        // Renderizar a view
        view_with_layout('admin/doctors/commissions', $data, 'admin');
    }
    
    /**
     * Exporta lista de médicos em CSV
     */
    public function export() {
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
        
        // Obter todos os médicos com filtros
        $doctors = $this->doctorModel->getAllWithFiltersNoLimit($search, $status, $vendorId);
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="medicos_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'ID', 'Nome', 'CRM', 'Estado', 'Especialidade', 'E-mail', 'Telefone',
            'Vendedores Associados', 'Total de Prescrições', 'Comissões Totais',
            'Status', 'Data de Cadastro'
        ]);
        
        // Dados
        foreach ($doctors as $doctor) {
            // Formatar status
            $statusTranslated = '';
            switch ($doctor['status']) {
                case 'active': $statusTranslated = 'Ativo'; break;
                case 'inactive': $statusTranslated = 'Inativo'; break;
                case 'pending': $statusTranslated = 'Pendente'; break;
                default: $statusTranslated = $doctor['status'];
            }
            
            fputcsv($output, [
                $doctor['id'],
                $doctor['name'],
                $doctor['crm'],
                $doctor['crm_state'],
                $doctor['specialty'],
                $doctor['email'] ?? '',
                $doctor['phone'] ?? '',
                $doctor['vendor_count'] ?? 0,
                $doctor['prescription_count'] ?? 0,
                'R$ ' . number_format($doctor['total_commissions'] ?? 0, 2, ',', '.'),
                $statusTranslated,
                date('d/m/Y', strtotime($doctor['created_at']))
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
     * @param bool $isUpdate Flag indicando se é atualização
     * @param int|null $doctorId ID do médico para verificação de CRM único
     * @return array Erros encontrados
     */
    private function validateDoctorForm($data, $isUpdate = false, $doctorId = null) {
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
        
        // Validar CRM único (apenas para criação ou se CRM foi alterado)
        if (!$isUpdate || ($doctorId && isset($data['crm']) && isset($data['crm_state']))) {
            $existingDoctor = $this->doctorModel->findByCrm($data['crm'], $data['crm_state']);
            
            if ($existingDoctor && (!$doctorId || $existingDoctor['id'] != $doctorId)) {
                $errors['crm'] = 'Este CRM já está cadastrado para este estado.';
            }
        }
        
        return $errors;
    }
    
    /**
     * Gera uma senha aleatória segura
     * 
     * @param int $length Comprimento da senha
     * @return string Senha gerada
     */
    private function generateRandomPassword($length = 10) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
        $count = mb_strlen($chars);
        
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $count - 1)];
        }
        
        return $password;
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
            'RJ' => 'Rio de Janeiro',
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