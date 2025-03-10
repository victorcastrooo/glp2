<?php
/**
* VendorController.php - Controlador para gerenciamento de vendedores no admin
* 
* Este controlador gerencia todas as operações relacionadas a vendedores parceiros,
* incluindo cadastro, edição, desativação, e visualização de desempenho e comissões.
*/

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\VendorModel;
use App\Models\DoctorModel;
use App\Models\DoctorVendorModel;
use App\Models\CommissionModel;
use App\Services\EmailService;

class VendorController {
   /**
    * @var UserModel Modelo de usuários
    */
   private $userModel;
   
   /**
    * @var VendorModel Modelo de vendedores
    */
   private $vendorModel;
   
   /**
    * @var DoctorModel Modelo de médicos
    */
   private $doctorModel;
   
   /**
    * @var DoctorVendorModel Modelo de relacionamento médico-vendedor
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
       $this->vendorModel = new VendorModel();
       $this->doctorModel = new DoctorModel();
       $this->doctorVendorModel = new DoctorVendorModel();
       $this->commissionModel = new CommissionModel();
       $this->emailService = new EmailService();
       
       // Verificar se o usuário é admin
       if (!is_admin()) {
           redirect('login');
       }
   }
   
   /**
    * Lista todos os vendedores
    */
   public function index() {
       // Parâmetros de paginação e filtros
       $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
       $limit = 15;
       $offset = ($page - 1) * $limit;
       
       $search = $_GET['search'] ?? '';
       $status = $_GET['status'] ?? 'all';
       
       // Obter vendedores com filtros aplicados
       $vendors = $this->vendorModel->getAllWithFilters(
           $search,
           $status,
           $limit,
           $offset
       );
       
       // Obter contagem total para paginação
       $totalVendors = $this->vendorModel->countAllWithFilters(
           $search,
           $status
       );
       
       // Calcular total de páginas
       $totalPages = ceil($totalVendors / $limit);
       
       // Estatísticas rápidas
       $stats = [
           'totalVendors' => $this->vendorModel->countAll(),
           'activeVendors' => $this->vendorModel->countByStatus('active'),
           'totalSales' => $this->vendorModel->getTotalSalesAmount(),
           'totalCommissions' => $this->vendorModel->getTotalCommissionsAmount()
       ];
       
       // Preparar dados para a view
       $data = [
           'title' => 'Gerenciar Vendedores',
           'vendors' => $vendors,
           'stats' => $stats,
           'search' => $search,
           'status' => $status,
           'currentPage' => $page,
           'totalPages' => $totalPages,
           'totalVendors' => $totalVendors
       ];
       
       // Renderizar a view
       view_with_layout('admin/vendors/index', $data, 'admin');
   }
   
   /**
    * Exibe o formulário para criar um novo vendedor
    */
   public function create() {
       // Preparar dados para a view
       $data = [
           'title' => 'Novo Vendedor',
           'errors' => get_flash_messages(true)['errors'] ?? [],
           'formData' => $_SESSION['form_data'] ?? []
       ];
       
       // Limpar dados temporários
       unset($_SESSION['form_data']);
       
       // Renderizar a view
       view_with_layout('admin/vendors/create', $data, 'admin');
   }
   
   /**
    * Processa o formulário de criação de vendedor
    */
   public function store() {
       // Verificar se é uma requisição POST
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           redirect('admin/vendedores');
           return;
       }
       
