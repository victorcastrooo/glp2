<?php
/**
 * LinkController.php - Controlador para gerenciamento de links de referência pelo vendedor
 * 
 * Este controlador permite que os vendedores criem, gerenciem e visualizem
 * estatísticas de seus links de referência para divulgação dos produtos.
 */

namespace App\Controllers\Vendor;

use App\Models\ReferralLinkModel;
use App\Models\ProductModel;
use App\Models\OrderModel;
use App\Models\VendorModel;

class LinkController {
    /**
     * @var ReferralLinkModel Modelo de links de referência
     */
    private $referralLinkModel;
    
    /**
     * @var ProductModel Modelo de produtos
     */
    private $productModel;
    
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * @var VendorModel Modelo de vendedores
     */
    private $vendorModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->referralLinkModel = new ReferralLinkModel();
        $this->productModel = new ProductModel();
        $this->orderModel = new OrderModel();
        $this->vendorModel = new VendorModel();
        
        // Verificar se o usuário é vendedor
        if (!is_vendor()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todos os links de referência do vendedor
     */
    public function index() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $orderBy = $_GET['order_by'] ?? 'created_at';
        $orderDirection = $_GET['order_direction'] ?? 'desc';
        
        // Obter links com filtros aplicados
        $links = $this->referralLinkModel->getVendorLinksWithFilters(
            $vendorId,
            $search,
            $productId,
            $orderBy,
            $orderDirection,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalLinks = $this->referralLinkModel->countVendorLinksWithFilters(
            $vendorId,
            $search,
            $productId
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalLinks / $limit);
        
        // Obter produtos para filtro
        $products = $this->productModel->getAllActive();
        
        // Obter estatísticas de links
        $linkStats = $this->referralLinkModel->getStatsByVendor($vendorId);
        
        // Obter top links por conversão
        $topLinks = $this->referralLinkModel->getTopLinksByVendor($vendorId, 5);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Meus Links de Referência',
            'links' => $links,
            'products' => $products,
            'linkStats' => $linkStats,
            'topLinks' => $topLinks,
            'search' => $search,
            'productId' => $productId,
            'orderBy' => $orderBy,
            'orderDirection' => $orderDirection,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLinks' => $totalLinks,
            'baseUrl' => BASE_URL . 'ref/'
        ];
        
        // Renderizar a view
        view_with_layout('vendor/links/index', $data, 'vendor');
    }
    
    /**
     * Exibe o formulário para criar um novo link
     */
    public function generate() {
        $vendorId = get_current_user_id();
        
        // Obter produtos ativos
        $products = $this->productModel->getAllActive();
        
        // Obter links existentes
        $existingLinks = $this->referralLinkModel->getVendorLinks($vendorId);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerar Novo Link',
            'products' => $products,
            'existingLinks' => $existingLinks,
            'errors' => get_flash_messages(true)['errors'] ?? [],
            'formData' => $_SESSION['form_data'] ?? [],
            'baseUrl' => BASE_URL . 'ref/'
        ];
        
        // Limpar dados temporários
        unset($_SESSION['form_data']);
        
