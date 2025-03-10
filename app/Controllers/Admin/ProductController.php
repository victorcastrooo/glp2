<?php
/**
 * ProductController.php - Controlador para gerenciamento de produtos no admin
 * 
 * Este controlador gerencia todas as operações relacionadas a produtos 
 * no painel administrativo, incluindo listagem, criação, edição, exclusão,
 * e gerenciamento de imagens.
 */

namespace App\Controllers\Admin;

use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\StockModel;
use App\Libraries\FileUpload;

class ProductController {
    /**
     * @var ProductModel Modelo de produtos
     */
    private $productModel;
    
    /**
     * @var CategoryModel Modelo de categorias
     */
    private $categoryModel;
    
    /**
     * @var StockModel Modelo de estoque
     */
    private $stockModel;
    
    /**
     * @var FileUpload Biblioteca para upload de arquivos
     */
    private $fileUpload;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos e bibliotecas
        $this->productModel = new ProductModel();
        $this->categoryModel = new CategoryModel();
        $this->stockModel = new StockModel();
        $this->fileUpload = new FileUpload();
        
        // Verificar se o usuário é admin
        if (!is_admin()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todos os produtos
     */
    public function index() {
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $categoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;
        $status = $_GET['status'] ?? 'all';
        
        // Obter produtos com filtros aplicados
        $products = $this->productModel->getAllWithFilters(
            $search,
            $categoryId,
            $status,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalProducts = $this->productModel->countAllWithFilters(
            $search,
            $categoryId,
            $status
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalProducts / $limit);
        
        // Obter categorias para filtro
        $categories = $this->categoryModel->getAll();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Gerenciar Produtos',
            'products' => $products,
            'categories' => $categories,
            'search' => $search,
            'categoryId' => $categoryId,
            'status' => $status,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalProducts' => $totalProducts
        ];
        
        // Renderizar a view
        view_with_layout('admin/products/index', $data, 'admin');
    }
    
    /**
     * Exibe o formulário para criar um novo produto
     */
    public function create() {
        // Obter categorias para o formulário
        $categories = $this->categoryModel->getAll();
        
        // Preparar dados para a view
        $data = [
            'title' => 'Novo Produto',
            'categories' => $categories,
            'product' => null, // Produto vazio para o formulário
            'action' => 'store',
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('admin/products/create', $data, 'admin');
    }
    
    /**
     * Processa o formulário de criação de produto
     */
    public function store() {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/produtos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/produtos/novo');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateProductForm($_POST);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar de volta
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/produtos/novo');
            return;
        }
        
        // Processar upload de imagem
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $uploadResult = $this->fileUpload->uploadImage(
                $_FILES['image'],
                'products',
                true, // Redimensionar imagem
                800,  // Largura máxima
                800   // Altura máxima
            );
            
            if ($uploadResult['success']) {
                $imagePath = $uploadResult['path'];
            } else {
                set_flash_message('error', 'Erro ao fazer upload da imagem: ' . $uploadResult['error']);
                redirect('admin/produtos/novo');
                return;
            }
        }
        
        // Gerar slug a partir do nome
        $slug = $this->generateSlug($_POST['name']);
        
        // Preparar dados para inserção
        $productData = [
            'name' => sanitize_string($_POST['name']),
            'slug' => $slug,
            'description' => $_POST['description'],
            'short_description' => sanitize_string($_POST['short_description'] ?? ''),
            'price' => str_replace(',', '.', $_POST['price']),
            'sale_price' => !empty($_POST['sale_price']) ? str_replace(',', '.', $_POST['sale_price']) : null,
            'sku' => sanitize_string($_POST['sku'] ?? ''),
            'barcode' => sanitize_string($_POST['barcode'] ?? ''),
            'stock_quantity' => (int) $_POST['initial_stock'],
            'category_id' => (int) $_POST['category_id'],
            'image' => $imagePath,
            'requires_prescription' => isset($_POST['requires_prescription']) ? 1 : 0,
            'concentration' => sanitize_string($_POST['concentration'] ?? ''),
            'dosage_form' => sanitize_string($_POST['dosage_form'] ?? ''),
            'weight' => !empty($_POST['weight']) ? str_replace(',', '.', $_POST['weight']) : null,
            'dimensions' => sanitize_string($_POST['dimensions'] ?? ''),
            'manufacturer' => sanitize_string($_POST['manufacturer'] ?? ''),
            'tax_info' => sanitize_string($_POST['tax_info'] ?? ''),
            'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        
        // Iniciar transação
        $db = getDbConnection();
        $db->beginTransaction();
        
        try {
            // Inserir produto
            $productId = $this->productModel->create($productData);
            
            // Adicionar estoque inicial
            if ($productId && (int) $_POST['initial_stock'] > 0) {
                $stockData = [
                    'product_id' => $productId,
                    'quantity' => (int) $_POST['initial_stock'],
                    'type' => 'purchase',
                    'reference_id' => null,
                    'batch_number' => sanitize_string($_POST['batch_number'] ?? ''),
                    'expiry_date' => $_POST['expiry_date'] ?? null,
                    'notes' => 'Estoque inicial',
                    'performed_by' => get_current_user_id()
                ];
                
                $this->stockModel->addStockMovement($stockData);
            }
            
            // Confirmar transação
            $db->commit();
            
            // Mensagem de sucesso
            set_flash_message('success', 'Produto criado com sucesso!');
            redirect('admin/produtos');
            
        } catch (\Exception $e) {
            // Reverter transação em caso de erro
            $db->rollBack();
            
            // Registrar erro
            log_message('Erro ao criar produto: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao criar produto: ' . $e->getMessage());
            redirect('admin/produtos/novo');
        }
    }
    
    /**
     * Exibe o formulário para editar um produto
     * 
     * @param int $id ID do produto
     */
    public function edit($id) {
        // Obter produto pelo ID
        $product = $this->productModel->getById($id);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/produtos');
            return;
        }
        
        // Obter categorias para o formulário
        $categories = $this->categoryModel->getAll();
        
        // Obter imagens adicionais do produto
        $productImages = $this->productModel->getProductImages($id);
        
        // Obter histórico de estoque
        $stockHistory = $this->stockModel->getProductStockHistory($id, 5);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Editar Produto',
            'product' => $product,
            'productImages' => $productImages,
            'categories' => $categories,
            'stockHistory' => $stockHistory,
            'action' => 'update',
            'errors' => get_flash_messages(true)['errors'] ?? []
        ];
        