       // Validar token CSRF
       if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores/novo');
           return;
       }
       
       // Validar dados do formulário
       $errors = $this->validateVendorForm($_POST);
       
       if (!empty($errors)) {
           // Se houver erros, salvar em flash e redirecionar
           $_SESSION['errors'] = $errors;
           $_SESSION['form_data'] = $_POST;
           redirect('admin/vendedores/novo');
           return;
       }
       
       // Verificar se o e-mail já existe
       $existingUser = $this->userModel->findByEmail($_POST['email']);
       
       if ($existingUser) {
           $_SESSION['errors'] = ['email' => 'Este e-mail já está em uso.'];
           $_SESSION['form_data'] = $_POST;
           redirect('admin/vendedores/novo');
           return;
       }
       
       // Verificar se o documento já existe
       $existingVendor = $this->vendorModel->findByDocument($_POST['document']);
       
       if ($existingVendor) {
           $_SESSION['errors'] = ['document' => 'Este CPF/CNPJ já está em uso.'];
           $_SESSION['form_data'] = $_POST;
           redirect('admin/vendedores/novo');
           return;
       }
       
       // Gerar senha aleatória
       $password = $this->generateRandomPassword();
       $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
       
       // Iniciar transação
       $db = getDbConnection();
       $db->beginTransaction();
       
       try {
           // Criar usuário
           $userData = [
               'name' => sanitize_string($_POST['name']),
               'email' => sanitize_string($_POST['email']),
               'password' => $hashedPassword,
               'role' => 'vendor',
               'status' => $_POST['status'] ?? 'active',
               'created_at' => date('Y-m-d H:i:s')
           ];
           
           $userId = $this->userModel->create($userData);
           
           // Criar vendedor
           $vendorData = [
               'user_id' => $userId,
               'company_name' => sanitize_string($_POST['company_name'] ?? ''),
               'document' => sanitize_string($_POST['document']),
               'phone' => sanitize_string($_POST['phone']),
               'commission_rate' => floatval(str_replace(',', '.', $_POST['commission_rate'])),
               'bank_info' => $_POST['bank_info'] ?? null
           ];
           
           $this->vendorModel->create($vendorData);
           
           // Confirmar transação
           $db->commit();
           
           // Enviar e-mail com credenciais
           $emailData = [
               'name' => $_POST['name'],
               'email' => $_POST['email'],
               'password' => $password,
               'login_url' => BASE_URL . 'login'
           ];
           
           $this->emailService->sendVendorWelcomeEmail($emailData);
           
           // Mensagem de sucesso
           set_flash_message('success', 'Vendedor cadastrado com sucesso! As credenciais foram enviadas para o e-mail.');
           redirect('admin/vendedores');
           
       } catch (\Exception $e) {
           // Reverter transação em caso de erro
           $db->rollBack();
           
           // Registrar erro
           log_message('Erro ao cadastrar vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao cadastrar vendedor: ' . $e->getMessage());
           redirect('admin/vendedores/novo');
       }
   }
   
   /**
    * Exibe o formulário para editar um vendedor
    * 
    * @param int $id ID do vendedor
    */
   public function edit($id) {
       // Obter vendedor pelo ID
       $vendor = $this->vendorModel->getDetailsById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Preparar dados para a view
       $data = [
           'title' => 'Editar Vendedor',
           'vendor' => $vendor,
           'errors' => get_flash_messages(true)['errors'] ?? [],
           'formData' => $_SESSION['form_data'] ?? []
       ];
       
       // Limpar dados temporários
       unset($_SESSION['form_data']);
       
       // Renderizar a view
       view_with_layout('admin/vendors/edit', $data, 'admin');
   }
   
   /**
    * Processa o formulário de edição de vendedor
    * 
    * @param int $id ID do vendedor
    */
   public function update($id) {
       // Verificar se é uma requisição POST
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           redirect('admin/vendedores');
           return;
       }
       
       // Validar token CSRF
       if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores/editar/' . $id);
           return;
       }
       
       // Obter vendedor existente
       $vendor = $this->vendorModel->getDetailsById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Validar dados do formulário (ignorando validação de senha)
       $errors = $this->validateVendorForm($_POST, true, $vendor['user_id']);
       
       if (!empty($errors)) {
           // Se houver erros, salvar em flash e redirecionar
           $_SESSION['errors'] = $errors;
           $_SESSION['form_data'] = $_POST;
           redirect('admin/vendedores/editar/' . $id);
           return;
       }
       
       // Verificar documento para evitar duplicação
       if ($_POST['document'] !== $vendor['document']) {
           $existingVendor = $this->vendorModel->findByDocument($_POST['document']);
           
           if ($existingVendor && $existingVendor['id'] != $id) {
               $_SESSION['errors'] = ['document' => 'Este CPF/CNPJ já está em uso por outro vendedor.'];
               $_SESSION['form_data'] = $_POST;
               redirect('admin/vendedores/editar/' . $id);
               return;
           }
       }
       
       // Iniciar transação
       $db = getDbConnection();
       $db->beginTransaction();
       
       try {
           // Atualizar dados do usuário
           $userData = [
               'name' => sanitize_string($_POST['name']),
               'status' => $_POST['status'] ?? 'active',
               'updated_at' => date('Y-m-d H:i:s')
           ];
           
           // Atualizar e-mail apenas se foi alterado
           if ($_POST['email'] !== $vendor['email']) {
               // Verificar se o novo e-mail não existe
               $existingUser = $this->userModel->findByEmail($_POST['email']);
               
               if ($existingUser && $existingUser['id'] != $vendor['user_id']) {
                   throw new \Exception('Este e-mail já está em uso por outro usuário.');
               }
               
               $userData['email'] = sanitize_string($_POST['email']);
           }
           
           // Atualizar senha apenas se foi preenchida
           if (!empty($_POST['password'])) {
               $userData['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
           }
           
           $this->userModel->update($vendor['user_id'], $userData);
           
           // Atualizar dados do vendedor
           $vendorData = [
               'company_name' => sanitize_string($_POST['company_name'] ?? ''),
               'document' => sanitize_string($_POST['document']),
               'phone' => sanitize_string($_POST['phone']),
               'commission_rate' => floatval(str_replace(',', '.', $_POST['commission_rate'])),
               'bank_info' => $_POST['bank_info'] ?? null
           ];
           
           $this->vendorModel->update($id, $vendorData);
           
           // Confirmar transação
           $db->commit();
           
           // Mensagem de sucesso
           set_flash_message('success', 'Vendedor atualizado com sucesso!');
           redirect('admin/vendedores');
           
       } catch (\Exception $e) {
           // Reverter transação em caso de erro
           $db->rollBack();
           
           // Registrar erro
           log_message('Erro ao atualizar vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao atualizar vendedor: ' . $e->getMessage());
           redirect('admin/vendedores/editar/' . $id);
       }
   }
   
   /**
    * Exibe detalhes de um vendedor com histórico de vendas e comissões
    * 
    * @param int $id ID do vendedor
    */
   public function details($id) {
       // Obter vendedor pelo ID
       $vendor = $this->vendorModel->getDetailsById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Obter estatísticas de vendas
       $salesStats = $this->vendorModel->getSalesStats($id);
       
       // Obter histórico de vendas recentes
       $recentSales = $this->vendorModel->getRecentSales($id, 10);
       
       // Obter histórico de comissões
       $commissions = $this->commissionModel->getVendorCommissions($id, 10);
       
       // Obter médicos relacionados
       $relatedDoctors = $this->doctorVendorModel->getDoctorsByVendor($id);
       
       // Obter links de referência
       $referralLinks = $this->vendorModel->getReferralLinks($id);
       
       // Preparar dados para a view
       $data = [
           'title' => 'Detalhes do Vendedor',
           'vendor' => $vendor,
           'salesStats' => $salesStats,
           'recentSales' => $recentSales,
           'commissions' => $commissions,
           'relatedDoctors' => $relatedDoctors,
           'referralLinks' => $referralLinks
       ];
       
       // Renderizar a view
       view_with_layout('admin/vendors/details', $data, 'admin');
   }
   
   /**
    * Desativa um vendedor
    * 
    * @param int $id ID do vendedor
    */
   public function disable($id) {
       // Obter vendedor pelo ID
       $vendor = $this->vendorModel->getById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Validar token CSRF (enviado via GET para simplificar)
       if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores');
           return;
       }
       
       try {
           // Atualizar status do usuário
           $this->userModel->update($vendor['user_id'], [
               'status' => 'inactive',
               'updated_at' => date('Y-m-d H:i:s')
           ]);
           
           // Mensagem de sucesso
           set_flash_message('success', 'Vendedor desativado com sucesso!');
           
       } catch (\Exception $e) {
           // Registrar erro
           log_message('Erro ao desativar vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao desativar vendedor: ' . $e->getMessage());
       }
       
       redirect('admin/vendedores');
   }
   
   /**
    * Reativa um vendedor
    * 
    * @param int $id ID do vendedor
    */
   public function enable($id) {
       // Obter vendedor pelo ID
       $vendor = $this->vendorModel->getById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Validar token CSRF (enviado via GET para simplificar)
       if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores');
           return;
       }
       
       try {
           // Atualizar status do usuário
           $this->userModel->update($vendor['user_id'], [
               'status' => 'active',
               'updated_at' => date('Y-m-d H:i:s')
           ]);
           
           // Mensagem de sucesso
           set_flash_message('success', 'Vendedor reativado com sucesso!');
           
       } catch (\Exception $e) {
           // Registrar erro
           log_message('Erro ao reativar vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao reativar vendedor: ' . $e->getMessage());
       }
       
       redirect('admin/vendedores');
   }
   
   /**
    * Exibe a página de relacionamento entre vendedores e médicos
    * 
    * @param int $id ID do vendedor
    */
   public function doctorRelations($id) {
       // Obter vendedor pelo ID
       $vendor = $this->vendorModel->getDetailsById($id);
       
       if (!$vendor) {
           set_flash_message('error', 'Vendedor não encontrado.');
           redirect('admin/vendedores');
           return;
       }
       
       // Obter médicos relacionados
       $relatedDoctors = $this->doctorVendorModel->getDoctorsByVendor($id);
       
       // Obter médicos não relacionados para adicionar
       $availableDoctors = $this->doctorModel->getAvailableDoctorsForVendor($id);
       
       // Preparar dados para a view
       $data = [
           'title' => 'Médicos Relacionados - ' . $vendor['name'],
           'vendor' => $vendor,
           'relatedDoctors' => $relatedDoctors,
           'availableDoctors' => $availableDoctors,
           'errors' => get_flash_messages(true)['errors'] ?? []
       ];
       
       // Renderizar a view
       view_with_layout('admin/vendors/doctor_relations', $data, 'admin');
   }
   
   /**
    * Adiciona uma relação entre vendedor e médico
    */
   public function addDoctorRelation() {
       // Verificar se é uma requisição POST
       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
           redirect('admin/vendedores');
           return;
       }
       
       // Validar token CSRF
       if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores');
           return;
       }
       
       // Obter dados do formulário
       $vendorId = (int) $_POST['vendor_id'];
       $doctorId = (int) $_POST['doctor_id'];
       $commissionRate = floatval(str_replace(',', '.', $_POST['commission_rate'] ?? '0'));
       
       // Validar dados
       if ($vendorId <= 0 || $doctorId <= 0) {
           set_flash_message('error', 'Dados inválidos.');
           redirect('admin/vendedores');
           return;
       }
       
       // Verificar se relação já existe
       $existingRelation = $this->doctorVendorModel->getRelation($doctorId, $vendorId);
       
       if ($existingRelation) {
           set_flash_message('error', 'Este médico já está relacionado a este vendedor.');
           redirect('admin/vendedores/medicos/' . $vendorId);
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
           set_flash_message('success', 'Médico adicionado ao vendedor com sucesso!');
           
       } catch (\Exception $e) {
           // Registrar erro
           log_message('Erro ao adicionar relação médico-vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao adicionar médico: ' . $e->getMessage());
       }
       
       redirect('admin/vendedores/medicos/' . $vendorId);
   }
   
   /**
    * Remove uma relação entre vendedor e médico
    * 
    * @param int $relationId ID da relação
    * @param int $vendorId ID do vendedor
    */
   public function removeDoctorRelation($relationId, $vendorId) {
       // Validar token CSRF (enviado via GET para simplificar)
       if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
           set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
           redirect('admin/vendedores/medicos/' . $vendorId);
           return;
       }
       
       // Verificar se relação existe e pertence ao vendedor
       $relation = $this->doctorVendorModel->getById($relationId);
       
       if (!$relation || $relation['vendor_id'] != $vendorId) {
           set_flash_message('error', 'Relação não encontrada ou inválida.');
           redirect('admin/vendedores/medicos/' . $vendorId);
           return;
       }
       
       try {
           // Remover relação
           $this->doctorVendorModel->delete($relationId);
           
           // Mensagem de sucesso
           set_flash_message('success', 'Médico removido do vendedor com sucesso!');
           
       } catch (\Exception $e) {
           // Registrar erro
           log_message('Erro ao remover relação médico-vendedor: ' . $e->getMessage(), 'error');
           
           // Mensagem de erro
           set_flash_message('error', 'Erro ao remover médico: ' . $e->getMessage());
       }
       
       redirect('admin/vendedores/medicos/' . $vendorId);
   }
   
   /**
    * Exporta lista de vendedores em CSV
    */
   public function export() {
       // Obter filtros
       $search = $_GET['search'] ?? '';
       $status = $_GET['status'] ?? 'all';
       
       // Obter todos os vendedores com filtros
       $vendors = $this->vendorModel->getAllWithFiltersNoLimit($search, $status);
       
       // Configurar cabeçalhos HTTP
       header('Content-Type: text/csv; charset=utf-8');
       header('Content-Disposition: attachment; filename="vendedores_' . date('Y-m-d') . '.csv"');
       
       // Criar handle de arquivo para output
       $output = fopen('php://output', 'w');
       
       // Adicionar BOM para UTF-8
       fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
       
       // Cabeçalhos
       fputcsv($output, [
           'ID', 'Nome', 'E-mail', 'Empresa', 'Documento', 'Telefone',
           'Taxa de Comissão', 'Status', 'Vendas Totais', 'Comissões Totais',
           'Data de Cadastro'
       ]);
       
       // Dados
       foreach ($vendors as $vendor) {
           fputcsv($output, [
               $vendor['id'],
               $vendor['name'],
               $vendor['email'],
               $vendor['company_name'] ?? '',
               $vendor['document'],
               $vendor['phone'],
               number_format($vendor['commission_rate'], 2, ',', '.') . '%',
               $vendor['status'] == 'active' ? 'Ativo' : 'Inativo',
               'R$ ' . number_format($vendor['total_sales'] ?? 0, 2, ',', '.'),
               'R$ ' . number_format($vendor['total_commissions'] ?? 0, 2, ',', '.'),
               date('d/m/Y', strtotime($vendor['created_at']))
           ]);
       }
       
       // Fechar handle de arquivo
       fclose($output);
       exit;
   }
   
   /**
    * Validação do formulário de vendedor
    * 
    * @param array $data Dados do formulário
    * @param bool $isUpdate Flag indicando se é atualização (ignora validação de senha)
    * @param int|null $userId ID do usuário para verificação de email único
    * @return array Erros encontrados
    */
   private function validateVendorForm($data, $isUpdate = false, $userId = null) {
       $errors = [];
       
       // Nome
       if (empty($data['name'])) {
           $errors['name'] = 'O nome é obrigatório.';
       } elseif (strlen($data['name']) < 3) {
           $errors['name'] = 'O nome deve ter no mínimo 3 caracteres.';
       }
       
       // Email
       if (empty($data['email'])) {
           $errors['email'] = 'O e-mail é obrigatório.';
       } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
           $errors['email'] = 'E-mail inválido.';
       } else if (!$isUpdate) {
           // Verificar se e-mail já existe (apenas na criação)
           $existingUser = $this->userModel->findByEmail($data['email']);
           if ($existingUser) {
               $errors['email'] = 'Este e-mail já está em uso.';
           }
       } else if ($userId) {
           // Verificar se e-mail já existe e não pertence a este usuário
           $existingUser = $this->userModel->findByEmail($data['email']);
           if ($existingUser && $existingUser['id'] != $userId) {
               $errors['email'] = 'Este e-mail já está em uso por outro usuário.';
           }
       }
       
       // Senha (apenas para criação)
       if (!$isUpdate && empty($data['password'])) {
           $errors['password'] = 'A senha é obrigatória.';
       } elseif (!$isUpdate && strlen($data['password']) < 8) {
           $errors['password'] = 'A senha deve ter no mínimo 8 caracteres.';
       }
       
       // Documento (CPF/CNPJ)
       if (empty($data['document'])) {
           $errors['document'] = 'O CPF/CNPJ é obrigatório.';
       } else {
           $document = preg_replace('/[^0-9]/', '', $data['document']);
           
           if (strlen($document) == 11) {
               // CPF
               if (!validateCPF($document)) {
                   $errors['document'] = 'CPF inválido.';
               }
           } elseif (strlen($document) == 14) {
               // CNPJ
               if (!validateCNPJ($document)) {
                   $errors['document'] = 'CNPJ inválido.';
               }
           } else {
               $errors['document'] = 'Documento inválido. Deve ser CPF ou CNPJ.';
           }
       }
       
       // Telefone
       if (empty($data['phone'])) {
           $errors['phone'] = 'O telefone é obrigatório.';
       } elseif (strlen(preg_replace('/[^0-9]/', '', $data['phone'])) < 10) {
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
}