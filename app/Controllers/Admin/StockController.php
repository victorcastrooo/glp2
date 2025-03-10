<?php
/**
 * StockController.php - Controlador para gerenciamento de estoque no admin
 * 
 * Este controlador gerencia todas as operações relacionadas ao estoque de produtos,
 * incluindo atualizações de estoque, registro de lotes, controle de validade e
 * visualização de histórico de movimentações.
 */

namespace App\Controllers\Admin;

use App\Models\ProductModel;
use App\Models\StockModel;

class StockController {
    /**
     * @var ProductModel Modelo de produtos
     */
    private $productModel;
    
    /**
     * @var StockModel Modelo de estoque
     */
    private $stockModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->productModel = new ProductModel();
        $this->stockModel = new StockModel();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Exibe a página principal de gerenciamento de estoque
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $stockFilter = $_GET['stock'] ?? 'all'; // all, low, out
        $expiring = isset($_GET['expiring']) ? (int)$_GET['expiring'] : 0; // dias para expirar
        
        // Obter produtos com seus estoques
        $products = $this->stockModel->getProductsWithStock(
            $search,
            $stockFilter,
            $expiring,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalProducts = $this->stockModel->countProductsWithStock(
            $search,
            $stockFilter,
            $expiring
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalProducts / $limit);
        
        // Obter alertas de estoque
        $lowStockThreshold = 10; // Limite para estoque baixo
        $expiringDays = 30; // Produtos que expiram em 30 dias
        
        $stockAlerts = [
            'lowStock' => $this->stockModel->getProductsWithLowStock($lowStockThreshold, 5),
            'expiring' => $this->stockModel->getExpiringBatches($expiringDays, 5),
            'outOfStock' => $this->stockModel->getOutOfStockProducts(5)
        ];
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Estoque',
            'products' => $products,
            'stockAlerts' => $stockAlerts,
            'search' => $search,
            'stockFilter' => $stockFilter,
            'expiring' => $expiring,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalProducts' => $totalProducts
        ];
        