        // Renderizar a view
        view_with_layout('vendor/links/generate', $data, 'vendor');
    }
    
    /**
     * Processa o formulário de criação de link
     */
    public function store() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('vendedor/links');
            return;
        }
        
        $vendorId = get_current_user_id();
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/links/gerar');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateLinkForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/links/gerar');
            return;
        }
        
        // Obter dados do formulário
        $linkName = sanitize_string($_POST['name'] ?? '');
        $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        $customCode = sanitize_string($_POST['custom_code'] ?? '');
        
        try {
            // Verificar limite de links (máximo de 30 links por vendedor)
            $linkCount = $this->referralLinkModel->countVendorLinks($vendorId);
            
            if ($linkCount >= 30) {
                set_flash_message('error', 'Você atingiu o limite máximo de 30 links. Exclua alguns links antigos para criar novos.');
                redirect('vendedor/links/gerar');
                return;
            }
            
            // Gerar código único se não for personalizado
            $code = !empty($customCode) ? $customCode : $this->generateUniqueCode();
            
            // Verificar se o código já existe
            if ($this->referralLinkModel->checkCodeExists($code)) {
                set_flash_message('error', 'Este código já está em uso. Por favor, escolha outro código personalizado.');
                $_SESSION['form_data'] = $_POST;
                redirect('vendedor/links/gerar');
                return;
            }
            
            // Criar link
            $linkData = [
                'vendor_id' => $vendorId,
                'product_id' => $productId,
                'code' => $code,
                'url' => BASE_URL . 'ref/' . $code . ($productId ? '/produto/' . $productId : ''),
                'name' => $linkName,
                'clicks' => 0,
                'conversions' => 0,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $linkId = $this->referralLinkModel->create($linkData);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Link de referência criado com sucesso!');
            redirect('vendedor/links');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao criar link de referência: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            $_SESSION['errors'] = ['general' => 'Erro ao criar link: ' . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            redirect('vendedor/links/gerar');
        }
    }
    
    /**
     * Exibe estatísticas detalhadas de um link
     * 
     * @param int $id ID do link
     */
    public function stats($id) {
        $vendorId = get_current_user_id();
        
        // Obter link pelo ID
        $link = $this->referralLinkModel->getById($id);
        
        if (!$link || $link['vendor_id'] != $vendorId) {
            set_flash_message('error', 'Link não encontrado ou não pertence a você.');
            redirect('vendedor/links');
            return;
        }
        
        // Obter estatísticas detalhadas
        $stats = $this->referralLinkModel->getLinkStats($id);
        
        // Obter produto associado
        $product = null;
        if ($link['product_id']) {
            $product = $this->productModel->getById($link['product_id']);
        }
        
        // Obter gráfico de clicks/conversões por período
        $period = $_GET['period'] ?? '30days';
        $endDate = date('Y-m-d');
        $startDate = $this->getStartDateByPeriod($period);
        
        $clicksChart = $this->referralLinkModel->getClicksChartData($id, $startDate, $endDate);
        $conversionsChart = $this->referralLinkModel->getConversionsChartData($id, $startDate, $endDate);
        
        // Obter pedidos realizados através deste link
        $orders = $this->orderModel->getByReferralLink($id, 10);
        
        // Calcular taxas de conversão
        $conversionRate = $stats['clicks'] > 0 ? ($stats['conversions'] / $stats['clicks'] * 100) : 0;
        
        // Preparar dados para a view
        $data = [
            'title' => 'Estatísticas do Link',
            'link' => $link,
            'stats' => $stats,
            'product' => $product,
            'clicksChart' => $clicksChart,
            'conversionsChart' => $conversionsChart,
            'orders' => $orders,
            'conversionRate' => $conversionRate,
            'period' => $period,
            'baseUrl' => BASE_URL . 'ref/'
        ];
        
        // Renderizar a view
        view_with_layout('vendor/links/stats', $data, 'vendor');
    }
    
    /**
     * Ativa ou desativa um link
     * 
     * @param int $id ID do link
     */
    public function toggleStatus($id) {
        $vendorId = get_current_user_id();
        
        // Obter link pelo ID
        $link = $this->referralLinkModel->getById($id);
        
        if (!$link || $link['vendor_id'] != $vendorId) {
            set_flash_message('error', 'Link não encontrado ou não pertence a você.');
            redirect('vendedor/links');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/links');
            return;
        }
        
        try {
            // Inverter status
            $newStatus = $link['is_active'] ? 0 : 1;
            
            // Atualizar status
            $this->referralLinkModel->update($id, [
                'is_active' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Mensagem de sucesso
            $message = $newStatus ? 'Link ativado com sucesso!' : 'Link desativado com sucesso!';
            set_flash_message('success', $message);
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao alterar status do link: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao alterar status do link: ' . $e->getMessage());
        }
        
        redirect('vendedor/links');
    }
    
    /**
     * Exclui um link
     * 
     * @param int $id ID do link
     */
    public function delete($id) {
        $vendorId = get_current_user_id();
        
        // Obter link pelo ID
        $link = $this->referralLinkModel->getById($id);
        
        if (!$link || $link['vendor_id'] != $vendorId) {
            set_flash_message('error', 'Link não encontrado ou não pertence a você.');
            redirect('vendedor/links');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('vendedor/links');
            return;
        }
        
        // Verificar se há pedidos associados
        $hasOrders = $this->orderModel->hasOrdersByReferralLink($id);
        
        if ($hasOrders) {
            set_flash_message('error', 'Não é possível excluir este link porque existem pedidos associados a ele. Você pode desativá-lo em vez de excluir.');
            redirect('vendedor/links');
            return;
        }
        
        try {
            // Excluir link
            $this->referralLinkModel->delete($id);
            
            // Mensagem de sucesso
            set_flash_message('success', 'Link excluído com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao excluir link: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao excluir link: ' . $e->getMessage());
        }
        
        redirect('vendedor/links');
    }
    
    /**
     * Exporta links do vendedor em CSV
     */
    public function export() {
        $vendorId = get_current_user_id();
        
        // Obter vendedor
        $vendor = $this->vendorModel->getById($vendorId);
        
        if (!$vendor) {
            set_flash_message('error', 'Vendedor não encontrado.');
            redirect('vendedor/links');
            return;
        }
        
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        
        // Obter todos os links com filtros
        $links = $this->referralLinkModel->getVendorLinksWithFiltersNoLimit(
            $vendorId,
            $search,
            $productId
        );
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="meus_links_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'ID', 'Nome', 'Código', 'URL Completa', 'Produto', 
            'Cliques', 'Conversões', 'Taxa de Conversão', 'Status', 'Data de Criação'
        ]);
        
        // Dados
        foreach ($links as $link) {
            // Calcular taxa de conversão
            $conversionRate = $link['clicks'] > 0 ? ($link['conversions'] / $link['clicks'] * 100) : 0;
            
            fputcsv($output, [
                $link['id'],
                $link['name'] ?: '(Sem nome)',
                $link['code'],
                $link['url'],
                $link['product_name'] ?: 'Todos os produtos',
                $link['clicks'],
                $link['conversions'],
                number_format($conversionRate, 2, ',', '.') . '%',
                $link['is_active'] ? 'Ativo' : 'Inativo',
                date('d/m/Y', strtotime($link['created_at']))
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * AJAX: Verifica se um código personalizado está disponível
     */
    public function checkCode() {
        // Verificar se é uma requisição AJAX
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
            $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Requisição inválida']);
            exit;
        }
        
        // Obter código para verificar
        $code = $_POST['code'] ?? '';
        
        if (empty($code)) {
            echo json_encode(['available' => false, 'message' => 'Código não pode ser vazio']);
            exit;
        }
        
        // Validar formato do código
        if (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $code)) {
            echo json_encode([
                'available' => false, 
                'message' => 'Código inválido. Use apenas letras, números, hífens e underscores (3-20 caracteres).'
            ]);
            exit;
        }
        
        // Verificar se o código já existe
        $exists = $this->referralLinkModel->checkCodeExists($code);
        
        if ($exists) {
            echo json_encode(['available' => false, 'message' => 'Este código já está em uso.']);
        } else {
            echo json_encode(['available' => true, 'message' => 'Código disponível!']);
        }
        
        exit;
    }
    
    /**
     * Validação do formulário de link
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateLinkForm($data) {
        $errors = [];
        
        // Nome (opcional)
        if (!empty($data['name']) && strlen($data['name']) > 100) {
            $errors['name'] = 'O nome não pode ter mais de 100 caracteres.';
        }
        
        // Código personalizado (opcional)
        if (!empty($data['custom_code'])) {
            if (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $data['custom_code'])) {
                $errors['custom_code'] = 'Código inválido. Use apenas letras, números, hífens e underscores (3-20 caracteres).';
            }
            
            // Verificar se o código já existe
            if ($this->referralLinkModel->checkCodeExists($data['custom_code'])) {
                $errors['custom_code'] = 'Este código já está em uso.';
            }
        }
        
        // Produto (opcional)
        if (!empty($data['product_id'])) {
            $productId = (int) $data['product_id'];
            $product = $this->productModel->getById($productId);
            
            if (!$product || !$product['is_active']) {
                $errors['product_id'] = 'Produto inválido ou inativo.';
            }
        }
        
        return $errors;
    }
    
    /**
     * Gera um código único aleatório
     * 
     * @param int $length Comprimento do código
     * @return string Código gerado
     */
    private function generateUniqueCode($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $count = strlen($chars);
        
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, $count - 1)];
            }
        } while ($this->referralLinkModel->checkCodeExists($code));
        
        return $code;
    }
    
    /**
     * Calcula a data de início com base no período selecionado
     *
     * @param string $period Período selecionado (today, 7days, 30days, year, etc)
     * @return string Data de início no formato Y-m-d
     */
    private function getStartDateByPeriod($period) {
        $today = date('Y-m-d');
        
        switch ($period) {
            case 'today':
                return $today;
            
            case '7days':
                return date('Y-m-d', strtotime('-7 days'));
            
            case '30days':
                return date('Y-m-d', strtotime('-30 days'));
            
            case 'month':
                return date('Y-m-01'); // Primeiro dia do mês atual
            
            case 'lastmonth':
                return date('Y-m-d', strtotime('first day of last month'));
            
            case 'year':
                return date('Y-01-01'); // Primeiro dia do ano atual
            
            default:
                return date('Y-m-d', strtotime('-30 days'));
        }
    }
}