        // Renderizar a view
        view_with_layout('admin/products/edit', $data, 'admin');
    }
    
    /**
     * Processa o formulário de edição de produto
     * 
     * @param int $id ID do produto
     */
    public function update($id) {
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('admin/produtos');
            return;
        }
        
        // Validar token CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/produtos/editar/' . $id);
            return;
        }
        
        // Obter produto existente
        $product = $this->productModel->getById($id);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/produtos');
            return;
        }
        
        // Validar dados do formulário
        $errors = $this->validateProductForm($_POST, $id);
        
        if (!empty($errors)) {
            // Se houver erros, salvar em flash e redirecionar de volta
            $_SESSION['errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            redirect('admin/produtos/editar/' . $id);
            return;
        }
        
        // Processar upload de imagem se houver
        $imagePath = $product['image'];
        if (!empty($_FILES['image']['name'])) {
            $uploadResult = $this->fileUpload->uploadImage(
                $_FILES['image'],
                'products',
                true, // Redimensionar imagem
                800,  // Largura máxima
                800   // Altura máxima
            );
            
            if ($uploadResult['success']) {
                // Deletar imagem antiga se existir
                if (!empty($product['image']) && file_exists(PUBLIC_PATH . $product['image'])) {
                    unlink(PUBLIC_PATH . $product['image']);
                }
                
                $imagePath = $uploadResult['path'];
            } else {
                set_flash_message('error', 'Erro ao fazer upload da imagem: ' . $uploadResult['error']);
                redirect('admin/produtos/editar/' . $id);
                return;
            }
        }
        
        // Gerar slug se o nome for alterado
        $slug = $product['slug'];
        if ($_POST['name'] !== $product['name']) {
            $slug = $this->generateSlug($_POST['name'], $id);
        }
        
        // Preparar dados para atualização
        $productData = [
            'name' => sanitize_string($_POST['name']),
            'slug' => $slug,
            'description' => $_POST['description'],
            'short_description' => sanitize_string($_POST['short_description'] ?? ''),
            'price' => str_replace(',', '.', $_POST['price']),
            'sale_price' => !empty($_POST['sale_price']) ? str_replace(',', '.', $_POST['sale_price']) : null,
            'sku' => sanitize_string($_POST['sku'] ?? ''),
            'barcode' => sanitize_string($_POST['barcode'] ?? ''),
            'category_id' => (int) $_POST['category_id'],
            'image' => $imagePath,
            'requires_prescription' => isset($_POST['requires_prescription']) ? 1 : 0,
            'concentration' => sanitize_string($_POST['concentration'] ?? ''),
            'dosage_form' => sanitize_string($_POST['dosage_form'] ?? ''),
            'weight' => !empty($_POST['weight']) ? str_replace(',', '.', $_POST['weight']) : null,
            'dimensions' => sanitize_string($_POST['dimensions'] ?? ''),
            'manufacturer' => sanitize_string($_POST['manufacturer'] ?? ''),
            'tax_info' => sanitize_string($_POST['tax_info'] ?? ''),
            'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Atualizar produto
        try {
            $success = $this->productModel->update($id, $productData);
            
            if ($success) {
                // Atualizar estoque se necessário
                if (isset($_POST['adjust_stock']) && $_POST['adjust_stock'] != 0) {
                    $stockData = [
                        'product_id' => $id,
                        'quantity' => (int) $_POST['adjust_stock'],
                        'type' => 'adjustment',
                        'reference_id' => null,
                        'batch_number' => sanitize_string($_POST['batch_number'] ?? ''),
                        'expiry_date' => $_POST['expiry_date'] ?? null,
                        'notes' => sanitize_string($_POST['stock_notes'] ?? 'Ajuste manual'),
                        'performed_by' => get_current_user_id()
                    ];
                    
                    $this->stockModel->addStockMovement($stockData);
                }
                
                set_flash_message('success', 'Produto atualizado com sucesso!');
            } else {
                set_flash_message('error', 'Erro ao atualizar produto. Tente novamente.');
            }
            
            redirect('admin/produtos/editar/' . $id);
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao atualizar produto: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao atualizar produto: ' . $e->getMessage());
            redirect('admin/produtos/editar/' . $id);
        }
    }
    
    /**
     * Exclui (desativa) um produto
     * 
     * @param int $id ID do produto
     */
    public function delete($id) {
        // Obter produto pelo ID
        $product = $this->productModel->getById($id);
        
        if (!$product) {
            set_flash_message('error', 'Produto não encontrado.');
            redirect('admin/produtos');
            return;
        }
        
        // Validar token CSRF (enviado via GET para simplificar)
        if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
            set_flash_message('error', 'Token de segurança inválido. Tente novamente.');
            redirect('admin/produtos');
            return;
        }
        
        // Desativar produto (soft delete)
        try {
            $success = $this->productModel->softDelete($id);
            
            if ($success) {
                set_flash_message('success', 'Produto excluído com sucesso!');
            } else {
                set_flash_message('error', 'Erro ao excluir produto. Tente novamente.');
            }
            
        } catch (\Exception $e) {
            // Registrar erro
            log_message('Erro ao excluir produto: ' . $e->getMessage(), 'error');
            
            // Mensagem de erro
            set_flash_message('error', 'Erro ao excluir produto: ' . $e->getMessage());
        }
        
        redirect('admin/produtos');
    }
    
    /**
     * Processa upload de imagens adicionais para o produto
     */
    public function uploadImage() {
        // Verificar se é uma requisição AJAX
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
            $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Requisição inválida']);
            exit;
        }
        
        // Verificar se o produto existe
        $productId = (int) $_POST['product_id'] ?? 0;
        $product = $this->productModel->getById($productId);
        
        if (!$product) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Produto não encontrado']);
            exit;
        }
        
        // Processar upload
        if (empty($_FILES['product_image']['name'])) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Nenhuma imagem enviada']);
            exit;
        }
        
        $uploadResult = $this->fileUpload->uploadImage(
            $_FILES['product_image'],
            'products/' . $productId,
            true, // Redimensionar imagem
            800,  // Largura máxima
            800   // Altura máxima
        );
        
        if (!$uploadResult['success']) {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => $uploadResult['error']]);
            exit;
        }
        
        // Salvar imagem no banco de dados
        try {
            $imageId = $this->productModel->addProductImage($productId, $uploadResult['path']);
            
            // Retornar sucesso
            echo json_encode([
                'success' => true,
                'message' => 'Imagem enviada com sucesso',
                'image' => [
                    'id' => $imageId,
                    'url' => BASE_URL . $uploadResult['path'],
                    'path' => $uploadResult['path']
                ]
            ]);
            
        } catch (\Exception $e) {
            // Em caso de erro, remover arquivo
            if (file_exists(PUBLIC_PATH . $uploadResult['path'])) {
                unlink(PUBLIC_PATH . $uploadResult['path']);
            }
            
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Erro ao salvar imagem: ' . $e->getMessage()]);
        }
        
        exit;
    }
    
    /**
     * Remove uma imagem adicional do produto
     */
    public function deleteImage() {
        // Verificar se é uma requisição AJAX
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
            $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Requisição inválida']);
            exit;
        }
        
        // Obter ID da imagem
        $imageId = (int) $_POST['image_id'] ?? 0;
        
        if (!$imageId) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'ID da imagem não fornecido']);
            exit;
        }
        
        // Obter imagem
        $image = $this->productModel->getProductImageById($imageId);
        
        if (!$image) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Imagem não encontrada']);
            exit;
        }
        
        // Verificar permissão (o produto pertence ao admin)
        $product = $this->productModel->getById($image['product_id']);
        
        if (!$product) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Permissão negada']);
            exit;
        }
        
        // Remover imagem
        try {
            // Deletar arquivo
            if (!empty($image['image']) && file_exists(PUBLIC_PATH . $image['image'])) {
                unlink(PUBLIC_PATH . $image['image']);
            }
            
            // Remover do banco de dados
            $success = $this->productModel->deleteProductImage($imageId);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Imagem removida com sucesso'
                ]);
            } else {
                header('HTTP/1.1 500 Internal Server Error');
                echo json_encode(['error' => 'Erro ao remover imagem do banco de dados']);
            }
            
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Erro ao remover imagem: ' . $e->getMessage()]);
        }
        
        exit;
    }
    
    /**
     * Validação do formulário de produto
     * 
     * @param array $data Dados do formulário
     * @param int|null $productId ID do produto (nulo para criação)
     * @return array Erros encontrados
     */
    private function validateProductForm($data, $productId = null) {
        $errors = [];
        
        // Nome
        if (empty($data['name'])) {
            $errors['name'] = 'O nome do produto é obrigatório.';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'O nome do produto deve ter no mínimo 3 caracteres.';
        }
        
        // Descrição
        if (empty($data['description'])) {
            $errors['description'] = 'A descrição do produto é obrigatória.';
        }
        
        // Preço
        if (empty($data['price'])) {
            $errors['price'] = 'O preço do produto é obrigatório.';
        } elseif (!is_numeric(str_replace(',', '.', $data['price']))) {
            $errors['price'] = 'O preço deve ser um valor numérico.';
        } elseif (floatval(str_replace(',', '.', $data['price'])) <= 0) {
            $errors['price'] = 'O preço deve ser maior que zero.';
        }
        
        // Preço promocional (se preenchido)
        if (!empty($data['sale_price'])) {
            if (!is_numeric(str_replace(',', '.', $data['sale_price']))) {
                $errors['sale_price'] = 'O preço promocional deve ser um valor numérico.';
            } elseif (floatval(str_replace(',', '.', $data['sale_price'])) <= 0) {
                $errors['sale_price'] = 'O preço promocional deve ser maior que zero.';
            } elseif (floatval(str_replace(',', '.', $data['sale_price'])) >= floatval(str_replace(',', '.', $data['price']))) {
                $errors['sale_price'] = 'O preço promocional deve ser menor que o preço regular.';
            }
        }
        
        // Categoria
        if (empty($data['category_id'])) {
            $errors['category_id'] = 'Selecione uma categoria.';
        }
        
        // SKU (se preenchido)
        if (!empty($data['sku'])) {
            // Verificar se já existe
            $existingSku = $this->productModel->getByField('sku', $data['sku']);
            if ($existingSku && ($productId === null || $existingSku['id'] != $productId)) {
                $errors['sku'] = 'Este SKU já está em uso por outro produto.';
            }
        }
        
        // Código de barras (se preenchido)
        if (!empty($data['barcode'])) {
            // Verificar se já existe
            $existingBarcode = $this->productModel->getByField('barcode', $data['barcode']);
            if ($existingBarcode && ($productId === null || $existingBarcode['id'] != $productId)) {
                $errors['barcode'] = 'Este código de barras já está em uso por outro produto.';
            }
        }
        
        // Concentração (específico para canabidiol)
        if (empty($data['concentration'])) {
            $errors['concentration'] = 'A concentração de canabidiol é obrigatória.';
        }
        
        // Forma farmacêutica
        if (empty($data['dosage_form'])) {
            $errors['dosage_form'] = 'A forma farmacêutica é obrigatória.';
        }
        
        // Verificar imagem no caso de criação
        if ($productId === null && empty($_FILES['image']['name'])) {
            $errors['image'] = 'A imagem principal do produto é obrigatória.';
        }
        
        // Verificar formato da imagem
        if (!empty($_FILES['image']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                $errors['image'] = 'Formato de imagem inválido. Use JPEG, PNG ou WebP.';
            }
        }
        
        return $errors;
    }
    
    /**
     * Gera um slug único a partir do nome do produto
     * 
     * @param string $name Nome do produto
     * @param int|null $excludeId ID do produto a ser excluído da verificação
     * @return string Slug único
     */
    private function generateSlug($name, $excludeId = null) {
        // Converter para minúsculas e remover acentos
        $slug = strtolower(preg_replace(
            ['/[áàãâä]/u', '/[éèêë]/u', '/[íìîï]/u', '/[óòõôö]/u', '/[úùûü]/u', '/ç/u'],
            ['a', 'e', 'i', 'o', 'u', 'c'],
            $name
        ));
        
        // Substituir espaços por hífens
        $slug = str_replace(' ', '-', $slug);
        
        // Remover caracteres especiais
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        
        // Substituir múltiplos hífens por um único
        $slug = preg_replace('/-+/', '-', $slug);
        
        // Remover hífens no início e no fim
        $slug = trim($slug, '-');
        
        // Verificar se o slug já existe
        $baseSlug = $slug;
        $suffix = 1;
        
        while (true) {
            $existingProduct = $this->productModel->getByField('slug', $slug);
            
            // Se não existir ou for o mesmo produto, retornar o slug
            if (!$existingProduct || ($excludeId !== null && $existingProduct['id'] == $excludeId)) {
                break;
            }
            
            // Adicionar sufixo e tentar novamente
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }
        
        return $slug;
    }
}