        // Renderizar a view
        view_with_layout('admin/stock/index', $data, 'admin');
    }
    
    /**
     * Exibe detalhes do estoque de um produto específico
     * 
     * @param int $id ID do produto
     */
    public function product($id) {
        // Obter produto pelo ID
        $product = $this->productModel->getById($id);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/estoque');
            return;
        }
        
        // Obter lotes do produto
        $batches = $this->stockModel->getProductBatches($id);
        
        // Obter histórico de movimentações do estoque
        $movements = $this->stockModel->getProductStockHistory($id, 20);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Estoque do Produto: ' . $product['name'],
            'product' => $product,
            'batches' => $batches,
            'movements' => $movements,
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('admin/stock/product', $data, 'admin');
    }
    
    /**
     * Processa a atualização de estoque de um produto
     * 
     * @param int $id ID do produto
     */
    public function update($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/estoque/produto/' . $id);
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/estoque/produto/' . $id);
            return;
        }
        
        // Obter produto pelo ID
        $product = $this->productModel->getById($id);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/estoque');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateStockForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/estoque/produto/' . $id);
            return;
        }
        
        // Determinar o tipo de movimentação
        $movementType = $_POST['movement_type'] ?? 'adjustment';
        $quantity = (int) $_POST['quantity'];
        
        // Para retiradas, converter quantidade para negativo
        if ($movementType === 'removal' || $movementType === 'sale') {
            $quantity = -abs($quantity);
        }
        
        // Verificar se há estoque suficiente para retiradas
        if ($quantity < 0 && ($product['stock_quantity'] + $quantity) < 0) {
            set_flash_message('error', 'Quantidade insuficiente em estoque para esta operação.');
            redirect('admin/estoque/produto/' . $id);
            return;
        }
        
        // Preparar dados para atualização de estoque
        $stockData = [
            'product_id' => $id,
            'quantity' => $quantity,
            'type' => $movementType,
            'reference_id' => $_POST['reference_id'] ?? null,
            'batch_number' => sanitize_string($_POST['batch_number'] ?? ''),
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'notes' => sanitize_string($_POST['notes'] ?? ''),
            'performed_by' => get_current_user_id()
        ];
        
        // Atualizar estoque
        try {
            // Registrar movimentação
            $movementId = $this->stockModel->addStockMovement($stockData);
            
            // Atualizar quantidade total do produto
            $this->productModel->updateStockQuantity($id, $quantity);
            
            // Criar ou atualizar lote se for entrada ou ajuste positivo
            if ($quantity > 0 && !empty($_POST['batch_number'])) {
                // Verificar se o lote já existe
                $existingBatch = $this->stockModel->getBatchByNumber($id, $_POST['batch_number']);
                
                if ($existingBatch) {
                    // Atualizar lote existente
                    $this->stockModel->updateBatch(
                        $existingBatch['id'],
                        $existingBatch['quantity'] + $quantity,
                        $_POST['expiry_date'] ?? null
                    );
                } else {
                    // Criar novo lote
                    $batchData = [
                        'product_id' => $id,
                        'batch_number' => sanitize_string($_POST['batch_number']),
                        'quantity' => $quantity,
                        'expiry_date' => $_POST['expiry_date'] ?? null,
                        'purchase_date' => $_POST['purchase_date'] ?? date('Y-m-d'),
                        'cost_price' => !empty($_POST['cost_price']) ? str_replace(',', '.', $_POST['cost_price']) : null,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $this->stockModel->createBatch($batchData);
                }
            }
            
            // Atualizar quantidade do lote se for saída e especificado o lote
            if ($quantity < 0 && !empty($_POST['batch_id'])) {
                $batchId = (int) $_POST['batch_id'];
                $batch = $this->stockModel->getBatchById($batchId);
                
                if ($batch && $batch['product_id'] == $id) {
                    // Verificar se há quantidade suficiente no lote
                    if ($batch['quantity'] + $quantity >= 0) {
                        $this->stockModel->updateBatchQuantity($batchId, $quantity);
                    } else {
                        set_flash_message('warning', 'A quantidade retirada excede o disponível no lote selecionado. O estoque total foi atualizado, mas o lote não foi alterado.');
                    }
                }
            }
            
            set_flash_message('success', 'Estoque atualizado com sucesso!');
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar estoque: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao atualizar estoque: ' . $e->getMessage());
        }
        
        redirect('admin/estoque/produto/' . $id);
    }
    
    /**
     * Exibe o histórico de movimentações de estoque
     */
    public function history() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 30;
        $offset = ($page - 1) * $limit;
        
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $type = $_GET['type'] ?? '';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Obter movimentações com filtros
        $movements = $this->stockModel->getStockMovementsWithFilters(
            $productId,
            $type,
            $startDate,
            $endDate,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalMovements = $this->stockModel->countStockMovementsWithFilters(
            $productId,
            $type,
            $startDate,
            $endDate
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalMovements / $limit);
        
        // Obter todos os produtos para o filtro
        $products = $this->productModel->getAllBasic();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Histórico de Movimentações de Estoque',
            'movements' => $movements,
            'products' => $products,
            'productId' => $productId,
            'type' => $type,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalMovements' => $totalMovements
        ];
        
        // Renderizar a view
        view_with_layout('admin/stock/history', $data, 'admin');
    }
    
    /**
     * Exibe a página de gerenciamento de lotes
     */
    public function batches() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $search = $_GET['search'] ?? '';
        $expiring = isset($_GET['expiring']) ? (int)$_GET['expiring'] : 0;
        $empty = isset($_GET['empty']) ? (bool)$_GET['empty'] : false;
        
        // Obter lotes com filtros
        $batches = $this->stockModel->getBatchesWithFilters(
            $productId,
            $search,
            $expiring,
            $empty,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalBatches = $this->stockModel->countBatchesWithFilters(
            $productId,
            $search,
            $expiring,
            $empty
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalBatches / $limit);
        
        // Obter todos os produtos para o filtro
        $products = $this->productModel->getAllBasic();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Lotes de Produtos',
            'batches' => $batches,
            'products' => $products,
            'productId' => $productId,
            'search' => $search,
            'expiring' => $expiring,
            'empty' => $empty,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalBatches' => $totalBatches,
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('admin/stock/batches', $data, 'admin');
    }
    
    /**
     * Processa o formulário de adição de novo lote
     */
    public function addBatch() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Obter produto pelo ID
        $productId = (int) $_POST['product_id'];
        $product = $this->productModel->getById($productId);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateBatchForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Verificar se o lote já existe
        $batchNumber = sanitize_string($_POST['batch_number']);
        $existingBatch = $this->stockModel->getBatchByNumber($productId, $batchNumber);
        
        if ($existingBatch) {
            set_flash_message('error', 'Já existe um lote com este número para o produto selecionado.');
            $_SESSION['form_data'] = $_POST;
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Preparar dados do lote
        $quantity = (int) $_POST['quantity'];
        $batchData = [
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'quantity' => $quantity,
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'purchase_date' => $_POST['purchase_date'] ?? date('Y-m-d'),
            'cost_price' => !empty($_POST['cost_price']) ? str_replace(',', '.', $_POST['cost_price']) : null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Adicionar lote e registrar movimento de estoque
        try {
            $db = getDbConnection();
            $db->beginTransaction();
            
            // Criar lote
            $batchId = $this->stockModel->createBatch($batchData);
            
            // Registrar movimento de estoque
            $stockData = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'type' => 'purchase',
                'reference_id' => null,
                'batch_number' => $batchNumber,
                'expiry_date' => $_POST['expiry_date'] ?? null,
                'notes' => 'Adição de novo lote: ' . $batchNumber,
                'performed_by' => get_current_user_id()
            ];
            
            $this->stockModel->addStockMovement($stockData);
            
            // Atualizar quantidade total do produto
            $this->productModel->updateStockQuantity($productId, $quantity);
            
            $db->commit();
            
            set_flash_message('success', 'Lote adicionado com sucesso!');
            
        } catch (\Exception $e) {
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao adicionar lote: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao adicionar lote: ' . $e->getMessage());
        }
        
        redirect('admin/estoque/lotes');
    }
    
    /**
     * Edita um lote existente
     */
    public function editBatch($id) {
        // Obter lote pelo ID
        $batch = $this->stockModel->getBatchById($id);
        
        if (!$batch) {
            set_flash_message('error', 'Lote não encontrado.');
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validar token CSRF
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
                redirect('admin/estoque/lotes/editar/' . $id);
                return;
            }
            
            // Validar dados do formulário
            $errors = $this->validateBatchEditForm($_POST);
            
            if (!empty($errors)) {
                // Se houver erros, salvar em flash e redirecionar
                $_SESSION['errors'] = $errors;
                $_SESSION['form_data'] = $_POST;
                redirect('admin/estoque/lotes/editar/' . $id);
                return;
            }
            
            // Calcular diferença de quantidade
            $oldQuantity = $batch['quantity'];
            $newQuantity = (int) $_POST['quantity'];
            $quantityDiff = $newQuantity - $oldQuantity;
            
            // Atualizar dados do lote
            $batchData = [
                'batch_number' => sanitize_string($_POST['batch_number']),
                'quantity' => $newQuantity,
                'expiry_date' => $_POST['expiry_date'] ?? null,
                'purchase_date' => $_POST['purchase_date'] ?? null,
                'cost_price' => !empty($_POST['cost_price']) ? str_replace(',', '.', $_POST['cost_price']) : null
            ];
            
            try {
                $db = getDbConnection();
                $db->beginTransaction();
                
                // Atualizar lote
                $this->stockModel->updateBatchFull($id, $batchData);
                
                // Se houver alteração na quantidade, registrar movimento
                if ($quantityDiff != 0) {
                    // Registrar movimento de estoque
                    $stockData = [
                        'product_id' => $batch['product_id'],
                        'quantity' => $quantityDiff,
                        'type' => 'adjustment',
                        'reference_id' => null,
                        'batch_number' => $batchData['batch_number'],
                        'expiry_date' => $batchData['expiry_date'],
                        'notes' => 'Ajuste de quantidade do lote: ' . $batchData['batch_number'],
                        'performed_by' => get_current_user_id()
                    ];
                    
                    $this->stockModel->addStockMovement($stockData);
                    
                    // Atualizar quantidade total do produto
                    $this->productModel->updateStockQuantity($batch['product_id'], $quantityDiff);
                }
                
                $db->commit();
                
                set_flash_message('success', 'Lote atualizado com sucesso!');
                redirect('admin/estoque/lotes');
                
            } catch (\Exception $e) {
                $db->rollBack();
                
                // Registrar erro
                log_message('Erro ao atualizar lote: ' . $e->getMessage(), 'error');
                
                // Mensagem de erro
                set_flash_message('error', 'Erro ao atualizar lote: ' . $e->getMessage());
                redirect('admin/estoque/lotes/editar/' . $id);
            }
        } else {
            // Obter produto relacionado ao lote
            $product = $this->productModel->getById($batch['product_id']);
            
            // Preparar dados para a view
            $data = [
                'title' => 'Editar Lote',
                'batch' => $batch,
                'product' => $product,
                'errors' => get_flash_messages(true)['errors'] ?? []
            ];
            
            // Renderizar a view
            view_with_layout('admin/stock/edit_batch', $data, 'admin');
        }
    }
    
    /**
     * Remove um lote
     * 
     * @param int $id ID do lote
     */
    public function deleteBatch($id) {
        // Obter lote pelo ID
        $batch = $this->stockModel->getBatchById($id);
        
        if (!$batch) {
            set_flash_message('error', 'Lote não encontrado.');
            redirect('admin/estoque/lotes');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/estoque/lotes');
            return;
        }
        
        try {
            $db = getDbConnection();
            $db->beginTransaction();
            
            // Registrar movimento de estoque negativo
            if ($batch['quantity'] > 0) {
                $stockData = [
                    'product_id' => $batch['product_id'],
                    'quantity' => -$batch['quantity'],
                    'type' => 'adjustment',
                    'reference_id' => null,
                    'batch_number' => $batch['batch_number'],
                    'expiry_date' => $batch['expiry_date'],
                    'notes' => 'Remoção do lote: ' . $batch['batch_number'],
                    'performed_by' => get_current_user_id()
                ];
                
                $this->stockModel->addStockMovement($stockData);
                
                // Atualizar quantidade total do produto
                $this->productModel->updateStockQuantity($batch['product_id'], -$batch['quantity']);
            }
            
            // Excluir lote
            $this->stockModel->deleteBatch($id);
            
            $db->commit();
            
            set_flash_message('success', 'Lote excluído com sucesso!');
            
        } catch (\Exception $e) {
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao excluir lote: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao excluir lote: ' . $e->getMessage());
        }
        
        redirect('admin/estoque/lotes');
    }
    
    /**
     * Validação do formulário de atualização de estoque
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateStockForm($data) {
        $errors = [];
        
        // Quantidade
        if (!isset($data['quantity']) || $data['quantity'] === '') {
            $errors['quantity'] = 'A quantidade é obrigatória.';
        } elseif (!is_numeric($data['quantity']) || (int)$data['quantity'] == 0) {
            $errors['quantity'] = 'A quantidade deve ser um número diferente de zero.';
        }
        
        // Tipo de movimentação
        if (empty($data['movement_type'])) {
            $errors['movement_type'] = 'O tipo de movimentação é obrigatório.';
        } elseif (!in_array($data['movement_type'], ['purchase', 'sale', 'adjustment', 'removal', 'return'])) {
            $errors['movement_type'] = 'Tipo de movimentação inválido.';
        }
        
        // Lote (obrigatório para entrada de estoque)
        $isStockEntry = ($data['movement_type'] == 'purchase' || $data['movement_type'] == 'return' || 
                         ($data['movement_type'] == 'adjustment' && (int)$data['quantity'] > 0));
        
        if ($isStockEntry && empty($data['batch_number'])) {
            $errors['batch_number'] = 'O número do lote é obrigatório para entradas de estoque.';
        }
        
        // Data de validade (obrigatória para novos lotes)
        if ($isStockEntry && !empty($data['batch_number']) && empty($data['expiry_date'])) {
            $errors['expiry_date'] = 'A data de validade é obrigatória para novos lotes.';
        }
        
        // Validar formato da data de validade
        if (!empty($data['expiry_date'])) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['expiry_date']);
            if (!$date || $date->format('Y-m-d') !== $data['expiry_date']) {
                $errors['expiry_date'] = 'Data de validade inválida. Use o formato AAAA-MM-DD.';
            }
        }
        
        // Custo (para compras)
        if ($data['movement_type'] == 'purchase' && !empty($data['cost_price'])) {
            if (!is_numeric(str_replace(',', '.', $data['cost_price']))) {
                $errors['cost_price'] = 'O custo deve ser um valor numérico.';
            } elseif (floatval(str_replace(',', '.', $data['cost_price'])) <= 0) {
                $errors['cost_price'] = 'O custo deve ser maior que zero.';
            }
        }
        
        return $errors;
    }
    
    /**
     * Validação do formulário de adição de lote
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateBatchForm($data) {
        $errors = [];
        
        // Produto
        if (empty($data['product_id'])) {
            $errors['product_id'] = 'Selecione um produto.';
        }
        
        // Número do lote
        if (empty($data['batch_number'])) {
            $errors['batch_number'] = 'O número do lote é obrigatório.';
        }
        
        // Quantidade
        if (!isset($data['quantity']) || $data['quantity'] === '') {
            $errors['quantity'] = 'A quantidade é obrigatória.';
        } elseif (!is_numeric($data['quantity']) || (int)$data['quantity'] <= 0) {
            $errors['quantity'] = 'A quantidade deve ser um número maior que zero.';
        }
        
        // Data de validade
        if (empty($data['expiry_date'])) {
            $errors['expiry_date'] = 'A data de validade é obrigatória.';
        } else {
            $date = \DateTime::createFromFormat('Y-m-d', $data['expiry_date']);
            if (!$date || $date->format('Y-m-d') !== $data['expiry_date']) {
                $errors['expiry_date'] = 'Data de validade inválida. Use o formato AAAA-MM-DD.';
            }
        }
        
        // Data de compra
        if (!empty($data['purchase_date'])) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['purchase_date']);
            if (!$date || $date->format('Y-m-d') !== $data['purchase_date']) {
                $errors['purchase_date'] = 'Data de compra inválida. Use o formato AAAA-MM-DD.';
            }
        }
        
        // Custo
        if (!empty($data['cost_price'])) {
            if (!is_numeric(str_replace(',', '.', $data['cost_price']))) {
                $errors['cost_price'] = 'O custo deve ser um valor numérico.';
            } elseif (floatval(str_replace(',', '.', $data['cost_price'])) <= 0) {
                $errors['cost_price'] = 'O custo deve ser maior que zero.';
            }
        }
        
        return $errors;
    }
    
    /**
     * Validação do formulário de edição de lote
     * 
     * @param array $data Dados do formulário
     * @return array Erros encontrados
     */
    private function validateBatchEditForm($data) {
        $errors = [];
        
        // Número do lote
       if (empty($data['batch_number'])) {
        $errors['batch_number'] = 'O número do lote é obrigatório.';
    }
    
    // Quantidade
    if (!isset($data['quantity']) || $data['quantity'] === '') {
        $errors['quantity'] = 'A quantidade é obrigatória.';
    } elseif (!is_numeric($data['quantity']) || (int)$data['quantity'] < 0) {
        $errors['quantity'] = 'A quantidade deve ser um número não negativo.';
    }
    
    // Data de validade
    if (empty($data['expiry_date'])) {
        $errors['expiry_date'] = 'A data de validade é obrigatória.';
    } else {
        $date = \DateTime::createFromFormat('Y-m-d', $data['expiry_date']);
        if (!$date || $date->format('Y-m-d') !== $data['expiry_date']) {
            $errors['expiry_date'] = 'Data de validade inválida. Use o formato AAAA-MM-DD.';
        }
    }
    
    // Data de compra
    if (!empty($data['purchase_date'])) {
        $date = \DateTime::createFromFormat('Y-m-d', $data['purchase_date']);
        if (!$date || $date->format('Y-m-d') !== $data['purchase_date']) {
            $errors['purchase_date'] = 'Data de compra inválida. Use o formato AAAA-MM-DD.';
        }
    }
    
    // Custo
    if (!empty($data['cost_price'])) {
        if (!is_numeric(str_replace(',', '.', $data['cost_price']))) {
            $errors['cost_price'] = 'O custo deve ser um valor numérico.';
        } elseif (floatval(str_replace(',', '.', $data['cost_price'])) <= 0) {
            $errors['cost_price'] = 'O custo deve ser maior que zero.';
        }
    }
    
    return $errors;
}

/**
 * Exporta relatório de estoque
 * 
 * @param string $type Tipo de relatório (inventory, movements, batches)
 */
public function exportReport($type = 'inventory') {
    // Verificar tipo de relatório
    if (!in_array($type, ['inventory', 'movements', 'batches'])) {
        set_flash_message('error', 'Tipo de relatório inválido.');
        redirect('admin/estoque');
        return;
    }
    
    // Obter filtros
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $search = $_GET['search'] ?? '';
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    // Definir nome do arquivo
    $filename = 'relatorio_' . $type . '_' . date('Y-m-d') . '.csv';
    
    // Configurar cabeçalhos HTTP
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Criar handle de arquivo para output
    $output = fopen('php://output', 'w');
    
    // Adicionar BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Gerar relatório com base no tipo
    switch ($type) {
        case 'inventory':
            // Cabeçalhos
            fputcsv($output, [
                'ID', 'Produto', 'SKU', 'Quantidade', 'Valor Estoque', 
                'Última Atualização', 'Status'
            ]);
            
            // Dados
            $products = $this->stockModel->getProductsWithStockForReport($search, $productId);
            
            foreach ($products as $product) {
                $status = '';
                if ($product['stock_quantity'] <= 0) {
                    $status = 'Esgotado';
                } elseif ($product['stock_quantity'] <= 10) {
                    $status = 'Estoque Baixo';
                } else {
                    $status = 'Normal';
                }
                
                fputcsv($output, [
                    $product['id'],
                    $product['name'],
                    $product['sku'] ?? '',
                    $product['stock_quantity'],
                    number_format($product['stock_quantity'] * $product['price'], 2, ',', '.'),
                    $product['updated_at'],
                    $status
                ]);
            }
            break;
            
        case 'movements':
            // Cabeçalhos
            fputcsv($output, [
                'ID', 'Data', 'Produto', 'Tipo', 'Quantidade', 
                'Lote', 'Validade', 'Responsável', 'Observações'
            ]);
            
            // Dados
            $movements = $this->stockModel->getStockMovementsForReport(
                $productId,
                $startDate,
                $endDate
            );
            
            foreach ($movements as $movement) {
                // Traduzir tipo
                $typeTranslated = '';
                switch ($movement['type']) {
                    case 'purchase': $typeTranslated = 'Compra'; break;
                    case 'sale': $typeTranslated = 'Venda'; break;
                    case 'adjustment': $typeTranslated = 'Ajuste'; break;
                    case 'removal': $typeTranslated = 'Remoção'; break;
                    case 'return': $typeTranslated = 'Devolução'; break;
                    default: $typeTranslated = $movement['type'];
                }
                
                fputcsv($output, [
                    $movement['id'],
                    date('d/m/Y H:i', strtotime($movement['created_at'])),
                    $movement['product_name'],
                    $typeTranslated,
                    $movement['quantity'],
                    $movement['batch_number'] ?? '',
                    $movement['expiry_date'] ? date('d/m/Y', strtotime($movement['expiry_date'])) : '',
                    $movement['user_name'],
                    $movement['notes']
                ]);
            }
            break;
            
        case 'batches':
            // Cabeçalhos
            fputcsv($output, [
                'ID', 'Produto', 'Lote', 'Quantidade', 'Data Compra',
                'Validade', 'Custo Unitário', 'Dias até Vencimento', 'Status'
            ]);
            
            // Dados
            $batches = $this->stockModel->getBatchesForReport($productId, $search);
            
            foreach ($batches as $batch) {
                // Calcular dias até o vencimento
                $daysToExpiry = '';
                $status = '';
                
                if ($batch['expiry_date']) {
                    $today = new \DateTime();
                    $expiryDate = new \DateTime($batch['expiry_date']);
                    $diff = $today->diff($expiryDate);
                    $daysToExpiry = $diff->invert ? -$diff->days : $diff->days;
                    
                    if ($diff->invert) {
                        $status = 'Vencido';
                    } elseif ($daysToExpiry <= 30) {
                        $status = 'Próximo ao Vencimento';
                    } else {
                        $status = 'Normal';
                    }
                }
                
                if ($batch['quantity'] <= 0) {
                    $status = 'Esgotado';
                }
                
                fputcsv($output, [
                    $batch['id'],
                    $batch['product_name'],
                    $batch['batch_number'],
                    $batch['quantity'],
                    $batch['purchase_date'] ? date('d/m/Y', strtotime($batch['purchase_date'])) : '',
                    $batch['expiry_date'] ? date('d/m/Y', strtotime($batch['expiry_date'])) : '',
                    $batch['cost_price'] ? number_format($batch['cost_price'], 2, ',', '.') : '',
                    $daysToExpiry,
                    $status
                ]);
            }
            break;
    }
    
    // Fechar handle de arquivo
    fclose($output);
    exit;
}
}